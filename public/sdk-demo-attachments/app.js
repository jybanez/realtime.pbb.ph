import { uiLoader } from "/vendor/helpers.pbb.ph/js/ui/ui.loader.js";
import {
    RealtimeSocketClient,
    parseRealtimeEnvelope,
    buildChatPublishPayload,
    normalizeChatMessageEvent,
    buildRoomJoinPayload,
    buildPresencePublishPayload,
    buildPresenceSubscribePayload,
    reducePresenceRosterEvent,
    listPresenceRosterItems,
    createThreadAttachment,
    validateDraftAttachments,
    transferAttachmentInChunks,
    reduceAttachmentChunkStore,
    resolveAttachmentUrlFromStore,
    inferAttachmentKind,
    shouldPreviewAttachmentFile,
    formatAttachmentFileSize,
    getAttachmentMimeType,
} from "/js/sdk/index.js";

uiLoader.setPreferBundles(true);

const state = {
    admission: null,
    client: null,
    messages: [],
    uploads: [],
    uploadHydration: null,
    receivedAttachments: {},
    roster: {},
    log: [],
    connectionStatus: "Idle",
    presenceStatus: "Offline",
    effectiveRoom: "chat.thread.sdk-demo-attachments-room",
    currentUserId: "",
    displayName: "",
    policy: {
        max_attachment_count: 0,
        max_attachment_bytes: 0,
        max_total_bytes_per_message: 0,
    },
    ui: {
        toast: null,
        emptyState: null,
        chatThread: null,
        chatComposer: null,
        chatUploadQueue: null,
    },
    thread: null,
    composer: null,
    uploadQueue: null,
    toast: null,
};

await bootstrap();

async function bootstrap() {
    await uiLoader.loadMany([
        "ui.toast",
        "ui.empty.state",
        "ui.chat.thread",
        "ui.chat.composer",
        "ui.chat.upload.queue",
    ]);

    state.ui.toast = await uiLoader.get("ui.toast");
    state.ui.emptyState = await uiLoader.get("ui.empty.state");
    state.ui.chatThread = await uiLoader.get("ui.chat.thread");
    state.ui.chatComposer = await uiLoader.get("ui.chat.composer");
    state.ui.chatUploadQueue = await uiLoader.get("ui.chat.upload.queue");

    state.toast = state.ui.toast({
        position: "bottom-right",
        defaultDuration: 3200,
    });

    mountThread();
    mountUploadQueue();
    mountComposer();
    renderPresence();
    renderLog();
    syncPolicyLabels();
    bindEvents();
    syncStatus();
}

function bindEvents() {
    document.getElementById("demoSettingsForm")?.addEventListener("submit", (event) => {
        event.preventDefault();
        void connect();
    });

    document.getElementById("disconnectButton")?.addEventListener("click", () => {
        disconnect("Disconnected by operator.");
    });
}

function mountThread() {
    const host = document.getElementById("chatThreadHost");
    if (!host || typeof state.ui.chatThread !== "function") {
        return;
    }

    state.thread = state.ui.chatThread(host, { messages: state.messages }, {
        className: "sdk-demo-thread",
        emptyTitle: "No messages yet",
        emptyText: "Connect, attach files, and send a message.",
        onAttachmentOpen(message, attachment) {
            const url = resolveAttachmentUrl(attachment, "url");
            if (url) {
                window.open(url, "_blank", "noopener");
            } else {
                toast("Attachment is still being reassembled.", "warning");
            }
        },
        onAttachmentDownload(_message, attachment) {
            const url = resolveAttachmentUrl(attachment, "url");
            if (!url) {
                toast("Attachment is not ready to download yet.", "warning");
                return;
            }
            const anchor = document.createElement("a");
            anchor.href = url;
            anchor.download = String(attachment?.name || "attachment");
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
        },
    });
}

function mountUploadQueue() {
    const host = document.getElementById("uploadQueueHost");
    if (!host || typeof state.ui.chatUploadQueue !== "function") {
        return;
    }

    state.uploadQueue = state.ui.chatUploadQueue(host, { items: state.uploads }, {
        emptyHidden: true,
        onRemove(item) {
            removeUploadItem(String(item?.id || ""));
        },
    });
}

function mountComposer() {
    const host = document.getElementById("chatComposerHost");
    if (!host || typeof state.ui.chatComposer !== "function") {
        return;
    }

    state.composer = state.ui.chatComposer(host, { value: "" }, {
        helperText: "Connect first. Attachments are chunked through the sandbox transport event for demo purposes.",
        disabled: true,
        showAttachmentButton: true,
        multiple: true,
        onSend(payload) {
            void sendChatMessage(payload);
        },
        onFilesSelected(files) {
            void addDraftFiles(files);
        },
    });
}

async function connect() {
    disconnect();

    const payload = {
        client_code: valueOf("clientCodeInput"),
        project_code: valueOf("projectCodeInput"),
        display_name: valueOf("displayNameInput"),
        user_id: valueOf("userIdInput") || `demo_${Math.random().toString(36).slice(2, 10)}`,
        room: valueOf("roomInput"),
    };

    if (!payload.client_code || !payload.project_code || !payload.display_name || !payload.room) {
        toast("Complete client, project, display name, and room.", "warning");
        return;
    }

    setConnectionStatus("Issuing");

    const response = await fetch("./admission.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
        body: JSON.stringify(payload),
    });

    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.status) {
        setConnectionStatus("Error");
        toast(data?.message || "Unable to issue demo admission.", "error");
        return;
    }

    state.admission = data.data;
    state.currentUserId = String(data.data?.session?.user_id || payload.user_id || "").trim();
    state.displayName = payload.display_name;
    state.effectiveRoom = String(data.data?.room || "").trim() || `chat.thread.${payload.room}`;
    state.policy = {
        max_attachment_count: Number(data.data?.session?.attachment_policy?.max_attachment_count || 0),
        max_attachment_bytes: Number(data.data?.session?.attachment_policy?.max_attachment_bytes || 0),
        max_total_bytes_per_message: Number(data.data?.session?.attachment_policy?.max_total_bytes_per_message || 0),
    };
    syncPolicyLabels();
    syncStatus();

    state.client = new RealtimeSocketClient({
        websocketUrl: String(data.data.websocket_url || ""),
        token: String(data.data.token || ""),
        requestPrefix: "sdkdemoattach",
        onOpen() {
            setConnectionStatus("Connected");
            logEvent("socket.open", { websocket_url: data.data.websocket_url });
        },
        onMessage(raw) {
            void handleSocketMessage(raw);
        },
        onError() {
            setConnectionStatus("Error");
            logEvent("socket.error", { message: "WebSocket error event received." });
            toast("Realtime websocket error.", "error");
        },
        onClose() {
            setConnectionStatus("Idle");
            setPresenceStatus("Offline");
            logEvent("socket.close", { message: "Socket closed." });
            syncComposer();
        },
    });

    state.client.connect();
    syncComposer();
}

function disconnect(reason = "") {
    if (reason) {
        logEvent("session.disconnect", { reason });
    }

    state.client?.close?.();
    state.client = null;
    state.admission = null;
    state.roster = {};
    state.receivedAttachments = {};
    clearUploads();
    setConnectionStatus("Idle");
    setPresenceStatus("Offline");
    syncComposer();
    renderPresence();
}

async function handleSocketMessage(raw) {
    let envelope;

    try {
        envelope = parseRealtimeEnvelope(raw);
    } catch {
        logEvent("socket.raw", { raw: String(raw || "") });
        return;
    }

    logEvent(`${envelope.phase || "unknown"}.${envelope.type || "message"}`, envelope.payload || {});

    if (envelope.phase === "ack" && envelope.type === "session.auth.request") {
        setConnectionStatus("Authenticated");
        sendRequest("room.join.request", state.effectiveRoom, buildRoomJoinPayload());
        return;
    }

    if (envelope.phase === "ack" && envelope.type === "room.join.request") {
        setConnectionStatus("Joined");
        sendRequest("presence.subscribe", state.effectiveRoom, buildPresenceSubscribePayload(state.effectiveRoom));
        sendRequest("presence.publish", state.effectiveRoom, buildPresencePublishPayload(state.effectiveRoom, "online", `${state.displayName} online`));
        syncComposer();
        toast(`Joined ${state.effectiveRoom}.`, "success");
        return;
    }

    if (envelope.phase === "ack" && envelope.type === "presence.publish") {
        setPresenceStatus("Online");
        return;
    }

    if (envelope.phase === "event" && envelope.type === "presence.state.event") {
        state.roster = reducePresenceRosterEvent(state.roster, envelope.payload || {});
        renderPresence();
        return;
    }

    if (envelope.phase === "event" && envelope.type === "sandbox.attachment.chunk.event") {
        state.receivedAttachments = reduceAttachmentChunkStore(state.receivedAttachments, envelope.payload || {});
        hydrateThreadAttachments(String(envelope.payload?.transfer_id || "").trim());
        return;
    }

    if (envelope.phase === "event" && envelope.type === "chat.message.event") {
        const message = normalizeChatMessageEvent(envelope.payload || {}, {
            currentUserId: state.currentUserId,
            fallbackSenderName: "Realtime user",
            resolveAttachmentUrl: (attachment, field) => resolveAttachmentUrl(attachment, field),
        });

        state.messages.push(message);
        state.thread?.setMessages?.(state.messages);
    }
}

async function sendChatMessage(payload) {
    if (!state.client?.isOpen?.() || state.connectionStatus !== "Joined") {
        toast("Connect and join the room first.", "warning");
        return;
    }

    if (state.uploadHydration) {
        await state.uploadHydration;
    }

    const text = String(payload?.text || "").trim();
    if (!text) {
        toast("Attachment messages still require text in the current transport contract.", "warning");
        return;
    }

    const draftAttachments = state.uploads.map(createThreadAttachment);
    const transportAttachments = [];

    for (const item of state.uploads) {
        transportAttachments.push(await transferAttachment(item));
    }

    const requestId = sendRequest("chat.message.publish", state.effectiveRoom, buildChatPublishPayload(text, transportAttachments));
    if (!requestId) {
        toast("Unable to publish chat message.", "error");
        return;
    }

    state.messages.push({
        id: `local_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`,
        direction: "outgoing",
        senderName: state.displayName,
        text,
        timestamp: new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }),
        state: "sending",
        attachments: draftAttachments,
        meta: {
            senderUserId: state.currentUserId,
        },
    });
    state.thread?.setMessages?.(state.messages);
    state.composer?.clear?.();
    state.composer?.focus?.();
    clearUploads();
}

async function addDraftFiles(files) {
    const { accepted, rejected } = validateDraftAttachments({
        existingItems: state.uploads,
        files,
        policy: state.policy,
    });

    rejected.forEach((message) => toast(message, "warning"));
    if (!accepted.length) {
        return;
    }

    const hydration = Promise.all(Array.from(accepted).map(async (file, index) => {
        const kind = inferAttachmentKind(file);
        const previewCapable = shouldPreviewAttachmentFile(file);
        const previewUrl = previewCapable ? URL.createObjectURL(file) : "";
        const transportUrl = await readFileAsDataUrl(file);

        return {
            id: `${Date.now()}-${index}-${String(file.name || "file").replace(/\s+/g, "-")}`,
            transferId: `xfer_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`,
            kind,
            name: String(file.name || "attachment"),
            sizeLabel: formatAttachmentFileSize(file.size),
            byteSize: Number(file.size) || 0,
            status: "queued",
            progress: null,
            progressLabel: "",
            previewUrl,
            transportUrl,
            mimeType: String(file.type || getAttachmentMimeType(kind)),
            file,
        };
    }));

    state.uploadHydration = hydration;
    const nextItems = await hydration;
    if (state.uploadHydration === hydration) {
        state.uploadHydration = null;
    }

    state.uploads = state.uploads.concat(nextItems);
    state.uploadQueue?.setItems?.(state.uploads);
}

async function transferAttachment(item) {
    const attachment = await transferAttachmentInChunks(item, {
        onChunk(chunkPayload) {
            sendRequest("sandbox.attachment.chunk.publish", state.effectiveRoom, chunkPayload);
        },
        onProgress(progress, progressLabel) {
            updateUploadItem(item.id, {
                status: progress >= 100 ? "uploaded" : "uploading",
                progress,
                progressLabel,
            });
        },
    });

    updateUploadItem(item.id, {
        status: "uploaded",
        progress: 100,
        progressLabel: "Delivered to message payload",
        mimeType: attachment.mime_type || item.mimeType || "",
    });

    return attachment;
}

function updateUploadItem(itemId, patch = {}) {
    let changed = false;
    state.uploads = state.uploads.map((item) => {
        if (item.id !== itemId) {
            return item;
        }
        changed = true;
        return {
            ...item,
            ...patch,
        };
    });

    if (changed) {
        state.uploadQueue?.setItems?.(state.uploads);
    }
}

function removeUploadItem(itemId) {
    const removed = state.uploads.find((item) => item.id === itemId) || null;
    if (removed?.previewUrl && removed.previewUrl.startsWith("blob:")) {
        URL.revokeObjectURL(removed.previewUrl);
    }
    state.uploads = state.uploads.filter((item) => item.id !== itemId);
    state.uploadQueue?.setItems?.(state.uploads);
}

function clearUploads() {
    state.uploads.forEach((item) => {
        if (String(item?.previewUrl || "").startsWith("blob:")) {
            URL.revokeObjectURL(item.previewUrl);
        }
    });
    state.uploads = [];
    state.uploadQueue?.setItems?.(state.uploads);
}

function hydrateThreadAttachments(transferId) {
    const current = transferId ? state.receivedAttachments?.[transferId] : null;
    if (!current?.completed) {
        return;
    }

    let updated = false;
    state.messages = state.messages.map((message) => {
        if (!Array.isArray(message.attachments)) {
            return message;
        }

        const attachments = message.attachments.map((attachment) => {
            if (String(attachment?.transfer_id || "") !== transferId) {
                return attachment;
            }

            updated = true;
            return {
                ...attachment,
                url: current.url,
                previewUrl: current.url,
                posterUrl: current.url,
            };
        });

        return updated ? { ...message, attachments } : message;
    });

    if (updated) {
        state.thread?.setMessages?.(state.messages);
    }
}

function resolveAttachmentUrl(attachment, field = "url") {
    return resolveAttachmentUrlFromStore(state.receivedAttachments, attachment, field);
}

function sendRequest(type, room, payload) {
    return state.client?.sendRequest?.(type, room, payload) || null;
}

function renderPresence() {
    const host = document.getElementById("presenceHost");
    if (!host) {
        return;
    }

    const items = listPresenceRosterItems(state.roster);
    if (!items.length) {
        renderEmpty(host, "No peers present", "Presence entries will appear here once peers publish state.");
        return;
    }

    host.innerHTML = `
        <div class="demo-roster-list">
            ${items.map((item) => `
                <article class="demo-roster-item">
                    <strong>${escapeHtml(item.displayName || item.userId || item.sessionId || "Realtime peer")}</strong>
                    <div class="demo-muted">${escapeHtml(item.userId || "Unknown identity")}</div>
                    <div class="demo-muted">${escapeHtml(item.state || "online")}</div>
                </article>
            `).join("")}
        </div>
    `;
}

function renderLog() {
    const host = document.getElementById("eventLogHost");
    if (!host) {
        return;
    }

    if (!state.log.length) {
        renderEmpty(host, "No events yet", "Transport events will appear here after the websocket connects.");
        return;
    }

    host.innerHTML = `
        <div class="demo-log-list">
            ${state.log.slice(0, 40).map((entry) => `
                <article class="demo-log-item">
                    <strong>${escapeHtml(entry.type)}</strong>
                    <div class="demo-muted">${escapeHtml(entry.at)}</div>
                    <pre class="demo-log-json">${escapeHtml(JSON.stringify(entry.payload, null, 2))}</pre>
                </article>
            `).join("")}
        </div>
    `;
}

function renderEmpty(host, title, description) {
    if (typeof state.ui.emptyState !== "function") {
        host.innerHTML = `<div class="demo-muted">${escapeHtml(description)}</div>`;
        return;
    }

    state.ui.emptyState(host, { title, description }, { chrome: false });
}

function syncPolicyLabels() {
    setText("attachmentCountLimit", state.policy.max_attachment_count > 0 ? `${state.policy.max_attachment_count} file(s)` : "Open");
    setText("attachmentBytesLimit", state.policy.max_attachment_bytes > 0 ? formatAttachmentFileSize(state.policy.max_attachment_bytes) : "Open");
    setText("attachmentTotalBytesLimit", state.policy.max_total_bytes_per_message > 0 ? formatAttachmentFileSize(state.policy.max_total_bytes_per_message) : "Open");
}

function logEvent(type, payload) {
    state.log.unshift({
        type,
        payload,
        at: new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", second: "2-digit" }),
    });
    renderLog();
}

function syncStatus() {
    setText("connectionStatus", state.connectionStatus);
    setText("presenceStatus", state.presenceStatus);
    setText("effectiveRoom", state.effectiveRoom);
    document.getElementById("disconnectButton")?.toggleAttribute("disabled", !state.client);
}

function syncComposer() {
    const joined = state.connectionStatus === "Joined";
    state.composer?.update?.({}, {
        disabled: !joined,
        helperText: joined
            ? `Connected to ${state.effectiveRoom}. Messages may include chunked attachments.`
            : "Connect first. Attachments are chunked through the sandbox transport event for demo purposes.",
    });
}

function setConnectionStatus(value) {
    state.connectionStatus = value;
    syncStatus();
}

function setPresenceStatus(value) {
    state.presenceStatus = value;
    syncStatus();
}

function valueOf(id) {
    return String(document.getElementById(id)?.value || "").trim();
}

function setText(id, value) {
    const node = document.getElementById(id);
    if (node) {
        node.textContent = String(value || "");
    }
}

function toast(message, type = "info") {
    state.toast?.show?.(String(message), {
        title: "Attachment Demo",
        type,
    });
}

function readFileAsDataUrl(file) {
    return new Promise((resolve) => {
        try {
            const reader = new FileReader();
            reader.onload = () => resolve(typeof reader.result === "string" ? reader.result : "");
            reader.onerror = () => resolve("");
            reader.readAsDataURL(file);
        } catch {
            resolve("");
        }
    });
}

function escapeHtml(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/\"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
