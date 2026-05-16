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
} from "/js/sdk/index.js";

uiLoader.setPreferBundles(true);

const state = {
    admission: null,
    client: null,
    messages: [],
    roster: {},
    log: [],
    connectionStatus: "Idle",
    presenceStatus: "Offline",
    effectiveRoom: "chat.thread.sdk-demo-room",
    currentUserId: "",
    displayName: "",
    ui: {
        toast: null,
        emptyState: null,
        chatThread: null,
        chatComposer: null,
    },
};

await bootstrap();

async function bootstrap() {
    await uiLoader.loadMany([
        "ui.toast",
        "ui.empty.state",
        "ui.chat.thread",
        "ui.chat.composer",
    ]);

    state.ui.toast = await uiLoader.get("ui.toast");
    state.ui.emptyState = await uiLoader.get("ui.empty.state");
    state.ui.chatThread = await uiLoader.get("ui.chat.thread");
    state.ui.chatComposer = await uiLoader.get("ui.chat.composer");

    state.toast = state.ui.toast({
        position: "bottom-right",
        defaultDuration: 2800,
    });

    mountThread();
    mountComposer();
    renderPresence();
    renderLog();
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
        emptyText: "Connect, join the room, and send a chat message.",
    });
}

function mountComposer() {
    const host = document.getElementById("chatComposerHost");
    if (!host || typeof state.ui.chatComposer !== "function") {
        return;
    }

    state.composer = state.ui.chatComposer(host, { value: "" }, {
        helperText: "Connect first. Enter sends, Shift+Enter adds a new line.",
        disabled: true,
        showAttachmentButton: false,
        onSend(payload) {
            void sendChatMessage(payload);
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
    syncStatus();

    state.client = new RealtimeSocketClient({
        websocketUrl: String(data.data.websocket_url || ""),
        token: String(data.data.token || ""),
        requestPrefix: "sdkdemo",
        onOpen() {
            setConnectionStatus("Connected");
            logEvent("socket.open", { websocket_url: data.data.websocket_url });
        },
        onMessage(raw) {
            handleSocketMessage(raw);
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
    setConnectionStatus("Idle");
    setPresenceStatus("Offline");
    syncComposer();
    renderPresence();
}

function handleSocketMessage(raw) {
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

    if (envelope.phase === "event" && envelope.type === "chat.message.event") {
        const message = normalizeChatMessageEvent(envelope.payload || {}, {
            currentUserId: state.currentUserId,
            fallbackSenderName: "Realtime user",
        });

        state.messages.push({
            id: message.id,
            direction: message.direction,
            senderName: message.senderName,
            text: message.text,
            timestamp: message.timestamp,
            state: message.state,
            attachments: message.attachments,
            meta: message.meta,
        });
        state.thread?.setMessages?.(state.messages);
    }
}

async function sendChatMessage(payload) {
    if (!state.client?.isOpen?.() || state.connectionStatus !== "Joined") {
        toast("Connect and join the room first.", "warning");
        return;
    }

    const text = String(payload?.text || "").trim();
    if (!text) {
        return;
    }

    const requestId = sendRequest("chat.message.publish", state.effectiveRoom, buildChatPublishPayload(text));
    if (!requestId) {
        toast("Unable to publish chat message.", "error");
        return;
    }

    state.composer?.clear?.();
    state.composer?.focus?.();
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
            ${state.log.slice(0, 30).map((entry) => `
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
            ? `Connected to ${state.effectiveRoom}`
            : "Connect first. Enter sends, Shift+Enter adds a new line.",
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
        title: "SDK Demo",
        type,
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
