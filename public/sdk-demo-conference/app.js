import { uiLoader } from "/vendor/helpers.pbb.ph/js/ui/ui.loader.js";
import {
    RealtimeSocketClient,
    parseRealtimeEnvelope,
    buildRoomJoinPayload,
    buildPresencePublishPayload,
    buildPresenceSubscribePayload,
    reducePresenceRosterEvent,
    listPresenceRosterItems,
    buildCallSignalPayload,
    ensureConferencePeerConnection,
    ensureConferenceRemoteStream,
    parseRealtimeSignalJson,
    normalizeRealtimeSdp,
    bindMediaElementStream,
    getMeshConferenceWarning,
    isRealtimeCallActive,
} from "/js/sdk/index.js";

uiLoader.setPreferBundles(true);

const state = {
    admission: null,
    client: null,
    roster: {},
    log: [],
    connectionStatus: "idle",
    presenceStatus: "offline",
    callStatus: "idle",
    effectiveRoom: "chat.thread.sdk-demo-conference-room",
    callRoom: "call.session.sdk-demo-conference-room",
    currentUserId: "",
    displayName: "",
    roomJoined: false,
    callRoomJoined: false,
    localStream: null,
    callMode: "",
    incomingOffers: {},
    pendingIceCandidatesByUser: {},
    peerConnections: {},
    remoteStreams: {},
    participantDisplayNames: {},
    isMicEnabled: true,
    isCameraEnabled: false,
    ui: {
        toast: null,
        emptyState: null,
    },
    toast: null,
};

await bootstrap();

async function bootstrap() {
    await uiLoader.loadMany([
        "ui.toast",
        "ui.empty.state",
    ]);

    state.ui.toast = await uiLoader.get("ui.toast");
    state.ui.emptyState = await uiLoader.get("ui.empty.state");

    state.toast = state.ui.toast({
        position: "bottom-right",
        defaultDuration: 3200,
    });

    bindEvents();
    renderPresence();
    renderLog();
    renderMedia();
    renderConferenceWarning();
    syncStatus();
    syncControls();
}

function bindEvents() {
    document.getElementById("demoSettingsForm")?.addEventListener("submit", (event) => {
        event.preventDefault();
        void connect();
    });

    document.getElementById("disconnectButton")?.addEventListener("click", () => {
        disconnect("Disconnected by operator.");
    });

    document.getElementById("audioCallButton")?.addEventListener("click", () => {
        void startCall("audio");
    });

    document.getElementById("videoCallButton")?.addEventListener("click", () => {
        void startCall("video");
    });

    document.getElementById("answerCallButton")?.addEventListener("click", () => {
        void answerCall();
    });

    document.getElementById("endCallButton")?.addEventListener("click", () => {
        void endCall(true);
    });

    document.getElementById("toggleMicButton")?.addEventListener("click", () => {
        toggleMic();
    });

    document.getElementById("toggleCameraButton")?.addEventListener("click", () => {
        void toggleCamera();
    });
}

async function connect() {
    disconnect();

    const payload = {
        client_code: valueOf("clientCodeInput"),
        project_code: valueOf("projectCodeInput"),
        display_name: valueOf("displayNameInput"),
        user_id: valueOf("userIdInput") || `conf_${Math.random().toString(36).slice(2, 10)}`,
        room: valueOf("roomInput"),
    };

    if (!payload.client_code || !payload.project_code || !payload.display_name || !payload.room) {
        toast("Complete client, project, display name, and room.", "warning");
        return;
    }

    setConnectionStatus("issuing");

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
        setConnectionStatus("error");
        toast(data?.message || "Unable to issue conference admission.", "error");
        return;
    }

    state.admission = data.data;
    state.currentUserId = String(data.data?.session?.user_id || payload.user_id || "").trim();
    state.displayName = payload.display_name;
    state.effectiveRoom = String(data.data?.room || "").trim() || `chat.thread.${payload.room}`;
    state.callRoom = String(data.data?.call_room || "").trim() || `call.session.${payload.room}`;
    state.participantDisplayNames[state.currentUserId] = state.displayName;
    syncStatus();

    state.client = new RealtimeSocketClient({
        websocketUrl: String(data.data.websocket_url || ""),
        token: String(data.data.token || ""),
        requestPrefix: "sdkdemoconf",
        onOpen() {
            setConnectionStatus("connected");
            logEvent("socket.open", { websocket_url: data.data.websocket_url });
        },
        onMessage(raw) {
            void handleSocketMessage(raw);
        },
        onError() {
            setConnectionStatus("error");
            logEvent("socket.error", { message: "WebSocket error event received." });
            toast("Realtime websocket error.", "error");
        },
        onClose() {
            setConnectionStatus("idle");
            setPresenceStatus("offline");
            state.roomJoined = false;
            state.callRoomJoined = false;
            logEvent("socket.close", { message: "Socket closed." });
            syncStatus();
            syncControls();
        },
    });

    state.client.connect();
}

function disconnect(reason = "") {
    if (reason) {
        logEvent("session.disconnect", { reason });
    }

    state.client?.close?.();
    state.client = null;
    stopAllConferenceMedia();
    Object.assign(state, {
        admission: null,
        roster: {},
        connectionStatus: "idle",
        presenceStatus: "offline",
        callStatus: "idle",
        roomJoined: false,
        callRoomJoined: false,
        callMode: "",
        incomingOffers: {},
        pendingIceCandidatesByUser: {},
        peerConnections: {},
        remoteStreams: {},
        participantDisplayNames: {},
        localStream: null,
        isMicEnabled: true,
        isCameraEnabled: false,
    });
    renderPresence();
    renderMedia();
    renderConferenceWarning();
    syncStatus();
    syncControls();
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
        sendRequest("room.join.request", state.effectiveRoom, buildRoomJoinPayload());
        sendRequest("room.join.request", state.callRoom, buildRoomJoinPayload());
        return;
    }

    if (envelope.phase === "ack" && envelope.type === "room.join.request") {
        const joinedRoom = String(envelope.room || envelope.payload?.room || "").trim();
        if (joinedRoom === state.effectiveRoom) {
            state.roomJoined = true;
            sendRequest("presence.subscribe", state.effectiveRoom, buildPresenceSubscribePayload(state.effectiveRoom));
            sendRequest("presence.publish", state.effectiveRoom, buildPresencePublishPayload(state.effectiveRoom, "online", `${state.displayName} online`));
        }
        if (joinedRoom === state.callRoom) {
            state.callRoomJoined = true;
        }
        syncStatus();
        syncControls();
        return;
    }

    if (envelope.phase === "ack" && envelope.type === "presence.publish") {
        setPresenceStatus("online");
        return;
    }

    if (envelope.phase === "event" && envelope.type === "presence.state.event") {
        state.roster = reducePresenceRosterEvent(state.roster, envelope.payload || {});
        renderPresence();
        renderConferenceWarning();
        if (isRealtimeCallActive(state.callStatus) && state.callRoomJoined) {
            await ensureMeshOffersToParticipants(state.callMode || "audio");
        }
        return;
    }

    if (envelope.phase === "event" && envelope.type === "call.signal.event") {
        await handleCallSignalEvent(envelope.payload || {});
    }
}

async function startCall(mode) {
    if (!state.client?.isOpen?.() || !state.roomJoined || !state.callRoomJoined) {
        toast("Connect and join both rooms first.", "warning");
        return;
    }

    const participants = getRosterParticipants();
    if (!participants.length) {
        toast("No other peers are present in the room yet.", "warning");
        return;
    }

    await ensureLocalMedia(mode);
    state.callMode = mode;
    state.callStatus = "outgoing";
    syncStatus();
    syncControls();
    renderMedia();

    participants.forEach((participant) => {
        sendCallSignal("ring", {
            targetUserId: participant.userId,
            meta: {
                mode,
                display_name: state.displayName,
            },
        });
    });

    await ensureMeshOffersToParticipants(mode);
    refreshAggregateCallState();
}

async function answerCall() {
    const offers = Object.values(state.incomingOffers || {}).filter(Boolean);
    if (!offers.length) {
        toast("No incoming offer is waiting.", "warning");
        return;
    }

    const mode = offers.some((offer) => String(offer.mode || "audio") === "video") ? "video" : "audio";
    await ensureLocalMedia(mode);
    state.callMode = mode;
    state.callStatus = "connecting";
    syncStatus();
    syncControls();
    renderMedia();

    for (const offer of offers) {
        await answerOffer(offer.userId, String(offer.mode || mode), String(offer.sdp || ""));
    }

    refreshAggregateCallState();
    await ensureMeshOffersToParticipants(state.callMode || mode);
}

async function endCall(shouldPropagate = true) {
    const remoteUserIds = Object.keys(state.peerConnections || {});
    if (shouldPropagate) {
        remoteUserIds.forEach((remoteUserId) => {
            sendCallSignal("hangup", {
                targetUserId: remoteUserId,
                meta: { mode: state.callMode || "audio" },
            });
        });
    }

    stopAllConferenceMedia();
    state.incomingOffers = {};
    state.pendingIceCandidatesByUser = {};
    state.peerConnections = {};
    state.remoteStreams = {};
    state.callMode = "";
    state.callStatus = "idle";
    state.isMicEnabled = true;
    state.isCameraEnabled = false;
    renderMedia();
    syncStatus();
    syncControls();
}

async function handleCallSignalEvent(payload) {
    const sender = payload?.sender || {};
    const senderUserId = String(sender.user_id || "").trim();
    const currentUserId = String(state.currentUserId || "").trim();
    const targetUserId = String(payload?.target_user_id || "").trim();
    if (senderUserId && senderUserId === currentUserId) {
        return;
    }
    if (targetUserId && currentUserId && targetUserId !== currentUserId) {
        return;
    }

    const signalType = String(payload?.signal_type || "").trim();
    const meta = parseRealtimeSignalJson(payload?.meta_json);
    const candidate = parseRealtimeSignalJson(payload?.candidate_json);
    const mode = String(meta?.mode || state.callMode || "audio");
    const senderDisplayName = String(sender.display_name || meta?.display_name || senderUserId || "Caller").trim();
    if (senderUserId) {
        state.participantDisplayNames[senderUserId] = senderDisplayName;
    }

    if (signalType === "ring") {
        if (!isRealtimeCallActive(state.callStatus)) {
            state.callStatus = "incoming";
            state.callMode = mode;
            syncStatus();
            syncControls();
            toast(`${senderDisplayName} is calling.`, "info");
        }
        return;
    }

    if (signalType === "offer") {
        if (["connecting", "connected"].includes(state.callStatus)) {
            await ensureLocalMedia(mode);
            await answerOffer(senderUserId, mode, String(payload?.sdp || ""));
            refreshAggregateCallState();
            await ensureMeshOffersToParticipants(state.callMode || mode);
            return;
        }

        state.incomingOffers[senderUserId] = {
            userId: senderUserId,
            displayName: senderDisplayName,
            mode,
            sdp: String(payload?.sdp || ""),
        };
        state.callStatus = "incoming";
        state.callMode = mode;
        syncStatus();
        syncControls();
        return;
    }

    if (signalType === "answer") {
        const pc = ensurePeerConnection(senderUserId);
        await pc.setRemoteDescription(new RTCSessionDescription({
            type: "answer",
            sdp: normalizeRealtimeSdp(payload?.sdp),
        }));
        await flushPendingIceCandidates(senderUserId);
        refreshAggregateCallState();
        await ensureMeshOffersToParticipants(state.callMode || mode);
        return;
    }

    if (signalType === "ice-candidate" && candidate) {
        const pc = state.peerConnections?.[senderUserId];
        if (pc?.remoteDescription) {
            try {
                await pc.addIceCandidate(new RTCIceCandidate(candidate));
            } catch (error) {
                logEvent("rtc.ice.error", {
                    remote_user_id: senderUserId,
                    message: error instanceof Error ? error.message : "Failed to add ICE candidate.",
                });
            }
        } else {
            if (!Array.isArray(state.pendingIceCandidatesByUser[senderUserId])) {
                state.pendingIceCandidatesByUser[senderUserId] = [];
            }
            state.pendingIceCandidatesByUser[senderUserId].push(candidate);
        }
        return;
    }

    if (signalType === "hangup") {
        cleanupRemotePeer(senderUserId);
        renderMedia();
        refreshAggregateCallState();
        maybeAutoEndSoloCall();
        syncStatus();
        syncControls();
    }
}

function ensurePeerConnection(remoteUserId) {
    return ensureConferencePeerConnection(state.peerConnections, remoteUserId, () => {
        const pc = new RTCPeerConnection({
            iceServers: [
                { urls: "stun:stun.l.google.com:19302" },
            ],
        });

        const remoteStream = ensureConferenceRemoteStream(state.remoteStreams, remoteUserId, () => new MediaStream());

        if (state.localStream) {
            state.localStream.getTracks().forEach((track) => {
                pc.addTrack(track, state.localStream);
            });
        }

        pc.onicecandidate = (event) => {
            if (!event.candidate) {
                return;
            }

            sendCallSignal("ice-candidate", {
                targetUserId: remoteUserId,
                candidate: event.candidate.toJSON(),
                meta: { mode: state.callMode || "audio" },
            });
        };

        pc.ontrack = (event) => {
            const incomingTracks = event.streams[0]?.getTracks?.() || [event.track].filter(Boolean);
            incomingTracks.forEach((track) => {
                if (!remoteStream.getTracks().some((candidate) => candidate.id === track.id)) {
                    remoteStream.addTrack(track);
                }
            });
            renderMedia();
        };

        pc.onconnectionstatechange = () => {
            if (["failed", "closed", "disconnected"].includes(String(pc.connectionState || ""))) {
                cleanupRemotePeer(remoteUserId);
                renderMedia();
            }
            refreshAggregateCallState();
            maybeAutoEndSoloCall();
            syncStatus();
            syncControls();
        };

        return pc;
    });
}

async function flushPendingIceCandidates(remoteUserId) {
    const pc = state.peerConnections?.[remoteUserId];
    if (!pc?.remoteDescription) {
        return;
    }

    const pending = Array.isArray(state.pendingIceCandidatesByUser?.[remoteUserId])
        ? state.pendingIceCandidatesByUser[remoteUserId].splice(0)
        : [];

    for (const candidate of pending) {
        try {
            await pc.addIceCandidate(new RTCIceCandidate(candidate));
        } catch (error) {
            logEvent("rtc.ice.error", {
                remote_user_id: remoteUserId,
                message: error instanceof Error ? error.message : "Failed to add queued ICE candidate.",
            });
        }
    }
}

async function negotiateOfferToRemote(remoteUserId, modeOverride = "") {
    if (!remoteUserId || !state.callRoomJoined || !state.localStream) {
        return;
    }

    const mode = String(modeOverride || state.callMode || "audio");
    const pc = ensurePeerConnection(remoteUserId);
    const offer = await pc.createOffer({
        offerToReceiveAudio: true,
        offerToReceiveVideo: mode === "video",
    });
    await pc.setLocalDescription(offer);
    sendCallSignal("offer", {
        targetUserId: remoteUserId,
        sdp: offer.sdp || "",
        meta: {
            mode,
            display_name: state.displayName,
        },
    });
}

async function ensureMeshOffersToParticipants(modeOverride = "") {
    const participants = getRosterParticipants();
    for (const participant of participants) {
        if (!shouldOfferToRemote(participant.userId)) {
            continue;
        }
        if (state.peerConnections?.[participant.userId]) {
            continue;
        }
        if (state.incomingOffers?.[participant.userId]) {
            continue;
        }
        await negotiateOfferToRemote(participant.userId, modeOverride);
    }
}

async function answerOffer(remoteUserId, mode, sdp) {
    const pc = ensurePeerConnection(remoteUserId);
    await pc.setRemoteDescription(new RTCSessionDescription({
        type: "offer",
        sdp: normalizeRealtimeSdp(sdp),
    }));
    await flushPendingIceCandidates(remoteUserId);
    const answer = await pc.createAnswer();
    await pc.setLocalDescription(answer);
    delete state.incomingOffers[remoteUserId];
    sendCallSignal("answer", {
        targetUserId: remoteUserId,
        sdp: answer.sdp || "",
        meta: {
            mode,
            display_name: state.displayName,
        },
    });
}

function sendCallSignal(signalType, options = {}) {
    if (!state.client?.isOpen?.() || !state.callRoomJoined) {
        return null;
    }

    return sendRequest("call.signal.publish", state.callRoom, buildCallSignalPayload(signalType, options));
}

async function ensureLocalMedia(mode) {
    if (state.localStream) {
        if (mode === "video" && !state.localStream.getVideoTracks().length) {
            await ensureVideoTrack();
        }
        return state.localStream;
    }

    const wantsVideo = mode === "video";
    const stream = await navigator.mediaDevices.getUserMedia({
        audio: true,
        video: wantsVideo,
    });

    state.localStream = stream;
    state.isMicEnabled = true;
    state.isCameraEnabled = wantsVideo;
    renderMedia();
    syncControls();
    return stream;
}

async function ensureVideoTrack() {
    const existingTrack = state.localStream?.getVideoTracks?.()[0] || null;
    if (existingTrack) {
        existingTrack.enabled = true;
        state.isCameraEnabled = true;
        renderMedia();
        syncControls();
        return existingTrack;
    }

    const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
    const track = stream.getVideoTracks()[0];
    if (!track) {
        throw new Error("Unable to acquire a camera track.");
    }

    if (!state.localStream) {
        state.localStream = new MediaStream();
    }

    state.localStream.addTrack(track);
    state.isCameraEnabled = true;
    renderMedia();
    syncControls();
    return track;
}

function toggleMic() {
    state.localStream?.getAudioTracks?.().forEach((track) => {
        track.enabled = !track.enabled;
        state.isMicEnabled = track.enabled;
    });
    syncControls();
}

async function toggleCamera() {
    if (!isRealtimeCallActive(state.callStatus)) {
        return;
    }

    if (!state.isCameraEnabled) {
        await ensureVideoTrack();
        state.callMode = "video";
        for (const remoteUserId of Object.keys(state.peerConnections || {})) {
            const pc = ensurePeerConnection(remoteUserId);
            const track = state.localStream?.getVideoTracks?.()[0] || null;
            if (track) {
                const sender = pc.getSenders().find((candidate) => candidate.track?.kind === "video");
                if (sender) {
                    await sender.replaceTrack(track);
                } else if (state.localStream) {
                    pc.addTrack(track, state.localStream);
                }
            }
            const offer = await pc.createOffer();
            await pc.setLocalDescription(offer);
            sendCallSignal("offer", {
                targetUserId: remoteUserId,
                sdp: offer.sdp || "",
                meta: { mode: "video", renegotiate: true, display_name: state.displayName },
            });
        }
        syncControls();
        renderMedia();
        return;
    }

    state.localStream?.getVideoTracks?.().forEach((track) => {
        try {
            track.stop();
        } catch {
            // noop
        }
        state.localStream?.removeTrack?.(track);
    });

    for (const remoteUserId of Object.keys(state.peerConnections || {})) {
        const pc = ensurePeerConnection(remoteUserId);
        const sender = pc.getSenders().find((candidate) => candidate.track?.kind === "video");
        if (sender) {
            await sender.replaceTrack(null);
        }
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        sendCallSignal("offer", {
            targetUserId: remoteUserId,
            sdp: offer.sdp || "",
            meta: { mode: "audio", renegotiate: true, display_name: state.displayName },
        });
    }

    state.isCameraEnabled = false;
    state.callMode = "audio";
    renderMedia();
    syncControls();
}

function cleanupRemotePeer(remoteUserId) {
    const remotePc = state.peerConnections?.[remoteUserId];
    if (remotePc) {
        try {
            remotePc.close();
        } catch {
            // noop
        }
    }
    delete state.peerConnections?.[remoteUserId];
    delete state.remoteStreams?.[remoteUserId];
    delete state.pendingIceCandidatesByUser?.[remoteUserId];
    delete state.incomingOffers?.[remoteUserId];
}

function refreshAggregateCallState() {
    const pendingOffers = Object.keys(state.incomingOffers || {}).length;
    const remoteConnections = Object.values(state.peerConnections || {});
    const hasConnected = remoteConnections.some((pc) => String(pc?.connectionState || "") === "connected");
    const hasActiveRemote = remoteConnections.length > 0;

    if (pendingOffers > 0) {
        state.callStatus = "incoming";
    } else if (hasConnected) {
        state.callStatus = "connected";
    } else if (hasActiveRemote) {
        state.callStatus = "connecting";
    } else {
        state.callStatus = "idle";
    }
}

function maybeAutoEndSoloCall() {
    if (Object.keys(state.peerConnections || {}).length > 0) {
        return;
    }
    if (Object.keys(state.incomingOffers || {}).length > 0) {
        return;
    }
    if (state.callStatus !== "idle") {
        return;
    }
    if (!state.localStream) {
        return;
    }

    stopAllConferenceMedia();
    state.callMode = "";
    state.isMicEnabled = true;
    state.isCameraEnabled = false;
    renderMedia();
    syncControls();
}

function stopAllConferenceMedia() {
    Object.values(state.peerConnections || {}).forEach((pc) => {
        try {
            pc.close();
        } catch {
            // noop
        }
    });

    [state.localStream, ...Object.values(state.remoteStreams || {})].forEach((stream) => {
        if (!stream) {
            return;
        }
        stream.getTracks().forEach((track) => {
            try {
                track.stop();
            } catch {
                // noop
            }
        });
    });
}

function getRosterParticipants() {
    return listPresenceRosterItems(state.roster).filter((item) => {
        const userId = String(item?.userId || "").trim();
        return userId && userId !== state.currentUserId;
    });
}

function shouldOfferToRemote(remoteUserId) {
    const current = String(state.currentUserId || "").trim();
    const remote = String(remoteUserId || "").trim();
    if (!current || !remote || current === remote) {
        return false;
    }

    return current.localeCompare(remote) < 0;
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

function renderMedia() {
    const localHost = document.getElementById("localMediaHost");
    const remoteHost = document.getElementById("remoteMediaHost");
    if (!localHost || !remoteHost) {
        return;
    }

    if (!state.localStream) {
        renderEmpty(localHost, "No local media", "Start or answer a call to acquire local audio/video.");
    } else {
        const localHasVideo = state.localStream.getVideoTracks().length > 0;
        localHost.innerHTML = `
            <article class="conference-media-card">
                <video id="localVideoEl" muted playsinline autoplay></video>
                <div class="conference-media-card-title">${escapeHtml(state.displayName || "Local peer")}</div>
                <div class="conference-media-card-meta">${localHasVideo ? "Audio + video" : "Audio only"}</div>
            </article>
        `;
        bindMediaElementStream(document.getElementById("localVideoEl"), state.localStream, { muted: true });
    }

    const remoteEntries = Object.entries(state.remoteStreams || {});
    if (!remoteEntries.length) {
        renderEmpty(remoteHost, "No remote media streams yet.", "Place or answer a call to start the mesh conference.");
        return;
    }

    remoteHost.innerHTML = remoteEntries.map(([remoteUserId]) => `
        <article class="conference-media-card">
            <video data-remote-user="${escapeHtml(remoteUserId)}" playsinline autoplay></video>
            <div class="conference-media-card-title">${escapeHtml(state.participantDisplayNames[remoteUserId] || remoteUserId)}</div>
            <div class="conference-media-card-meta">${describeRemoteStream(state.remoteStreams[remoteUserId])}</div>
        </article>
    `).join("");

    remoteEntries.forEach(([remoteUserId, stream]) => {
        const mediaEl = document.querySelector(`[data-remote-user="${CSS.escape(remoteUserId)}"]`);
        bindMediaElementStream(mediaEl, stream, { muted: false });
    });
}

function renderConferenceWarning() {
    const host = document.getElementById("conferenceWarningHost");
    if (!host) {
        return;
    }

    const count = getRosterParticipants().length + (state.currentUserId ? 1 : 0);
    const warning = getMeshConferenceWarning(count);
    if (!warning) {
        host.innerHTML = "";
        return;
    }

    host.innerHTML = `<div class="demo-warning-card">${escapeHtml(warning)}</div>`;
}

function renderEmpty(host, title, description) {
    if (typeof state.ui.emptyState !== "function") {
        host.innerHTML = `<div class="demo-muted">${escapeHtml(description)}</div>`;
        return;
    }

    state.ui.emptyState(host, { title, description }, { chrome: false });
}

function syncStatus() {
    setText("connectionStatus", formatStatus(state.connectionStatus));
    setText("presenceStatus", formatStatus(state.presenceStatus));
    setText("callStatus", formatStatus(state.callStatus));
    setText("effectiveRoom", state.effectiveRoom);
    setText("callRoom", state.callRoom);
    document.getElementById("disconnectButton")?.toggleAttribute("disabled", !state.client);
}

function syncControls() {
    const active = isRealtimeCallActive(state.callStatus);
    const incoming = state.callStatus === "incoming" && Object.keys(state.incomingOffers || {}).length > 0;
    const audioButton = document.getElementById("audioCallButton");
    const videoButton = document.getElementById("videoCallButton");
    const answerButton = document.getElementById("answerCallButton");
    const endButton = document.getElementById("endCallButton");
    const micButton = document.getElementById("toggleMicButton");
    const cameraButton = document.getElementById("toggleCameraButton");

    if (audioButton) {
        audioButton.hidden = active;
        audioButton.disabled = !state.client || !state.roomJoined || !state.callRoomJoined;
    }
    if (videoButton) {
        videoButton.hidden = active;
        videoButton.disabled = !state.client || !state.roomJoined || !state.callRoomJoined;
    }
    if (answerButton) {
        answerButton.hidden = !incoming;
        answerButton.disabled = !incoming;
    }
    if (endButton) {
        endButton.hidden = !active;
        endButton.disabled = !active;
    }
    if (micButton) {
        micButton.hidden = !active;
        micButton.disabled = !active;
        micButton.textContent = state.isMicEnabled ? "Mute mic" : "Unmute mic";
    }
    if (cameraButton) {
        cameraButton.hidden = !active;
        cameraButton.disabled = !active;
        cameraButton.textContent = state.isCameraEnabled ? "Camera off" : "Camera on";
    }
}

function sendRequest(type, room, payload) {
    return state.client?.sendRequest?.(type, room, payload) || null;
}

function logEvent(type, payload) {
    state.log.unshift({
        type,
        payload,
        at: new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", second: "2-digit" }),
    });
    renderLog();
}

function setConnectionStatus(value) {
    state.connectionStatus = value;
    syncStatus();
    syncControls();
}

function setPresenceStatus(value) {
    state.presenceStatus = value;
    syncStatus();
}

function toast(message, type = "info") {
    state.toast?.show?.(String(message), {
        title: "Conference Demo",
        type,
    });
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

function formatStatus(value) {
    const normalized = String(value || "idle").trim().toLowerCase();
    if (!normalized) {
        return "Idle";
    }
    return normalized.charAt(0).toUpperCase() + normalized.slice(1);
}

function describeRemoteStream(stream) {
    const hasVideo = Boolean(stream?.getVideoTracks?.().length);
    return hasVideo ? "Remote audio + video" : "Remote audio only";
}

function escapeHtml(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/\"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
