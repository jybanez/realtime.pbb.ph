import { marked } from "/vendor/helpers.pbb.ph/js/vendor/marked.esm.js";

import {
    RealtimeSocketClient,
    buildCallSignalPayload,
    buildChatPublishPayload,
    buildMediaChunkPublishPayload,
    buildPresencePublishPayload,
    buildPresenceSubscribePayload,
    buildRoomJoinPayload,
    bindMediaElementStream,
    createThreadAttachment,
    createRealtimeConferenceState,
    ensureConferencePeerConnection,
    ensureConferenceRemoteStream,
    formatAttachmentFileSize,
    formatRealtimeTimestamp,
    getAttachmentMimeType,
    getMeshConferenceWarning,
    inferAttachmentKind,
    isRealtimeCallActive,
    listPresenceRosterItems,
    normalizeChatMessageEvent,
    normalizeRealtimeSdp,
    parseRealtimeEnvelope,
    parseRealtimeSignalJson,
    reduceAttachmentChunkStore,
    reducePresenceRosterEvent,
    resolveAttachmentUrlFromStore,
    shouldPreviewAttachmentFile,
    transferAttachmentInChunks,
    validateDraftAttachments,
} from "./sdk/index.js";

const appEl = document.getElementById("app");
const csrfMeta = document.querySelector('meta[name="csrf-token"]');
const appName = appEl?.dataset.appName || "PBB Realtime";
const flashMessage = String(appEl?.dataset.flashMessage || "").trim();
const assetVersion = String(appEl?.dataset.assetVersion || "").trim();
const projectStyleHref = assetVersion ? `/css/app.css?v=${encodeURIComponent(assetVersion)}` : "/css/app.css";
const SANDBOX_RINGTONE_SRC = "/audio/ringtone.mp3";

const API = {
    bootstrap: "/api/admin/bootstrap",
    publicSdkDocs: "/api/sdk-docs",
    csrf: "/api/admin/csrf-token",
    clientOptions: "/api/admin/client-options",
    login: "/api/admin/login",
    logout: "/api/admin/logout",
    sessionPing: "/api/admin/session/ping",
    dashboard: "/api/admin/dashboard",
    clients: "/api/admin/clients",
    sandboxContext: "/api/admin/sandbox/context",
    sandboxAdmission: "/api/admin/sandbox/admission",
    realtimeSession: "/api/realtime/session",
    policies: "/api/admin/policies",
    projects: "/api/admin/projects",
    sessions: "/api/admin/sessions",
    audit: "/api/admin/audit",
    users: "/api/admin/users",
    userOptions: "/api/admin/user-options",
    userAudit: "/api/admin/users",
    operations: "/api/admin/operations",
    runtimeSettingsMaestroTelemetry: "/api/admin/runtime-settings/maestro-telemetry",
    runtimeSettingsAccount: "/api/admin/runtime-settings/account",
    telemetry: "/api/admin/telemetry",
    sdkDocs: "/api/admin/sdk-docs",
    backendSdkDownload: "/api/admin/sdk-downloads/backend-php",
    demoBundleDownload: "/api/admin/sdk-downloads/demo-bundle",
    me: "/api/admin/me",
    mePassword: "/api/admin/me/password",
};

const WEB = {
    dashboard: "/admin",
    clients: "/admin/clients",
    users: "/admin/users",
    sdk: "/admin/sdk",
    sdkBackend: "/admin/sdk/backend",
    sandbox: "/admin/sandbox",
    presenceInspector: "/admin/presence-inspector",
    policies: "/admin/policies",
    projects: "/admin/projects",
    sessions: "/admin/sessions",
    audit: "/admin/audit",
    operations: "/admin/operations",
    telemetry: "/admin/telemetry",
    publicSdk: "/sdk-docs",
    publicSdkBackend: "/sdk-docs/backend",
    status: "/",
};

const SDK_TUTORIALS = {
    quickstart: {
        slug: "quickstart",
        title: "Quickstart",
        summary: "Bring up a basic Realtime terminal with connection, room join, presence, chat, and optional media transport.",
        sections: [
            {
                title: "What You Need",
                bullets: [
                    "A product backend that issues Realtime admission/session tokens.",
                    "A room name and project scope that permit the behaviors you need.",
                    "The SDK core client plus the feature helpers you plan to use.",
                ],
            },
            {
                title: "Flow",
                bullets: [
                    "Call your product backend first to obtain Realtime admission, including the signed token.",
                    "Create a `RealtimeSocketClient` with the websocket URL and token returned by your backend.",
                    "Connect the socket and wait for `session.auth.request` ack.",
                    "Join the room, subscribe presence, and publish presence.",
                    "Use the chat helpers to publish and normalize messages.",
                    "Layer in media chunk transport only when the product needs browser-originated media ingress.",
                ],
                code: `import {\n  RealtimeSocketClient,\n  parseRealtimeEnvelope,\n  buildRoomJoinPayload,\n  buildPresenceSubscribePayload,\n  buildPresencePublishPayload,\n  buildChatPublishPayload,\n  normalizeChatMessageEvent,\n} from "/js/sdk/index.js";\n\nconst room = "chat.thread.hotline-room";\n\n// Step 1: ask your product backend to mint a Realtime token.\n// The SDK does not issue tokens on its own.\nconst admission = await fetch("/your-backend/realtime/admission", {\n  method: "POST",\n  headers: { \"Content-Type\": \"application/json\" },\n  body: JSON.stringify({\n    project_code: \"prj_...\",\n    room,\n    user_id: \"operator_123\",\n    display_name: \"Operator 123\",\n  }),\n}).then((response) => response.json());\n\n// Step 2: backend returns websocket URL + signed token.\nconst websocketUrl = admission.websocket_url;\nconst token = admission.token;\n\nconst client = new RealtimeSocketClient({ websocketUrl, token });\n\nclient.on(\"message\", (raw) => {\n  const envelope = parseRealtimeEnvelope(raw);\n\n  // After auth, join the room and start presence.\n  if (envelope.phase === \"ack\" && envelope.type === \"session.auth.request\") {\n    client.sendRequest(\"room.join.request\", room, buildRoomJoinPayload());\n    return;\n  }\n\n  if (envelope.phase === \"ack\" && envelope.type === \"room.join.request\") {\n    client.sendRequest(\"presence.subscribe\", room, buildPresenceSubscribePayload(room));\n    client.sendRequest(\"presence.publish\", room, buildPresencePublishPayload(room, \"online\", \"Terminal active\"));\n    return;\n  }\n\n  // Normalize chat events before giving them to your UI.\n  if (envelope.phase === \"event\" && envelope.type === \"chat.message.event\") {\n    const message = normalizeChatMessageEvent(envelope.payload, {\n      currentUserId: \"operator_123\",\n      fallbackSenderName: \"Realtime user\",\n    });\n    thread.setMessages([...thread.getMessages(), message]);\n  }\n});\n\nclient.connect();\n\n// Use the helper when publishing chat.\nclient.sendRequest(\"chat.message.publish\", room, buildChatPublishPayload(\"Hello from the SDK\"));`,
                returns: [
                    "`new RealtimeSocketClient(...)` returns a socket client instance with `connect()`, `close()`, `isOpen()`, `sendRequest()`, `on()`, and `off()`.",
                    "`client.connect()` returns the browser `WebSocket` instance.",
                    "`client.sendRequest(...)` returns the generated request id string, or `null` if the socket is not open.",
                    "`parseRealtimeEnvelope(raw)` returns a normalized envelope object with `phase`, `type`, `room`, `payload`, and `meta` fields.",
                    "`buildRoomJoinPayload()` returns `{}`.",
                    "`buildPresenceSubscribePayload(room)` returns `{ room }`.",
                    "`buildPresencePublishPayload(room, state, statusText)` returns a payload object with `room`, `state`, and `status_text`.",
                    "`buildChatPublishPayload(text, attachments)` returns `{ text, attachments }`.",
                    "`normalizeChatMessageEvent(...)` returns a UI-ready message object with normalized `id`, `direction`, `senderName`, `timestamp`, `attachments`, and `meta.senderUserId`.",
                ],
                args: [
                    "`new RealtimeSocketClient(options)` expects `websocketUrl`, `token`, and optional callbacks like `onOpen`, `onMessage`, `onError`, `onClose`. The `token` must come from your product backend, not from the SDK.",
                    "`client.sendRequest(type, room, payload, meta)` expects a Realtime request type string, room name, payload object, and optional metadata object.",
                    "`parseRealtimeEnvelope(raw)` expects the raw websocket message string.",
                    "`buildPresencePublishPayload(room, state, statusText)` expects the target room, presence state such as `online`, and an optional status text.",
                    "`normalizeChatMessageEvent(payload, options)` expects the raw event payload plus options like `currentUserId`, `fallbackSenderName`, and optional `resolveAttachmentUrl`.",
                ],
            },
        ],
    },
    presence: {
        slug: "presence",
        title: "Presence Tutorial",
        summary: "Track who is in a room and maintain a room roster safely.",
        sections: [
            {
                title: "SDK Pieces",
                bullets: [
                    "`buildPresenceSubscribePayload(room)`",
                    "`buildPresencePublishPayload(room, state, statusText)`",
                    "`reducePresenceRosterEvent(roster, payload)`",
                    "`listPresenceRosterItems(roster)`",
                ],
            },
            {
                title: "Recommended Pattern",
                bullets: [
                    "Treat the roster as reducer-managed state.",
                    "Subscribe after room join ack.",
                    "Publish presence after subscribe, not before.",
                    "Never make product-specific user lookups part of the transport roster reducer.",
                ],
                code: `import {\n  buildPresenceSubscribePayload,\n  buildPresencePublishPayload,\n  reducePresenceRosterEvent,\n  listPresenceRosterItems,\n} from "/js/sdk/index.js";\n\nlet roster = {};\n\nclient.on("message", (raw) => {\n  const envelope = parseRealtimeEnvelope(raw);\n\n  if (envelope.phase === "ack" && envelope.type === "room.join.request") {\n    // Subscribe first so the terminal sees roster updates immediately.\n    client.sendRequest(\"presence.subscribe\", room, buildPresenceSubscribePayload(room));\n    client.sendRequest(\"presence.publish\", room, buildPresencePublishPayload(room, \"online\", \"Available\"));\n    return;\n  }\n\n  if (envelope.phase === \"event\" && envelope.type === \"presence.state.event\") {\n    // Reducer keeps the roster stable and removes offline peers correctly.\n    roster = reducePresenceRosterEvent(roster, envelope.payload);\n    const items = listPresenceRosterItems(roster);\n    rosterView.render(items);\n  }\n});`,
                returns: [
                    "`reducePresenceRosterEvent(roster, payload)` returns a new roster object keyed by session or user identity.",
                    "`listPresenceRosterItems(roster)` returns a sorted array of visible roster entries.",
                    "Each roster entry contains `key`, `sessionId`, `userId`, `displayName`, `projectCode`, `appCode`, `state`, `statusText`, `updatedAt`, and `expiresAt`.",
                ],
                args: [
                    "`buildPresenceSubscribePayload(room)` expects the room name string.",
                    "`buildPresencePublishPayload(room, state, statusText)` expects the room, state, and optional status text.",
                    "`reducePresenceRosterEvent(roster, payload)` expects the current roster object and the raw `presence.state.event` payload.",
                    "`listPresenceRosterItems(roster)` expects the reducer-managed roster object.",
                ],
            },
        ],
    },
    chat: {
        slug: "chat",
        title: "Chat Tutorial",
        summary: "Publish chat messages and normalize incoming events into a terminal-friendly model.",
        sections: [
            {
                title: "SDK Pieces",
                bullets: [
                    "`buildChatPublishPayload(text, attachments)`",
                    "`normalizeChatMessageEvent(payload, options)`",
                    "`formatRealtimeTimestamp(value)`",
                ],
            },
            {
                title: "Recommended Pattern",
                bullets: [
                    "Keep optimistic outgoing UI state in the app.",
                    "Replace optimistic messages with normalized incoming events on delivery.",
                    "Keep attachment URL resolution outside the raw event payload via `resolveAttachmentUrl`.",
                ],
                code: `import {\n  buildChatPublishPayload,\n  normalizeChatMessageEvent,\n} from \"/js/sdk/index.js\";\n\nconst pending = new Map();\n\nfunction sendChat(text) {\n  // Product UI creates optimistic state before delivery.\n  const localId = crypto.randomUUID();\n  pending.set(localId, { text });\n  thread.add({ id: localId, text, direction: \"outgoing\", state: \"sending\" });\n\n  client.sendRequest(\"chat.message.publish\", room, buildChatPublishPayload(text, []));\n}\n\nclient.on(\"message\", (raw) => {\n  const envelope = parseRealtimeEnvelope(raw);\n  if (envelope.phase !== \"event\" || envelope.type !== \"chat.message.event\") {\n    return;\n  }\n\n  const message = normalizeChatMessageEvent(envelope.payload, {\n    currentUserId: session.user_id,\n    fallbackSenderName: \"Realtime user\",\n    // Keep attachment resolution separate so reassembly can fill it later.\n    resolveAttachmentUrl: (attachment, field) => attachment?.[field] || attachment?.url || \"\",\n  });\n\n  thread.upsert(message);\n});`,
                returns: [
                    "`buildChatPublishPayload(text, attachments)` returns `{ text, attachments }`.",
                    "`normalizeChatMessageEvent(...)` returns a normalized message object with `direction`, `state`, `timestamp`, `attachments`, and `meta.senderUserId` already derived.",
                    "`formatRealtimeTimestamp(value)` returns a display-oriented time string, or the original value if it cannot be parsed as a date.",
                ],
                args: [
                    "`buildChatPublishPayload(text, attachments)` expects a message text string and an attachment array.",
                    "`normalizeChatMessageEvent(payload, options)` expects the raw `chat.message.event` payload and options such as `currentUserId`, `fallbackSenderName`, and optional attachment URL resolver.",
                    "`formatRealtimeTimestamp(value)` expects an ISO timestamp or any date-like string.",
                ],
            },
        ],
    },
    attachments: {
        slug: "attachments",
        title: "Attachment Transport Tutorial",
        summary: "Validate, chunk, transport, and reassemble attachments with policy-aware limits.",
        sections: [
            {
                title: "SDK Pieces",
                bullets: [
                    "`validateDraftAttachments(...)`",
                    "`transferAttachmentInChunks(item, options)`",
                    "`reduceAttachmentChunkStore(store, payload)`",
                    "`resolveAttachmentUrlFromStore(store, attachment, field)`",
                ],
            },
            {
                title: "Transport Rule",
                bullets: [
                    "Validate file count and byte limits before transport starts.",
                    "Use chunk transport only for sandbox or explicitly supported product flows.",
                    "Preserve policy-aware limits at the application boundary.",
                ],
                code: `import {\n  validateDraftAttachments,\n  transferAttachmentInChunks,\n  reduceAttachmentChunkStore,\n  resolveAttachmentUrlFromStore,\n} from \"/js/sdk/index.js\";\n\nlet received = {};\n\nasync function sendFiles(existingUploads, files, policy) {\n  const { accepted, rejected } = validateDraftAttachments({\n    existingItems: existingUploads,\n    files,\n    policy,\n  });\n\n  rejected.forEach((message) => toast.warn(message));\n\n  for (const item of accepted) {\n    // The SDK handles chunk splitting and progress callbacks.\n    await transferAttachmentInChunks(item, {\n      onChunk: (payload) => {\n        client.sendRequest(\"sandbox.attachment.chunk.publish\", room, payload);\n      },\n      onProgress: (progress, label) => {\n        uploadQueue.update(item.id, { progress, progressLabel: label });\n      },\n    });\n  }\n}\n\nclient.on(\"message\", (raw) => {\n  const envelope = parseRealtimeEnvelope(raw);\n  if (envelope.phase === \"event\" && envelope.type === \"sandbox.attachment.chunk.event\") {\n    received = reduceAttachmentChunkStore(received, envelope.payload);\n  }\n});\n\nfunction resolveUrl(attachment, field) {\n  return resolveAttachmentUrlFromStore(received, attachment, field);\n}`,
                returns: [
                    "`validateDraftAttachments(...)` returns `{ accepted, rejected }`.",
                    "`transferAttachmentInChunks(item, options)` returns a normalized transport attachment object suitable for chat payloads.",
                    "`reduceAttachmentChunkStore(store, payload)` returns a new attachment-store object keyed by `transfer_id`.",
                    "`resolveAttachmentUrlFromStore(store, attachment, field)` returns a resolved URL string or an empty string if the attachment is not yet complete.",
                    "`inferAttachmentKind(file)` returns one of `image`, `video`, `audio`, or `file`.",
                    "`formatAttachmentFileSize(bytes)` returns a human-readable label such as `512 KB` or `1.5 MB`.",
                ],
                args: [
                    "`validateDraftAttachments({ existingItems, files, policy })` expects current queued items, incoming `FileList` or array, and the effective attachment policy.",
                    "`transferAttachmentInChunks(item, options)` expects a hydrated attachment item plus callbacks like `onChunk(payload)` and `onProgress(progress, label)`.",
                    "`reduceAttachmentChunkStore(store, payload)` expects the current transfer store and the raw chunk event payload.",
                    "`resolveAttachmentUrlFromStore(store, attachment, field)` expects the current transfer store, a normalized attachment object, and the desired URL field such as `url` or `preview_url`.",
                    "`inferAttachmentKind(file)` expects a browser `File`-like object with `type`.",
                ],
            },
        ],
    },
    media: {
        slug: "media",
        title: "Media Transport Tutorial",
        summary: "Publish browser-originated media chunks over websocket while keeping storage and lifecycle in the product backend.",
        sections: [
            {
                title: "SDK Pieces",
                bullets: [
                    "`buildMediaChunkPublishPayload(input)`",
                    "`client.sendRequest(\"media.chunk.publish\", room, payload)`",
                ],
            },
            {
                title: "Transport Boundary",
                bullets: [
                    "Enable media ingest on the project scope that should publish browser-originated media chunks.",
                    "Join the authorized `call.session.*` or `stream.session.*` room before publishing.",
                    "Treat the immediate `media.chunk.publish` ack as queue acceptance only, not downstream persistence.",
                    "Use `media.chunk.forwarded` as the durable deletion boundary for local retry data, and handle `media.chunk.failed` as retryable failure.",
                    "Keep temp storage, merge/finalize, and media business events in the product backend rather than in Realtime.",
                ],
                code: `import {\n  buildMediaChunkPublishPayload,\n} from "/js/sdk/index.js";\n\nconst mediaRoom = "call.session.call_001";\n\nconst payload = buildMediaChunkPublishPayload({\n  call_session_id: "call_001",\n  media_id: "media_operator_local_audio",\n  type: "recording",\n  track_kind: "audio",\n  mime_type: "audio/webm",\n  extension: "webm",\n  chunk_index: 0,\n  chunk_total: 24,\n  total_bytes: 24576,\n  chunk_data: base64Chunk,\n  correlation_id: "corr_media_001",\n});\n\nclient.sendRequest("media.chunk.publish", mediaRoom, payload);`,
                returns: [
                    "`buildMediaChunkPublishPayload(input)` returns a normalized payload object for `media.chunk.publish`.",
                    "`client.sendRequest(\"media.chunk.publish\", room, payload)` returns the generated request id string, or `null` if the socket is not open.",
                    "`media.chunk.publish` ack payloads confirm `delivery: \"queued\"` inside Realtime only.",
                    "`media.chunk.forwarded` and `media.chunk.failed` events carry downstream forwarding outcomes for the same room.",
                ],
                args: [
                    "`buildMediaChunkPublishPayload(input)` expects media routing fields such as `media_id` or `segment_key`, media metadata such as `type`, `track_kind`, `mime_type`, chunk indexes, and base64 `chunk_data`.",
                    "`client.sendRequest(\"media.chunk.publish\", room, payload)` expects an already joined authorized room and the normalized media payload object.",
                ],
            },
        ],
    },
    conference: {
        slug: "conference",
        title: "Call And Conference Tutorial",
        summary: "Run signaling and small-group mesh behavior with a hard default cap of 5 participants.",
        sections: [
            {
                title: "SDK Pieces",
                bullets: [
                    "`buildCallSignalPayload(signalType, options)`",
                    "`normalizeRealtimeSdp(value)`",
                    "`parseRealtimeSignalJson(value)`",
                    "`ensureConferencePeerConnection(...)`",
                    "`ensureConferenceRemoteStream(...)`",
                    "`bindMediaElementStream(...)`",
                ],
            },
            {
                title: "Conference Guardrails",
                bullets: [
                    "Current default mesh hard limit is 5 participants.",
                    "Warn operators at 4+ participants.",
                    "Treat media transport as device-to-device; Realtime remains signaling only.",
                ],
                code: `import {\n  buildCallSignalPayload,\n  normalizeRealtimeSdp,\n  parseRealtimeSignalJson,\n  ensureConferencePeerConnection,\n  ensureConferenceRemoteStream,\n  bindMediaElementStream,\n} from \"/js/sdk/index.js\";\n\nconst peerConnections = {};\nconst remoteStreams = {};\n\nfunction ensurePeerConnection(remoteUserId) {\n  return ensureConferencePeerConnection(peerConnections, remoteUserId, () => {\n    const pc = new RTCPeerConnection({\n      iceServers: [{ urls: \"stun:stun.l.google.com:19302\" }],\n    });\n\n    const remoteStream = ensureConferenceRemoteStream(remoteStreams, remoteUserId, () => new MediaStream());\n\n    // Each remote participant gets its own stream in mesh mode.\n    pc.ontrack = (event) => {\n      const tracks = event.streams[0]?.getTracks?.() || [event.track].filter(Boolean);\n      tracks.forEach((track) => remoteStream.addTrack(track));\n      bindMediaElementStream(document.querySelector(\`[data-remote-user=\"\${remoteUserId}\"]\`), remoteStream, { muted: false });\n    };\n\n    pc.onicecandidate = (event) => {\n      if (!event.candidate) return;\n      client.sendRequest(\"call.signal.publish\", callRoom, buildCallSignalPayload(\"ice-candidate\", {\n        targetUserId: remoteUserId,\n        candidate: event.candidate.toJSON(),\n      }));\n    };\n\n    return pc;\n  });\n}\n\nasync function applyOffer(remoteUserId, rawSdp) {\n  const pc = ensurePeerConnection(remoteUserId);\n  await pc.setRemoteDescription(new RTCSessionDescription({\n    type: \"offer\",\n    sdp: normalizeRealtimeSdp(rawSdp),\n  }));\n  const answer = await pc.createAnswer();\n  await pc.setLocalDescription(answer);\n\n  client.sendRequest(\"call.signal.publish\", callRoom, buildCallSignalPayload(\"answer\", {\n    targetUserId: remoteUserId,\n    sdp: answer.sdp,\n    meta: { mode: \"video\" },\n  }));\n}\n\n// Parse helper keeps candidate/meta handling predictable.\nconst meta = parseRealtimeSignalJson(envelope.payload?.meta_json);`,
                returns: [
                    "`buildCallSignalPayload(signalType, options)` returns a signaling payload with `signal_type`, `target_user_id`, `sdp`, `candidate_json`, and `meta_json`.",
                    "`normalizeRealtimeSdp(value)` returns an SDP string normalized to CRLF line endings.",
                    "`parseRealtimeSignalJson(value)` returns the parsed object or `null` on invalid JSON.",
                    "`ensureConferencePeerConnection(peerConnections, remoteUserId, factory)` returns the existing or newly created `RTCPeerConnection`.",
                    "`ensureConferenceRemoteStream(remoteStreams, remoteUserId, factory)` returns the existing or newly created `MediaStream`.",
                    "`bindMediaElementStream(mediaEl, stream, options)` returns nothing; it mutates the DOM media element binding in place.",
                    "`isRealtimeCallActive(callState)` returns a boolean.",
                ],
                args: [
                    "`buildCallSignalPayload(signalType, options)` expects a signal type like `offer`, `answer`, or `ice-candidate`, plus optional `targetUserId`, `sdp`, `candidate`, and `meta`.",
                    "`normalizeRealtimeSdp(value)` expects a raw SDP string.",
                    "`parseRealtimeSignalJson(value)` expects a JSON string from `candidate_json` or `meta_json`.",
                    "`ensureConferencePeerConnection(peerConnections, remoteUserId, factory)` expects a mutable peer-connection map, the remote participant id, and a lazy factory function.",
                    "`ensureConferenceRemoteStream(remoteStreams, remoteUserId, factory)` expects a mutable remote-stream map, the remote participant id, and a lazy factory function.",
                    "`bindMediaElementStream(mediaEl, stream, options)` expects an `HTMLMediaElement`, a `MediaStream | null`, and optional flags such as `muted`.",
                    "`isRealtimeCallActive(callState)` expects the current call state string.",
                ],
            },
        ],
    },
};

const normalizedPath = getInitialPath();
const routeState = resolveRouteState(normalizedPath);
const SANDBOX_LOG_LIMIT = 200;
const SANDBOX_LOG_ROW_HEIGHT = 76;
const SANDBOX_LOG_OVERSCAN = 6;

const state = {
    appName,
    csrfToken: String(csrfMeta?.content || "").trim(),
    route: routeState,
    authenticated: false,
    account: null,
    accountSso: {
        enabled: false,
        redirectUrl: "/auth/account/redirect",
    },
    sessionLifetimeMinutes: 120,
    keepaliveThresholdSeconds: 120,
    lastServerTouchAt: Date.now(),
    lastUserActivityAt: Date.now(),
    lastKeepaliveAt: 0,
    keepaliveInFlight: false,
    keepaliveTimer: null,
    sessionPromptOpen: false,
    accountModalOpen: false,
    passwordModalOpen: false,
    loginModalOpen: false,
    logoutInFlight: false,
    shellNavigationBound: false,
    pageData: null,
    ui: {
        navbar: null,
        emptyState: null,
        skeleton: null,
        formModal: null,
        actionModal: null,
        loginFormModal: null,
        reauthFormModal: null,
        accountFormModal: null,
        changePasswordFormModal: null,
        confirmDialog: null,
        toastFactory: null,
        toast: null,
        chatThread: null,
        chatComposer: null,
        chatUploadQueue: null,
        audioGraph: null,
        virtualList: null,
        propertyEditor: null,
        icons: null,
        clientsGrid: null,
        projectsGrid: null,
        policiesGrid: null,
        usersGrid: null,
        sessionsGrid: null,
        auditGrid: null,
    },
    sandbox: createSandboxState(),
    presenceInspector: createPresenceInspectorState(),
};

bootstrap();

async function bootstrap() {
    if (!appEl) {
        return;
    }

    await loadUiModules();
    ensureProjectStyles();
    renderShell();
    initUiRuntime();
    bindShellNavigation();

    if (state.route.kind === "status") {
        const bootOk = await bootstrapAdminSession(false);
        if (bootOk && state.authenticated && state.account) {
            state.route = resolveRouteState(WEB.dashboard);
            if (window.location.pathname === WEB.status) {
                window.history.replaceState({}, "", WEB.dashboard);
            }
            renderNavbar();
            if (flashMessage) {
                showToast(flashMessage, { title: "Status", type: "success" });
            }
            await renderCurrentPage();
            startKeepaliveWatcher();
            return;
        }

        renderStatusPage();
        renderNavbar();
        if (flashMessage) {
            showToast(flashMessage, { title: "Status", type: "success" });
        }
        return;
    }

    const bootOk = await bootstrapAdminSession();
    if (!bootOk) {
        return;
    }

    renderNavbar();
    if (flashMessage) {
        showToast(flashMessage, { title: "Status", type: "success" });
    }
    await renderCurrentPage();
    startKeepaliveWatcher();
}

async function loadUiModules() {
    const { uiLoader } = await import("/vendor/helpers.pbb.ph/js/ui/ui.loader.js");
    uiLoader.setPreferBundles(true);

    await uiLoader.loadMany([
        "ui.navbar",
        "ui.empty.state",
        "ui.skeleton",
        "ui.toast",
        "ui.dialog.confirm",
        "ui.action.modal",
        "ui.form.modal",
        "ui.form.modal.login",
        "ui.form.modal.reauth",
        "ui.form.modal.account",
        "ui.form.modal.change.password",
        "ui.chat.thread",
        "ui.chat.composer",
        "ui.chat.upload.queue",
        "ui.audio.audiograph",
        "ui.virtual.list",
        "ui.property.editor",
        "ui.icons",
        "ui.grid",
    ]);

    state.ui.navbar = await uiLoader.get("ui.navbar");
    state.ui.emptyState = await uiLoader.get("ui.empty.state");
    state.ui.skeleton = await uiLoader.get("ui.skeleton");
    state.ui.toastFactory = await uiLoader.get("ui.toast");
    state.ui.confirmDialog = await uiLoader.get("ui.dialog.confirm");
    state.ui.actionModal = await uiLoader.get("ui.action.modal");
    state.ui.formModal = await uiLoader.get("ui.form.modal");
    state.ui.chatThread = await uiLoader.get("ui.chat.thread");
    state.ui.chatComposer = await uiLoader.get("ui.chat.composer");
    state.ui.chatUploadQueue = await uiLoader.get("ui.chat.upload.queue");
    state.ui.audioGraph = await uiLoader.get("ui.audio.audiograph");
    state.ui.virtualList = await uiLoader.get("ui.virtual.list");
    state.ui.propertyEditor = await uiLoader.get("ui.property.editor");
    state.ui.icons = await uiLoader.get("ui.icons");
    state.ui.grid = await uiLoader.get("ui.grid");

    state.ui.loginFormModal = await uiLoader.get("ui.form.modal.login");
    state.ui.reauthFormModal = await uiLoader.get("ui.form.modal.reauth");
    state.ui.accountFormModal = await uiLoader.get("ui.form.modal.account");
    state.ui.changePasswordFormModal = await uiLoader.get("ui.form.modal.change.password");
}

function ensureProjectStyles() {
    if (document.querySelector(`link[rel="stylesheet"][href="${projectStyleHref}"]`)) {
        return;
    }

    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = projectStyleHref;
    document.head.appendChild(link);
}

function initUiRuntime() {
    if (!state.ui.toast && state.ui.toastFactory) {
        state.ui.toast = state.ui.toastFactory({
            position: "bottom-right",
            defaultDuration: 2800,
        });
    }
}

function bindShellNavigation() {
    if (state.shellNavigationBound) {
        return;
    }

    state.shellNavigationBound = true;

    document.addEventListener("click", (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }

        const button = target.closest(
            "[data-page-nav], [data-client-open], [data-project-open], [data-policy-open], #openClientsButton, #backToClientsButton, #backToClientButton, #backToPoliciesButton"
        );

        if (!button || button.matches(":disabled, .is-disabled")) {
            return;
        }

        const pageNav = button.getAttribute("data-page-nav");
        if (pageNav) {
            navigateShell(pageNav);
            return;
        }

        const clientOpen = button.getAttribute("data-client-open");
        if (clientOpen) {
            navigateShell(`${WEB.clients}/${encodeURIComponent(clientOpen.trim())}`);
            return;
        }

        const projectOpen = button.getAttribute("data-project-open");
        if (projectOpen) {
            navigateShell(`${WEB.projects}/${encodeURIComponent(projectOpen.trim())}`);
            return;
        }

        const policyOpen = button.getAttribute("data-policy-open");
        if (policyOpen) {
            navigateShell(`${WEB.policies}/${encodeURIComponent(policyOpen.trim())}`);
            return;
        }

        if (button.id === "openClientsButton" || button.id === "backToClientsButton") {
            navigateShell(WEB.clients);
            return;
        }

        if (button.id === "backToClientButton") {
            const clientCode = String(pageHostClientCode() || "").trim();
            navigateShell(clientCode ? `${WEB.clients}/${encodeURIComponent(clientCode)}` : WEB.clients);
            return;
        }

        if (button.id === "backToPoliciesButton") {
            navigateShell(WEB.policies);
        }
    });

    window.addEventListener("popstate", () => {
        const nextRoute = resolveRouteState(normalizePath(window.location.pathname));
        if (nextRoute.kind === state.route.kind && nextRoute.id === state.route.id) {
            return;
        }

        state.route = nextRoute;
        if (appEl) {
            appEl.dataset.page = getPathForRoute(state.route);
        }

        renderShell();
        renderNavbar();
        void renderCurrentPage();
    });
}

function pageHostClientCode() {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) {
        return "";
    }

    const clientCode = pageHost.querySelector("[data-client-code]")?.getAttribute("data-client-code");
    return String(clientCode || "");
}

function renderShell() {
    if (appEl) {
        appEl.dataset.page = getPathForRoute(state.route);
    }

    state.ui.clientsGrid?.destroy?.();
    state.ui.clientsGrid = null;
    state.ui.usersGrid?.destroy?.();
    state.ui.usersGrid = null;
    state.ui.sessionsGrid?.destroy?.();
    state.ui.sessionsGrid = null;
    state.ui.auditGrid?.destroy?.();
    state.ui.auditGrid = null;

    appEl.innerHTML = `
        <div class="app-shell">
            <header class="shell-header">
                <div id="navbarHost"></div>
            </header>
            <main id="pageHost" class="app-grid"></main>
        </div>
    `;
    document.body.classList.remove("auth-page");
}

function renderStatusPage() {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    const wsHost = String(appEl?.dataset.wsHost || "127.0.0.1").trim();
    const wsPort = String(appEl?.dataset.wsPort || "8080").trim();
    const tokenAudience = String(appEl?.dataset.tokenAudience || "pbb-realtime").trim();
    const environment = String(appEl?.dataset.environment || "local").trim();
    const laravel = String(appEl?.dataset.laravel || "10.50.2").trim();
    const isAuthenticated = Boolean(state.account);

    pageHost.innerHTML = `
        <section class="status-layout">
            <article class="panel panel-stack status-main">
                <div class="status-badge-row">
                    <span class="ui-badge">PBB Realtime</span>
                    <span class="ui-badge">API-first gateway</span>
                    <span class="ui-badge">Backend-only service</span>
                </div>

                <div class="status-copy">
                    <h1 class="ui-title status-title">
                        Shared realtime control plane for PBB apps.
                    </h1>
                    <p class="status-lede">
                        This service stays backend-only. It handles websocket session admission,
                        room membership, presence, chat routing, and signaling for browser apps
                        across the PBB ecosystem.
                    </p>
                </div>

                <div class="field-grid three status-summary-grid">
                    ${renderStatusSummaryCard("Service", "Online", "Private realtime gateway is reachable")}
                    ${renderStatusSummaryCard("WebSocket", "/realtime", "Session transport and room signaling")}
                    ${renderStatusSummaryCard("HTTP", "/api/*", "Health, readiness, metrics, and admin APIs")}
                </div>

                <div class="field-grid two status-action-grid">
                    <article class="panel status-subpanel">
                        <p class="eyebrow">Operators</p>
                        <h2 class="status-subtitle">Private admin access</h2>
                        <p class="empty-note">${escapeHtml(
                            isAuthenticated
                                ? "An authenticated admin session is already active in this browser."
                                : "Use the admin surface for client, policy, sandbox, session, and audit work."
                        )}</p>
                        <div class="actions">
                            <button class="button" id="statusOperatorButton" type="button">${escapeHtml(isAuthenticated ? "Open dashboard" : "Sign in")}</button>
                            <button class="button-secondary" id="statusSdkDocsButton" type="button">Open SDK docs</button>
                        </div>
                    </article>

                    <article class="panel status-subpanel">
                        <p class="eyebrow">Endpoints</p>
                        <h2 class="status-subtitle">Service diagnostics</h2>
                        <p class="empty-note">Open the live health and readiness endpoints directly from this page.</p>
                        <div class="actions">
                            <button class="button-secondary" id="statusHealthButton" type="button">Open health</button>
                            <button class="button-secondary" id="statusReadyButton" type="button">Open ready</button>
                            <button class="button-secondary" id="statusMetricsButton" type="button">Open metrics</button>
                        </div>
                    </article>
                </div>
            </article>

            <aside class="panel panel-stack status-aside">
                <div>
                    <p class="section-title">Service</p>
                    <h2 class="status-subtitle">Runtime profile</h2>
                </div>
                <div class="ui-badge">Online</div>

                <div class="status-list">
                    <div class="status-row">
                        <strong>Gateway</strong>
                        <span>${escapeHtml(`${wsHost}:${wsPort}`)}</span>
                    </div>
                    <div class="status-row">
                        <strong>Audience</strong>
                        <span>${escapeHtml(tokenAudience)}</span>
                    </div>
                    <div class="status-row">
                        <strong>Environment</strong>
                        <span>${escapeHtml(environment)}</span>
                    </div>
                    <div class="status-row">
                        <strong>Runtime</strong>
                        <span>${escapeHtml(`Laravel ${laravel}`)}</span>
                    </div>
                    <div class="status-row">
                        <strong>WebSocket</strong>
                        <span>/realtime</span>
                    </div>
                </div>

                <div class="status-capability-list">
                    <div class="status-capability-card">
                        <strong>Session admission</strong>
                        <span>JWT validation and connection acceptance</span>
                    </div>
                    <div class="status-capability-card">
                        <strong>Room transport</strong>
                        <span>Membership, presence, chat, media transport, and call signaling</span>
                    </div>
                    <div class="status-capability-card">
                        <strong>Operator surface</strong>
                        <span>Private admin shell for clients, policies, sessions, and audit</span>
                    </div>
                </div>
            </aside>
        </section>

        <div class="status-footer">
            <span>Built for <code>PBB HQ</code>, <code>PBB Workspace</code>, and other PBB browser apps.</span>
            <span>UI rendered with vendored <code>helpers.pbb.ph</code>.</span>
        </div>
    `;

    document.getElementById("statusOperatorButton")?.addEventListener("click", () => {
        if (isAuthenticated) {
            navigateShell(WEB.dashboard);
            return;
        }
        startLoginFlow();
    });

    document.getElementById("statusSdkDocsButton")?.addEventListener("click", () => {
        navigateShell(WEB.publicSdk);
    });

    document.getElementById("statusHealthButton")?.addEventListener("click", () => {
        window.location.href = "/api/health";
    });

    document.getElementById("statusReadyButton")?.addEventListener("click", () => {
        window.location.href = "/api/ready";
    });

    document.getElementById("statusMetricsButton")?.addEventListener("click", () => {
        window.location.href = "/api/metrics";
    });
}

function renderStatusSummaryCard(label, value, caption) {
    return `
        <article class="panel status-summary-card">
            <p class="eyebrow">${escapeHtml(label)}</p>
            <h2 class="status-summary-value">${escapeHtml(value)}</h2>
            <p class="empty-note">${escapeHtml(caption)}</p>
        </article>
    `;
}

async function bootstrapAdminSession(redirectOnFailure = true) {
    const { response, data } = await requestJson(API.bootstrap, { handleSessionExpiry: false });

    if (!response.ok) {
        if (redirectOnFailure) {
            redirectToLogin();
        }
        return false;
    }

    state.authenticated = Boolean(data?.auth?.authenticated);
    state.account = normalizeAccount(data?.auth?.account);
    state.accountSso = normalizeAccountSso(data?.settings?.accountSso);
    state.sessionLifetimeMinutes = Math.max(1, Number(data?.settings?.sessionLifetimeMinutes ?? 120) || 120);
    state.keepaliveThresholdSeconds = Math.max(
        15,
        Number(data?.settings?.keepaliveThresholdSeconds ?? deriveKeepaliveThresholdSeconds(state.sessionLifetimeMinutes)) || 15,
    );
    state.lastServerTouchAt = Date.now();
    state.lastUserActivityAt = Date.now();
    state.lastKeepaliveAt = 0;

    if (data?.security?.csrfToken) {
        setCsrfToken(data.security.csrfToken);
    }

    if (!state.authenticated || !state.account) {
        if (redirectOnFailure) {
            redirectToLogin();
        }
        return false;
    }

    return true;
}

function normalizeAccount(account) {
    if (!account || typeof account !== "object") {
        return null;
    }

    return {
        id: account.id ?? null,
        name: account.name ?? "",
        email: account.email ?? "",
        is_operator: Boolean(account.is_operator),
        user_type: String(account.user_type || ""),
        is_admin: Boolean(account.is_admin),
        assigned_clients: Array.isArray(account.assigned_clients) ? account.assigned_clients : [],
    };
}

function normalizeAccountSso(accountSso) {
    if (!accountSso || typeof accountSso !== "object") {
        return {
            enabled: false,
            redirectUrl: "/auth/account/redirect",
        };
    }

    return {
        enabled: Boolean(accountSso.enabled),
        clientId: String(accountSso.clientId || "pbb-realtime"),
        baseUrl: String(accountSso.baseUrl || "https://account.pbb.ph"),
        redirectUrl: String(accountSso.redirectUrl || "/auth/account/redirect"),
    };
}

function isCurrentUserAdmin() {
    return Boolean(state.account?.is_admin);
}

function deriveKeepaliveThresholdSeconds(lifetimeMinutes) {
    const lifetimeSeconds = Math.max(60, Number(lifetimeMinutes) || 120) * 60;
    return Math.max(15, Math.min(120, Math.floor(lifetimeSeconds * 0.2)));
}

function applySessionTimingPayload(payload = {}) {
    if (payload?.session_lifetime_minutes) {
        state.sessionLifetimeMinutes = Math.max(1, Number(payload.session_lifetime_minutes) || state.sessionLifetimeMinutes);
    }

    if (payload?.keepalive_threshold_seconds) {
        state.keepaliveThresholdSeconds = Math.max(15, Number(payload.keepalive_threshold_seconds) || state.keepaliveThresholdSeconds);
    } else if (payload?.session_lifetime_minutes) {
        state.keepaliveThresholdSeconds = deriveKeepaliveThresholdSeconds(state.sessionLifetimeMinutes);
    }
}

function resolveRouteState(pathname) {
    const path = normalizePath(pathname);

    if (path === WEB.publicSdk) {
        return { kind: "publicSdk", title: "SDK Docs" };
    }

    if (path === WEB.publicSdkBackend) {
        return { kind: "publicSdkBackend", title: "Backend SDK" };
    }

    if (path === WEB.dashboard) {
        return { kind: "dashboard", title: "Dashboard" };
    }

    if (path === WEB.clients) {
        return { kind: "clients", title: "Clients" };
    }

    if (path === WEB.users) {
        return { kind: "users", title: "Users" };
    }

    if (path === WEB.sdk) {
        return { kind: "sdk", title: "SDK" };
    }

    if (path === WEB.sdkBackend) {
        return { kind: "sdkBackend", title: "Backend SDK" };
    }

    if (path === WEB.sandbox) {
        return { kind: "sandbox", title: "Sandbox" };
    }

    if (path === WEB.presenceInspector) {
        return { kind: "presenceInspector", title: "Presence" };
    }

    if (path === WEB.policies) {
        return { kind: "policies", title: "Policies" };
    }

    if (path === WEB.sessions) {
        return { kind: "sessions", title: "Sessions" };
    }

    if (path === WEB.audit) {
        return { kind: "audit", title: "Audit" };
    }

    if (path === WEB.operations) {
        return { kind: "operations", title: "Operations" };
    }

    if (path === WEB.telemetry) {
        return { kind: "telemetry", title: "Telemetry" };
    }

    const clientMatch = path.match(/^\/admin\/clients\/([^/]+)$/);
    if (clientMatch) {
        return { kind: "client", id: clientMatch[1], title: "Client" };
    }

    const sdkTutorialMatch = path.match(/^\/admin\/sdk\/tutorials\/([^/]+)$/);
    if (sdkTutorialMatch) {
        return { kind: "sdkTutorial", id: sdkTutorialMatch[1], title: "SDK Tutorial" };
    }

    const publicSdkTutorialMatch = path.match(/^\/sdk-docs\/tutorials\/([^/]+)$/);
    if (publicSdkTutorialMatch) {
        return { kind: "publicSdkTutorial", id: publicSdkTutorialMatch[1], title: "SDK Tutorial" };
    }

    const policyMatch = path.match(/^\/admin\/policies\/([^/]+)$/);
    if (policyMatch) {
        return { kind: "policy", id: policyMatch[1], title: "Policy" };
    }

    const projectMatch = path.match(/^\/admin\/projects\/([^/]+)$/);
    if (projectMatch) {
        return { kind: "project", id: projectMatch[1], title: "Project" };
    }

    if (path.startsWith("/admin")) {
        return { kind: "dashboard", title: "Dashboard" };
    }

    return { kind: "status", title: "Status" };
}

function normalizePath(pathname) {
    const value = String(pathname || "/").replace(/\/+$/, "");
    return value === "" ? "/" : value;
}

function getInitialPath() {
    const datasetPath = normalizePath(appEl?.dataset.page || "");
    if (datasetPath !== "/") {
        return datasetPath.startsWith("/") ? datasetPath : `/${datasetPath}`;
    }

    return normalizePath(window.location.pathname);
}

function getPathForRoute(route) {
    switch (route?.kind) {
        case "publicSdk":
            return WEB.publicSdk;
        case "publicSdkBackend":
            return WEB.publicSdkBackend;
        case "publicSdkTutorial":
            return `${WEB.publicSdk}/tutorials/${encodeURIComponent(route.id || "")}`;
        case "dashboard":
            return WEB.dashboard;
        case "clients":
            return WEB.clients;
        case "users":
            return WEB.users;
        case "sandbox":
            return WEB.sandbox;
        case "client":
            return `${WEB.clients}/${encodeURIComponent(route.id || "")}`;
        case "policies":
            return WEB.policies;
        case "policy":
            return `${WEB.policies}/${encodeURIComponent(route.id || "")}`;
        case "project":
            return `${WEB.projects}/${encodeURIComponent(route.id || "")}`;
        case "sessions":
            return WEB.sessions;
        case "audit":
            return WEB.audit;
        case "users":
            return WEB.users;
        case "operations":
            return WEB.operations;
        case "telemetry":
            return WEB.telemetry;
        default:
            return WEB.status;
    }
}

function setCsrfToken(token) {
    const next = String(token || "").trim();
    if (!next) {
        return;
    }

    state.csrfToken = next;
    if (csrfMeta) {
        csrfMeta.setAttribute("content", next);
    }
}

async function requestJson(url, options = {}) {
    const method = String(options.method || "GET").toUpperCase();
    const headers = new Headers(options.headers || {});
    headers.set("Accept", "application/json");
    headers.set("X-Requested-With", "XMLHttpRequest");

    if (method !== "GET" && state.csrfToken) {
        headers.set("X-CSRF-TOKEN", state.csrfToken);
    }

    const response = await fetch(url, {
        credentials: "same-origin",
        ...options,
        method,
        headers,
    });

    const text = await response.text();
    let data = null;

    if (text) {
        try {
            data = JSON.parse(text);
        } catch {
            data = null;
        }
    }

    return { response, data };
}

function showToast(message, options = {}) {
    if (!state.ui.toast || !message) {
        return;
    }

    state.ui.toast.show(String(message), {
        title: options.title || "PBB Realtime",
        type: options.type || "info",
    });
}

function renderNavbar() {
    const host = document.getElementById("navbarHost");
    if (!host || !state.ui.navbar) {
        return;
    }

    const isPublicSdkRoute = isPublicSdkRouteKind();

    const adminOnlyItems = [];
    if (isCurrentUserAdmin()) {
        adminOnlyItems.push({ id: WEB.users, label: "Users" });
    }

    const items = isPublicSdkRoute
        ? [
            { id: WEB.publicSdk, label: "SDK Docs" },
            { id: WEB.publicSdkBackend, label: "Backend SDK" },
        ]
        : (state.route.kind === "status" && !state.account)
            ? []
            : [
            { id: WEB.dashboard, label: "Dashboard" },
            { id: WEB.clients, label: "Clients" },
            ...adminOnlyItems,
            { id: WEB.sdk, label: "SDK" },
            { id: WEB.sandbox, label: "Sandbox" },
            { id: WEB.presenceInspector, label: "Presence" },
            { id: WEB.sessions, label: "Sessions" },
            { id: WEB.audit, label: "Audit" },
            ...(isCurrentUserAdmin()
                ? [
                    { id: WEB.operations, label: "Operations" },
                    { id: WEB.telemetry, label: "Telemetry" },
                ]
                : []),
            ];

    const actions = [];

    if (isPublicSdkRoute && state.account) {
        actions.push({
            id: "open-admin",
            label: "Open Admin",
        });
    } else if (state.route.kind === "status" && !state.account) {
        actions.push({
            id: "login",
            label: "Sign In",
        });
    } else if (!state.account && state.route.kind !== "login") {
        actions.push({
            id: "login",
            label: "Login",
        });
    }

    if (state.account) {
        if (isCurrentUserAdmin()) {
            actions.push({
                id: "settings",
                label: "Settings",
                ariaLabel: "Open Realtime settings",
                iconOnly: true,
                icon: createNavbarActionIcon("actions.options", {
                    title: "Realtime settings",
                    ariaLabel: "Realtime settings",
                }),
            });
        }

        actions.push({
            id: "user",
            label: state.account.name || "Account",
            menuItems: [
                { id: "account", label: "Account" },
                { id: "change-password", label: "Change Password" },
                { id: "logout", label: "Logout" },
            ],
        });
    }

    state.ui.navbar(host, {}, {
        brandText: "PBB Realtime",
        brandSubtitle: isPublicSdkRoute ? "Public SDK reference" : "Private admin surface",
        sticky: true,
        items,
        actions,
        activeId: getActiveNavId(),
        onNavigate(item) {
            if (item?.id) {
                navigateShell(item.id);
            }
        },
        onAction(action) {
            if (action.id === "login") {
                startLoginFlow();
            } else if (action.id === "open-admin") {
                navigateShell(WEB.dashboard);
            } else if (action.id === "settings") {
                void openRealtimeSettingsModal();
            }
        },
        onActionMenuSelect(action, menuItem) {
            if (action.id !== "user") {
                return;
            }

            if (menuItem.id === "account") {
                void openAccountModal();
            } else if (menuItem.id === "change-password") {
                void openChangePasswordModal();
            } else if (menuItem.id === "logout") {
                void logout();
            }
        },
    });

}

function createNavbarActionIcon(name, options = {}) {
    try {
        const icon = state.ui.icons?.createIcon?.(name, options);
        return icon?.outerHTML || "";
    } catch {
        return "";
    }
}

function getActiveNavId() {
    switch (state.route.kind) {
        case "publicSdk":
        case "publicSdkTutorial":
            return WEB.publicSdk;
        case "publicSdkBackend":
            return WEB.publicSdkBackend;
        case "dashboard":
            return WEB.dashboard;
        case "clients":
        case "client":
        case "project":
        case "policy":
        case "policies":
            return WEB.clients;
        case "users":
            return WEB.users;
        case "sandbox":
            return WEB.sandbox;
        case "sdk":
        case "sdkBackend":
        case "sdkTutorial":
            return WEB.sdk;
        case "sessions":
            return WEB.sessions;
        case "audit":
            return WEB.audit;
        case "operations":
            return WEB.operations;
        default:
            return WEB.status;
    }
}

async function renderCurrentPage() {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) {
        return;
    }

    if (state.route.kind === "status") {
        renderStatusPage();
        return;
    }

    if (["publicSdk", "publicSdkBackend", "publicSdkTutorial"].includes(state.route.kind)) {
        if (state.route.kind === "publicSdk") {
            state.pageData = { kind: "publicSdk", data: null };
            renderSdkPage();
            return;
        }

        if (state.route.kind === "publicSdkBackend") {
            state.pageData = { kind: "publicSdkBackend", data: null };
            renderSdkBackendPage();
            return;
        }

        state.pageData = { kind: "publicSdkTutorial", data: null };
        renderSdkTutorialPage(state.route.id);
        return;
    }

    if (!state.authenticated || !state.account) {
        redirectToLogin();
        return;
    }

    if (["users", "operations", "telemetry"].includes(state.route.kind) && !isCurrentUserAdmin()) {
        navigateShell(WEB.dashboard);
        return;
    }

    renderLoadingState(pageHost, "Loading page data...");

    try {
        if (state.route.kind !== "sandbox") {
            destroySandboxRuntime();
        }

        if (state.route.kind !== "presenceInspector") {
            destroyPresenceInspectorRuntime();
        }

        if (state.route.kind === "dashboard") {
            const data = await fetchPageData(API.dashboard);
            if (!data) return;
            state.pageData = { kind: "dashboard", data };
            renderDashboardPage(data);
            return;
        }

        if (state.route.kind === "clients") {
            const page = getCurrentListPage();
            const data = await fetchPageData(`${API.clients}?page=${page}`);
            if (!data) return;
            state.pageData = { kind: "clients", data };
            renderClientsIndexPage(data);
            return;
        }

        if (state.route.kind === "users") {
            const page = getCurrentListPage();
            const data = await fetchPageData(`${API.users}?page=${page}`);
            if (!data) return;
            state.pageData = { kind: "users", data };
            renderUsersPage(data);
            return;
        }

        if (state.route.kind === "client") {
            const data = await fetchPageData(`${API.clients}/${encodeURIComponent(state.route.id)}`);
            if (!data) return;
            state.pageData = { kind: "client", data };
            renderClientDetailPage(data);
            return;
        }

        if (state.route.kind === "sdk") {
            state.pageData = { kind: "sdk", data: null };
            renderSdkPage();
            return;
        }

        if (state.route.kind === "sdkBackend") {
            state.pageData = { kind: "sdkBackend", data: null };
            renderSdkBackendPage();
            return;
        }

        if (state.route.kind === "sdkTutorial") {
            state.pageData = { kind: "sdkTutorial", data: null };
            renderSdkTutorialPage(state.route.id);
            return;
        }

        if (state.route.kind === "sandbox") {
            const data = await fetchPageData(API.sandboxContext);
            if (!data) return;
            state.pageData = { kind: "sandbox", data };
            renderSandboxPage(data);
            return;
        }

        if (state.route.kind === "presenceInspector") {
            const data = await fetchPageData(API.sandboxContext);
            if (!data) return;
            state.pageData = { kind: "presenceInspector", data };
            renderPresenceInspectorPage(data);
            return;
        }

        if (state.route.kind === "policies") {
            navigateShell(WEB.clients);
            return;
        }

        if (state.route.kind === "policy") {
            const data = await fetchPageData(`${API.policies}/${encodeURIComponent(state.route.id)}`);
            if (!data) return;
            state.pageData = { kind: "policy", data };
            renderPolicyDetailPage(data);
            return;
        }

        if (state.route.kind === "projects") {
            navigateShell(WEB.clients);
            return;
        }

        if (state.route.kind === "project") {
            const data = await fetchPageData(`${API.projects}/${encodeURIComponent(state.route.id)}`);
            if (!data) return;
            state.pageData = { kind: "project", data };
            renderProjectDetailPage(data);
            return;
        }

        if (state.route.kind === "sessions") {
            const page = getCurrentListPage();
            const data = await fetchPageData(`${API.sessions}?page=${page}`);
            if (!data) return;
            state.pageData = { kind: "sessions", data };
            renderSessionsPage(data);
            return;
        }

        if (state.route.kind === "audit") {
            const page = getCurrentListPage();
            const data = await fetchPageData(`${API.audit}?page=${page}`);
            if (!data) return;
            state.pageData = { kind: "audit", data };
            renderAuditPage(data);
            return;
        }

        if (state.route.kind === "operations") {
            const data = await fetchPageData(API.operations);
            if (!data) return;
            state.pageData = { kind: "operations", data };
            renderOperationsPage(data);
            return;
        }

        if (state.route.kind === "telemetry") {
            const data = await fetchPageData(API.telemetry);
            if (!data) return;
            state.pageData = { kind: "telemetry", data };
            renderTelemetryPage(data);
            return;
        }

        renderNotFoundPage();
    } catch (error) {
        renderErrorPage(error);
    }
}

async function fetchPageData(url) {
    const { response, data } = await requestJson(url);

    if (!response.ok) {
        if (response.status === 401 || response.status === 419) {
            await handleSessionExpiry();
            return null;
        }

        throw new Error(data?.message || "Unable to load page data.");
    }

    return data?.data || null;
}

function renderLoadingState(container, message) {
    if (!container || !state.ui.skeleton) {
        return;
    }

    container.innerHTML = `
        <section class="panel panel-stack page-shell page-shell-top">
            <div class="page-head">
                <div>
                    <p class="eyebrow">Loading</p>
                    <h1 class="page-title">${escapeHtml(message)}</h1>
                </div>
            </div>
            <div id="loadingSkeleton"></div>
        </section>
    `;

    const skeletonHost = document.getElementById("loadingSkeleton");
    if (skeletonHost) {
        state.ui.skeleton(skeletonHost, { lines: 4 }, { variant: "lines", lines: 4 });
    }
}

function renderDashboardPage(data) {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    const counts = data?.counts || {};
    const sessionSummary = data?.sessionSummary || {};
    const recentProjects = Array.isArray(data?.recentProjects) ? data.recentProjects : [];
    const isAdmin = isCurrentUserAdmin();
    const assignedClients = Array.isArray(state.account?.assigned_clients) ? state.account.assigned_clients : [];
    const clientCardLabel = isAdmin ? "Clients" : "Visible clients";
    const clientCardCaption = isAdmin
        ? "All registered clients visible to admins"
        : `${assignedClients.length} assigned client(s) available to this account`;
    const projectCardCaption = isAdmin
        ? "Project scopes across all visible clients"
        : "Project scopes across your assigned clients";

    pageHost.innerHTML = `
        <div class="dashboard-page">
        <section class="panel panel-stack">
            <div class="page-head">
                <div>
                    <p class="eyebrow">Admin Surface</p>
                    <h1 class="page-title">Operator dashboard</h1>
                    <p class="page-lede">
                        Private control surface for client registration, policy visibility, session monitoring,
                        audit review, and incident operations.
                    </p>
                </div>
                <div class="grid-actions">
                    <button class="button" id="openSandboxButton" type="button">Open sandbox</button>
                    <button class="button-secondary" id="openPresenceButton" type="button">Open presence</button>
                    <button class="button-secondary" id="openSdkButton" type="button">Open SDK</button>
                </div>
            </div>

            <div class="field-grid four">
                ${renderStatCard(clientCardLabel, counts.clients ?? 0, clientCardCaption)}
                ${renderStatCard("Project scopes", counts.projects ?? 0, projectCardCaption)}
                ${renderStatCard("Connected sessions", sessionSummary.connected ?? 0, `${sessionSummary.disconnected ?? 0} disconnected, ${counts.sessions ?? 0} total`)}
                ${renderStatCard("Audit events", counts.audit ?? 0, "Recent operator and policy activity")}
            </div>
        </section>

        <section class="field-grid two dashboard-secondary-grid">
            <article class="panel panel-stack">
                <div>
                    <p class="eyebrow">Quick actions</p>
                    <h2 class="page-title" style="font-size: 1.4rem;">Start here</h2>
                </div>
                ${renderDashboardQuickActions()}
            </article>

            <article class="panel panel-stack">
                <div>
                    <p class="eyebrow">Projects</p>
                    <h2 class="page-title" style="font-size: 1.4rem;">Recent project scopes</h2>
                </div>
                ${renderProjectMiniList(recentProjects)}
            </article>
        </section>
        </div>
    `;

    document.getElementById("openSandboxButton")?.addEventListener("click", () => {
        navigateShell(WEB.sandbox);
    });

    document.getElementById("openPresenceButton")?.addEventListener("click", () => {
        navigateShell(WEB.presence);
    });

    document.getElementById("openSdkButton")?.addEventListener("click", () => {
        navigateShell(WEB.sdk);
    });

    pageHost.querySelectorAll("[data-dashboard-nav]").forEach((button) => {
        button.addEventListener("click", () => {
            const target = String(button.getAttribute("data-dashboard-nav") || "").trim();
            if (!target) return;
            navigateShell(target);
        });
    });

    pageHost.querySelectorAll("[data-project-open]").forEach((button) => {
        button.addEventListener("click", () => {
            const projectId = String(button.getAttribute("data-project-open") || "").trim();
            if (!projectId) return;
            navigateShell(`${WEB.projects}/${encodeURIComponent(projectId)}`);
        });
    });

    pageHost.querySelectorAll("[data-project-edit]").forEach((button) => {
        button.addEventListener("click", () => {
            const projectId = String(button.getAttribute("data-project-edit") || "").trim();
            if (!projectId) return;
            void openProjectModal("edit", projectId);
        });
    });

}

function renderDashboardQuickActions() {
    const actions = [
        {
            title: "Sandbox",
            description: "Open a live peer terminal for chat, presence, and call testing.",
            target: WEB.sandbox,
            label: "Open sandbox",
        },
        {
            title: "Presence",
            description: "Inspect who is currently present inside a selected room.",
            target: WEB.presence,
            label: "Open presence",
        },
        {
            title: "Clients",
            description: "Go directly to client-owned trust, policy, and scope management.",
            target: WEB.clients,
            label: "Open clients",
        },
        {
            title: "SDK",
            description: "Open the frontend and backend SDK docs and reference flows.",
            target: WEB.sdk,
            label: "Open SDK",
        },
    ];

    return `
        <div class="dashboard-quick-grid">
            ${actions.map((action) => `
                <article class="panel dashboard-quick-card">
                    <div class="dashboard-quick-copy">
                        <strong>${escapeHtml(action.title)}</strong>
                        <div class="muted tiny">${escapeHtml(action.description)}</div>
                    </div>
                    <button class="button-secondary" type="button" data-dashboard-nav="${escapeHtml(action.target)}">${escapeHtml(action.label)}</button>
                </article>
            `).join("")}
        </div>
    `;
}

function renderClientsIndexPage(data) {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    const items = Array.isArray(data?.items) ? data.items : [];
    const meta = data?.meta || {};
    const clientsGridHostId = "clientsGridHost";
    const clientNameWidth = measureStackedColumnWidth(items, {
        label: "Name",
        min: 240,
        max: 420,
        primary: (row) => row?.name || row?.client_code || "Client",
        secondary: (row) => row?.client_code || "",
    });
    const projectCountWidth = measureColumnWidth(items, {
        label: "Projects",
        min: 120,
        max: 160,
        value: (row) => row?.project_count ?? "",
    });
    const statusWidth = measureColumnWidth(items, {
        label: "Status",
        min: 120,
        max: 160,
        value: (row) => row?.status || "",
        charWidth: 9,
    });
    const issuanceWidth = measureColumnWidth(items, {
        label: "Token issuance",
        min: 220,
        max: 320,
        value: (row) => row?.token_issuance_mode || "",
        charWidth: 8.6,
    });
    const clientActionsWidth = 180;

    state.ui.clientsGrid?.destroy?.();
    state.ui.clientsGrid = null;

    if (!items.length && !isCurrentUserAdmin()) {
        pageHost.innerHTML = `
            <section class="panel panel-stack page-shell page-shell-fill">
                <div class="page-head">
                    <div>
                        <p class="eyebrow">Clients</p>
                        <h1 class="page-title">Client and trust management</h1>
                        <p class="page-lede">Define who may connect to the realtime gateway and which policies apply.</p>
                    </div>
                </div>
                ${renderEmptyStateHtml(
                    "No assigned clients",
                    "This account does not currently have any client assignments. Ask an admin to assign one or more clients so records become visible here."
                )}
            </section>
        `;
        return;
    }

    pageHost.innerHTML = `
        <section class="panel panel-stack page-shell page-shell-fill">
            <div class="page-head">
                <div>
                    <p class="eyebrow">Clients</p>
                    <h1 class="page-title">Client and trust management</h1>
                    <p class="page-lede">Define who may connect to the realtime gateway and which policies apply.</p>
                </div>
                ${isCurrentUserAdmin() ? `
                    <div class="actions">
                        <button class="button" id="openNewClientButton" type="button">New client</button>
                    </div>
                ` : ""}
            </div>
            <div id="${clientsGridHostId}" class="clients-grid-host" aria-label="Clients grid"></div>
            ${renderPager(meta, WEB.clients)}
        </section>
    `;

    document.getElementById("openNewClientButton")?.addEventListener("click", () => {
        void openClientModal("create");
    });

    const clientGridHost = document.getElementById(clientsGridHostId);
    if (clientGridHost && state.ui.grid) {
        state.ui.clientsGrid = state.ui.grid(clientGridHost, items, {
            mode: "local",
            className: "clients-grid",
            chrome: false,
            columns: [
                {
                    key: "name",
                    label: "Name",
                    sortable: true,
                    resizable: true,
                    width: clientNameWidth,
                    renderCell: ({ row }) => {
                        const host = document.createElement("div");
                        host.className = "grid-stacked";
                        const title = document.createElement("strong");
                        title.textContent = String(row?.name || row?.client_code || "Client");
                        const subtext = document.createElement("div");
                        subtext.className = "muted tiny";
                        subtext.textContent = String(row?.client_code || "");
                        host.append(title, subtext);
                        return host;
                    },
                },
                {
                    key: "project_count",
                    label: "Projects",
                    sortable: true,
                    resizable: true,
                    width: projectCountWidth,
                    align: "center",
                },
                {
                    key: "status",
                    label: "Status",
                    sortable: true,
                    resizable: true,
                    width: statusWidth,
                    renderCell: ({ value }) => renderBadgeElement(value),
                },
                {
                    key: "token_issuance_mode",
                    label: "Token issuance",
                    sortable: true,
                    resizable: true,
                    width: issuanceWidth,
                },
                {
                    key: "actions",
                    label: "Actions",
                    sortable: false,
                    resizable: true,
                    width: clientActionsWidth,
                    align: "right",
                    renderCell: ({ row }) => {
                        const host = document.createElement("div");
                        host.className = "grid-actions";

                        const viewButton = document.createElement("button");
                        viewButton.type = "button";
                        viewButton.className = "button-secondary tiny";
                        viewButton.textContent = "View";
                        viewButton.addEventListener("click", (event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            navigateShell(`${WEB.clients}/${encodeURIComponent(String(row.client_code || "").trim())}`);
                        });

                        const editButton = document.createElement("button");
                        editButton.type = "button";
                        editButton.className = "button-secondary tiny";
                        editButton.textContent = "Edit";
                        editButton.addEventListener("click", (event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            void openClientModal("edit", String(row.client_code || "").trim());
                        });

                        host.append(viewButton, editButton);
                        return host;
                    },
                },
            ],
            selectable: "none",
            wrapCellContent: false,
            enableSort: true,
            enableSearch: false,
            enablePagination: false,
            enableColumnResize: true,
            onRowClick(row) {
                const clientCode = String(row.client_code || "").trim();
                if (!clientCode) return;
                navigateShell(`${WEB.clients}/${encodeURIComponent(clientCode)}`);
            },
            onColumnResize() {
                // The helper grid persists column widths internally while the page remains mounted.
            },
        });
    }

    pageHost.querySelectorAll("[data-page-nav]").forEach((button) => {
        button.addEventListener("click", () => {
            const target = String(button.getAttribute("data-page-nav") || "").trim();
            if (!target) return;
            navigateShell(target);
        });
    });

    pageHost.querySelectorAll("[data-client-open]").forEach((button) => {
        button.addEventListener("click", () => {
            const clientId = String(button.getAttribute("data-client-open") || "").trim();
            if (!clientId) return;
            navigateShell(`${WEB.clients}/${encodeURIComponent(clientId)}`);
        });
    });

    pageHost.querySelectorAll("[data-client-edit]").forEach((button) => {
        button.addEventListener("click", () => {
            const clientId = String(button.getAttribute("data-client-edit") || "").trim();
            if (!clientId) return;
            void openClientModal("edit", clientId);
        });
    });
}

function renderUsersPage(data) {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    const items = Array.isArray(data?.items) ? data.items : [];
    const meta = data?.meta || {};
    const usersGridHostId = "usersGridHost";
    const nameWidth = measureStackedColumnWidth(items, {
        label: "User",
        min: 240,
        max: 360,
        primary: (row) => row?.name || row?.email || "User",
        secondary: (row) => row?.email || "",
    });
    const roleWidth = measureColumnWidth(items, {
        label: "Role",
        min: 120,
        max: 160,
        value: (row) => row?.user_type || "",
    });
    const assignedWidth = measureColumnWidth(items, {
        label: "Assigned clients",
        min: 120,
        max: 180,
        value: (row) => row?.assigned_client_count ?? 0,
    });
    const accessWidth = measureColumnWidth(items, {
        label: "Access",
        min: 140,
        max: 180,
        value: (row) => row?.is_operator ? "admin-surface" : "disabled",
    });

    state.ui.usersGrid?.destroy?.();
    state.ui.usersGrid = null;

    pageHost.innerHTML = `
        <section class="panel panel-stack page-shell page-shell-fill">
            <div class="page-head">
                <div>
                    <p class="eyebrow">Users</p>
                    <h1 class="page-title">User and client access management</h1>
                    <p class="page-lede">Admins can grant client-scoped access to regular users and retain global access for admins.</p>
                </div>
                <div class="actions">
                    <button class="button" id="openNewUserButton" type="button">New user</button>
                </div>
            </div>
            <div id="${usersGridHostId}" class="page-grid-host" aria-label="Users grid"></div>
            ${renderPager(meta, WEB.users)}
        </section>
    `;

    document.getElementById("openNewUserButton")?.addEventListener("click", () => {
        void openUserModal("create");
    });

    const host = document.getElementById(usersGridHostId);
    if (host && state.ui.grid) {
        state.ui.usersGrid = state.ui.grid(host, items, {
            mode: "local",
            className: "users-grid",
            chrome: false,
            columns: [
                {
                    key: "name",
                    label: "User",
                    sortable: true,
                    resizable: true,
                    width: nameWidth,
                    renderCell: ({ row }) => {
                        const shell = document.createElement("div");
                        shell.className = "grid-stacked";
                        const title = document.createElement("strong");
                        title.textContent = String(row?.name || row?.email || "User");
                        const subtext = document.createElement("div");
                        subtext.className = "muted tiny";
                        subtext.textContent = String(row?.email || "");
                        shell.append(title, subtext);
                        return shell;
                    },
                },
                {
                    key: "user_type",
                    label: "Role",
                    sortable: true,
                    resizable: true,
                    width: roleWidth,
                    renderCell: ({ value }) => renderBadgeElement(value || "regular"),
                },
                {
                    key: "assigned_client_count",
                    label: "Assigned clients",
                    sortable: true,
                    resizable: true,
                    width: assignedWidth,
                    align: "center",
                },
                {
                    key: "access",
                    label: "Access",
                    sortable: false,
                    resizable: true,
                    width: accessWidth,
                    renderCell: ({ row }) => renderBadgeElement(row?.is_operator ? "admin-surface" : "disabled"),
                },
                {
                    key: "assignments",
                    label: "Client assignments",
                    sortable: false,
                    resizable: true,
                    width: 320,
                    renderCell: ({ row }) => {
                        const shell = document.createElement("div");
                        shell.className = "grid-stacked";
                        const assignments = Array.isArray(row?.assigned_clients) ? row.assigned_clients : [];
                        if (row?.is_admin) {
                            const title = document.createElement("strong");
                            title.textContent = "All clients";
                            const note = document.createElement("div");
                            note.className = "muted tiny";
                            note.textContent = "Admin access is global.";
                            shell.append(title, note);
                            return shell;
                        }

                        if (!assignments.length) {
                            const empty = document.createElement("div");
                            empty.className = "muted tiny";
                            empty.textContent = "No clients assigned.";
                            shell.append(empty);
                            return shell;
                        }

                        assignments.slice(0, 3).forEach((client) => {
                            const line = document.createElement("div");
                            line.className = "muted tiny";
                            line.textContent = `${client.name || client.client_code || "Client"} (${client.client_code || ""})`;
                            shell.append(line);
                        });

                        if (assignments.length > 3) {
                            const more = document.createElement("div");
                            more.className = "muted tiny";
                            more.textContent = `+${assignments.length - 3} more`;
                            shell.append(more);
                        }

                        return shell;
                    },
                },
                {
                    key: "actions",
                    label: "Actions",
                    sortable: false,
                    resizable: true,
                    width: 190,
                    align: "right",
                    renderCell: ({ row }) => {
                        const shell = document.createElement("div");
                        shell.className = "grid-actions";

                        const auditButton = document.createElement("button");
                        auditButton.type = "button";
                        auditButton.className = "button-secondary tiny";
                        auditButton.textContent = "Audit";
                        auditButton.addEventListener("click", (event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            void openUserAuditModal(row?.id);
                        });

                        const editButton = document.createElement("button");
                        editButton.type = "button";
                        editButton.className = "button-secondary tiny";
                        editButton.textContent = "Edit";
                        editButton.addEventListener("click", (event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            void openUserModal("edit", row?.id);
                        });

                        shell.append(auditButton, editButton);
                        return shell;
                    },
                },
            ],
            selectable: "none",
            wrapCellContent: false,
            enableSort: true,
            enableSearch: false,
            enablePagination: false,
            enableColumnResize: true,
        });
    }

    pageHost.querySelectorAll("[data-page-nav]").forEach((button) => {
        button.addEventListener("click", () => {
            const target = String(button.getAttribute("data-page-nav") || "").trim();
            if (!target) return;
            navigateShell(target);
        });
    });
}

async function openUserAuditModal(userId) {
    const normalizedId = String(userId || "").trim();
    if (!normalizedId) {
        return;
    }

    const payload = await fetchRecordJson(`${API.userAudit}/${encodeURIComponent(normalizedId)}/audit`, "user audit");
    const user = payload?.user || {};
    const events = Array.isArray(payload?.events) ? payload.events : [];

    const host = document.createElement("div");
    host.className = "sdk-doc-modal-body";
    host.innerHTML = `
        <section class="panel panel-stack">
            <div>
                <p class="eyebrow">User Audit</p>
                <h2 class="section-title">${escapeHtml(user.name || user.email || "User")}</h2>
                <p class="page-lede">${escapeHtml(user.email || "")}</p>
            </div>
            ${events.length ? `
                <div class="notes-list">
                    ${events.map((event) => `
                        <article class="panel panel-stack">
                            <div class="row">
                                <strong>${escapeHtml(formatLabel(event.action_type || "change"))}</strong>
                                <span class="muted tiny">${escapeHtml(formatDateTime(event.occurred_at))}</span>
                            </div>
                            <div class="muted tiny">Actor: ${escapeHtml(event.actor_identity || "system")}</div>
                            ${event.reason ? `<div class="muted tiny">Reason: ${escapeHtml(event.reason)}</div>` : ""}
                            <div class="code-block"><pre>${escapeHtml(JSON.stringify({
                                before: event.before_state || null,
                                after: event.after_state || null,
                            }, null, 2))}</pre></div>
                        </article>
                    `).join("")}
                </div>
            ` : renderEmptyStateHtml("No user audit yet", "No user-management changes have been recorded for this account.") }
        </section>
    `;

    const modal = state.ui.actionModal({
        title: "User audit",
        size: "xl",
        className: "sdk-doc-modal",
        content: host,
        actions: [
            {
                id: "close",
                label: "Close",
                variant: "primary",
            },
        ],
    });

    const result = modal.open();
    if (result && typeof result.then === "function") {
        await result;
    }
}

function renderClientDetailPage(data) {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    const client = data?.client || {};
    const policies = Array.isArray(client.policies) ? client.policies : [];
    const projects = Array.isArray(client.projects) ? client.projects : [];
    pageHost.dataset.clientCode = String(client.client_code || "").trim();

    pageHost.innerHTML = `
        <section class="panel page-shell page-shell-fill detail-page-shell">
            <div class="page-head">
                <div>
                    <p class="eyebrow">Clients</p>
                    <h1 class="page-title">${escapeHtml(client.name || client.client_code || "Client")}</h1>
                    <div class="muted tiny">${escapeHtml(client.client_code || "")}</div>
                    <p class="page-lede">Detailed view for the registered realtime client and its trust metadata.</p>
                </div>
                <div class="actions">
                    <button class="button-secondary" id="backToClientsButton" type="button">Back to list</button>
                    <button class="button" id="editClientButton" type="button">Edit</button>
                    <button class="button-danger" id="disableClientButton" type="button">Disable</button>
                </div>
            </div>

            <div class="detail-page-body client-detail-page-body">
                <section class="detail-column detail-column-meta">
                    ${renderDetailCard("Summary", [
                        ["Status", renderBadge(client.status)],
                        ["Token issuance", escapeHtml(client.token_issuance_mode)],
                        ["Project scopes", escapeHtml(String(client.project_count ?? projects.length ?? 0))],
                    ], true)}
                    ${renderDetailCard("Trust", [
                        ["Issuer identity", escapeHtml(client.issuer_identity)],
                        ["Trusted signing profile", escapeHtml(client.trusted_signing_profile)],
                    ], true)}
                    ${renderDetailCard("Notes", [
                        ["Summary", client.description],
                        ["Integration owner", client.integration_owner],
                        ["Integration notes", client.integration_notes],
                        ["Trust notes", client.trust_notes],
                    ])}
                </section>

                <section class="panel detail-card detail-scroll-surface">
                    <div class="row">
                        <div>
                            <p class="eyebrow">Policies</p>
                            <div class="muted tiny">${escapeHtml(String(client.policy_count ?? policies.length ?? 0))} registered</div>
                        </div>
                        <div class="grid-actions">
                            <button class="button-secondary tiny" id="newPolicyButton" type="button">New policy</button>
                        </div>
                    </div>
                    <div class="detail-scroll-body">
                        ${renderPolicyDetailList(policies)}
                    </div>
                </section>

                <section class="panel detail-card detail-scroll-surface">
                    <div class="row">
                        <div>
                            <p class="eyebrow">Project scopes</p>
                            <div class="muted tiny">${escapeHtml(String(projects.length))} registered</div>
                        </div>
                        <div class="grid-actions">
                            <button class="button-secondary tiny" id="newProjectButton" type="button">New project scope</button>
                        </div>
                    </div>
                    <div class="detail-scroll-body">
                        ${renderProjectDetailList(projects)}
                    </div>
                </section>
            </div>
        </section>
    `;

    hydrateActionIcons(pageHost);

    document.getElementById("backToClientsButton")?.addEventListener("click", () => {
        navigateShell(WEB.clients);
    });

    document.getElementById("newProjectButton")?.addEventListener("click", () => {
        const clientId = getClientDatabaseId(client);
        if (!clientId) return;
        void openProjectModal("create", null, {
            id: clientId,
            label: `${String(client.name || client.client_code || "Client").trim()} (${String(client.client_code || "").trim()})`,
        });
    });

    document.getElementById("newPolicyButton")?.addEventListener("click", () => {
        const clientId = getClientDatabaseId(client);
        if (!clientId) return;
        void openPolicyModal("create", null, {
            id: clientId,
            code: String(client.client_code || "").trim(),
            label: `${String(client.name || client.client_code || "Client").trim()} (${String(client.client_code || "").trim()})`,
            name: String(client.name || client.client_code || "Client").trim(),
        });
    });

    document.getElementById("editClientButton")?.addEventListener("click", () => {
        const clientCode = getClientRouteKey(client);
        if (!clientCode) return;
        void openClientModal("edit", clientCode);
    });

    document.getElementById("disableClientButton")?.addEventListener("click", () => {
        void deactivateClient(client);
    });

    pageHost.querySelectorAll("[data-project-open]").forEach((button) => {
        button.addEventListener("click", () => {
            const projectId = String(button.getAttribute("data-project-open") || "").trim();
            if (!projectId) return;
            navigateShell(`${WEB.projects}/${encodeURIComponent(projectId)}`);
        });
    });

    pageHost.querySelectorAll("[data-project-edit]").forEach((button) => {
        button.addEventListener("click", () => {
            const projectId = String(button.getAttribute("data-project-edit") || "").trim();
            if (!projectId) return;
            void openProjectModal("edit", projectId);
        });
    });

    pageHost.querySelectorAll("[data-project-deactivate]").forEach((button) => {
        button.addEventListener("click", () => {
            const projectId = String(button.getAttribute("data-project-deactivate") || "").trim();
            if (!projectId) return;
            const selectedProject = projects.find((item) => String(item.project_code || "").trim() === projectId);
            if (!selectedProject) return;
            void deactivateProject(selectedProject);
        });
    });

    pageHost.querySelectorAll("[data-project-reactivate]").forEach((button) => {
        button.addEventListener("click", () => {
            const projectId = String(button.getAttribute("data-project-reactivate") || "").trim();
            if (!projectId) return;
            const selectedProject = projects.find((item) => String(item.project_code || "").trim() === projectId);
            if (!selectedProject) return;
            void reactivateProject(selectedProject);
        });
    });

    pageHost.querySelectorAll("[data-policy-open]").forEach((button) => {
        button.addEventListener("click", () => {
            const policyId = String(button.getAttribute("data-policy-open") || "").trim();
            if (!policyId) return;
            navigateShell(`/admin/policies/${encodeURIComponent(policyId)}`);
        });
    });

    pageHost.querySelectorAll("[data-policy-edit]").forEach((button) => {
        button.addEventListener("click", () => {
            const policyId = String(button.getAttribute("data-policy-edit") || "").trim();
            if (!policyId) return;
            void openPolicyModal("edit", policyId, {
                id: String(client.id || "").trim(),
                code: String(client.client_code || "").trim(),
                label: `${String(client.name || client.client_code || "Client").trim()} (${String(client.client_code || "").trim()})`,
                name: String(client.name || client.client_code || "Client").trim(),
            });
        });
    });

    pageHost.querySelectorAll("[data-policy-deprecate]").forEach((button) => {
        button.addEventListener("click", () => {
            const policyId = String(button.getAttribute("data-policy-deprecate") || "").trim();
            if (!policyId) return;
            const selectedPolicy = policies.find((item) => String(item.policy_code || "").trim() === policyId);
            if (!selectedPolicy) return;
            void deprecatePolicy(selectedPolicy);
        });
    });

    pageHost.querySelectorAll("[data-policy-duplicate]").forEach((button) => {
        button.addEventListener("click", () => {
            const policyId = String(button.getAttribute("data-policy-duplicate") || "").trim();
            if (!policyId) return;
            const selectedPolicy = policies.find((item) => String(item.policy_code || "").trim() === policyId);
            if (!selectedPolicy) return;
            void duplicatePolicy(selectedPolicy, {
                id: String(client.id || "").trim(),
                code: String(client.client_code || "").trim(),
                label: `${String(client.name || client.client_code || "Client").trim()} (${String(client.client_code || "").trim()})`,
                name: String(client.name || client.client_code || "Client").trim(),
            });
        });
    });
}

function renderSdkPage() {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;
    const sdkPaths = getSdkSurfacePaths();

    const content = `
        <article class="panel panel-stack">
            <h2 class="section-title">Manual</h2>
            <dl class="detail-list">
                <div>
                    <dt>Ownership boundary</dt>
                    <dd>Realtime owns transport behavior. Product teams own business orchestration.</dd>
                </div>
                <div>
                    <dt>Current modules</dt>
                    <dd>Core transport, presence, chat, attachments, media transport, and conference helpers.</dd>
                </div>
                <div>
                    <dt>Reference consumer</dt>
                    <dd>The sandbox remains the first consumer and regression surface for the SDK.</dd>
                </div>
                <div>
                    <dt>Conference default</dt>
                    <dd>Small-group mesh with a hard default limit of 5 participants.</dd>
                </div>
            </dl>
        </article>
        <article class="panel panel-stack">
            <h2 class="section-title">Reference docs</h2>
            <ul class="notes-list">
                <li><button class="button button-ghost" type="button" data-sdk-doc="integration-guide">Open integration guide</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="hotline-reference-flow">Open Hotline reference flow</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="versioning-strategy">Open versioning strategy</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-proposal">Open backend SDK proposal</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-checklist">Open backend SDK checklist</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-quickstart">Open backend SDK quickstart</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-hotline-example">Open backend SDK Hotline example</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-arguments-reference">Open backend SDK arguments reference</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-return-values-reference">Open backend SDK return values reference</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-trust-boundary">Open backend SDK trust boundary</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-migration-guide">Open backend SDK migration guide</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="sdk-demo-app">Open SDK demo app doc</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="sdk-demo-attachments-app">Open SDK attachment demo doc</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="sdk-demo-conference-app">Open SDK conference demo doc</button></li>
            </ul>
            <div class="actions">
                <button class="button" type="button" data-sdk-download="${escapeHtml(API.backendSdkDownload)}">Download backend SDK ZIP</button>
                <button class="button button-ghost" type="button" data-sdk-download="${escapeHtml(API.demoBundleDownload)}">Download demo bundle ZIP</button>
            </div>
        </article>
        <article class="panel panel-stack">
            <h2 class="section-title">Quickstart</h2>
            <ol class="notes-list">
                <li>Obtain admission/token from the product backend.</li>
                <li>Create <code>RealtimeSocketClient</code> with websocket URL and token.</li>
                <li>Connect, join room, subscribe presence, publish presence.</li>
                <li>Layer in chat, attachments, media chunk transport, and conference only as needed.</li>
            </ol>
            <div class="actions">
                <button class="button" type="button" data-page-nav="${escapeHtml(`${sdkPaths.sdk}/tutorials/quickstart`)}">Open quickstart</button>
                <button class="button button-ghost" type="button" data-page-nav="${escapeHtml(sdkPaths.sdkBackend)}">Open backend SDK</button>
                <a class="button button-ghost" href="/sdk-demo/" target="_blank" rel="noopener">Open chat demo</a>
                <a class="button button-ghost" href="/sdk-demo-attachments/" target="_blank" rel="noopener">Open attachment demo</a>
                <a class="button button-ghost" href="/sdk-demo-conference/" target="_blank" rel="noopener">Open conference demo</a>
            </div>
        </article>
    `;

    pageHost.innerHTML = renderSdkDocsLayout({
        eyebrow: "SDK",
        title: "Realtime SDK manual",
        lede: "Use these pages as the operator-facing manual and tutorial entry point for product teams integrating the Realtime SDK.",
        activeSlug: "",
        content,
    });

    bindSdkPageEvents(pageHost);
}

function renderSdkBackendPage() {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;
    const sdkPaths = getSdkSurfacePaths();

    const content = `
        <article class="panel panel-stack">
            <h2 class="section-title">Backend SDK</h2>
            <dl class="detail-list">
                <div>
                    <dt>Purpose</dt>
                    <dd>Issue Realtime admission payloads safely from a product backend.</dd>
                </div>
                <div>
                    <dt>Packaging</dt>
                    <dd>Plain PHP first, framework-agnostic, easy to vendor into existing PBB projects.</dd>
                </div>
                <div>
                    <dt>Owns</dt>
                    <dd>Claim normalization, token signing, room derivation, and frontend-facing admission payloads.</dd>
                </div>
                <div>
                    <dt>Does not own</dt>
                    <dd>Business authorization, operator assignment, or workflow logic.</dd>
                </div>
            </dl>
        </article>
        <article class="panel panel-stack">
            <h2 class="section-title">Reference docs</h2>
            <ul class="notes-list">
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-proposal">Open backend SDK proposal</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-quickstart">Open backend SDK quickstart</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-hotline-example">Open backend SDK Hotline example</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-arguments-reference">Open backend SDK arguments reference</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-return-values-reference">Open backend SDK return values reference</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-trust-boundary">Open backend SDK trust boundary</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-migration-guide">Open backend SDK migration guide</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="backend-sdk-checklist">Open backend SDK checklist</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="sdk-demo-app">Open SDK demo app doc</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="sdk-demo-attachments-app">Open SDK attachment demo doc</button></li>
                <li><button class="button button-ghost" type="button" data-sdk-doc="sdk-demo-conference-app">Open SDK conference demo doc</button></li>
            </ul>
            <div class="actions">
                <button class="button" type="button" data-sdk-download="${escapeHtml(API.backendSdkDownload)}">Download backend SDK ZIP</button>
                <button class="button button-ghost" type="button" data-sdk-download="${escapeHtml(API.demoBundleDownload)}">Download demo bundle ZIP</button>
            </div>
        </article>
        <article class="panel panel-stack">
            <h2 class="section-title">Reference files</h2>
            <ul class="notes-list">
                <li><a href="#" data-sdk-doc="backend-sdk-quickstart">Backend quickstart</a></li>
                <li><code>sdk/php/pbb_realtime_backend_sdk.php</code></li>
                <li><code>sdk/php/examples/admission-endpoint.php</code></li>
                <li><code>sdk/php/examples/chat-terminal-admission.php</code></li>
                <li><code>sdk/php/examples/operator-console-admission.php</code></li>
                <li><code>sdk/php/examples/conference-admission.php</code></li>
                <li><code>public/sdk-demo/index.php</code></li>
                <li><code>public/sdk-demo/admission.php</code></li>
                <li><code>public/sdk-demo/app.js</code></li>
                <li><code>public/sdk-demo-attachments/index.php</code></li>
                <li><code>public/sdk-demo-conference/index.php</code></li>
            </ul>
            <div class="actions">
                <a class="button button-ghost" href="/sdk-demo/" target="_blank" rel="noopener">Open chat demo</a>
                <a class="button button-ghost" href="/sdk-demo-attachments/" target="_blank" rel="noopener">Open attachment demo</a>
                <a class="button button-ghost" href="/sdk-demo-conference/" target="_blank" rel="noopener">Open conference demo</a>
            </div>
        </article>
    `;

    pageHost.innerHTML = renderSdkDocsLayout({
        eyebrow: "Backend SDK",
        title: "Realtime backend SDK",
        lede: "Use this page when a product backend needs to issue trusted Realtime admission for the frontend SDK.",
        activeSlug: "backend",
        content,
        actions: `
            <button class="button button-ghost" type="button" data-page-nav="${escapeHtml(sdkPaths.sdk)}">Back to SDK</button>
        `,
    });

    bindSdkPageEvents(pageHost);
}

function renderSdkTutorialPage(slug) {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;
    const sdkPaths = getSdkSurfacePaths();

    const tutorial = SDK_TUTORIALS[String(slug || "").trim()] || SDK_TUTORIALS.quickstart;
    const sections = tutorial.sections.map((section) => `
        <article class="panel panel-stack">
            <h2 class="section-title">${escapeHtml(section.title)}</h2>
            ${Array.isArray(section.bullets) && section.bullets.length > 0 ? `
                <ul class="notes-list">
                    ${section.bullets.map((item) => `<li>${escapeHtml(item)}</li>`).join("")}
                </ul>
            ` : ""}
            ${section.code ? `
                <div class="code-block"><pre>${escapeHtml(section.code)}</pre></div>
            ` : ""}
            ${Array.isArray(section.args) && section.args.length > 0 ? `
                <div class="panel panel-stack sdk-docs-args">
                    <div>
                        <p class="eyebrow">Arguments</p>
                        <h3 class="section-title">Function inputs</h3>
                    </div>
                    <ul class="notes-list">
                        ${section.args.map((item) => `<li>${escapeHtml(item)}</li>`).join("")}
                    </ul>
                </div>
            ` : ""}
            ${Array.isArray(section.returns) && section.returns.length > 0 ? `
                <div class="panel panel-stack sdk-docs-returns">
                    <div>
                        <p class="eyebrow">Return Values</p>
                        <h3 class="section-title">Function contracts</h3>
                    </div>
                    <ul class="notes-list">
                        ${section.returns.map((item) => `<li>${escapeHtml(item)}</li>`).join("")}
                    </ul>
                </div>
            ` : ""}
        </article>
    `).join("");

    pageHost.innerHTML = renderSdkDocsLayout({
        eyebrow: "SDK Tutorial",
        title: tutorial.title,
        lede: tutorial.summary,
        activeSlug: tutorial.slug,
        content: sections,
        actions: `
            <button class="button button-ghost" type="button" data-page-nav="${escapeHtml(sdkPaths.sdk)}">Back to SDK</button>
        `,
    });

    bindSdkPageEvents(pageHost);
}

function renderSdkDocsLayout({ eyebrow, title, lede, activeSlug, content, actions = "" }) {
    return `
        <section class="panel panel-stack page-shell sdk-docs-shell">
            <div class="page-head">
                <div>
                    <p class="eyebrow">${escapeHtml(eyebrow || "SDK")}</p>
                    <h1 class="page-title">${escapeHtml(title || "Realtime SDK")}</h1>
                    <p class="page-lede">${escapeHtml(lede || "")}</p>
                </div>
                ${actions ? `<div class="actions">${actions}</div>` : ""}
            </div>
            <div class="sdk-docs-body">
                <aside class="panel panel-stack sdk-docs-nav">
                    ${renderSdkDocsNav(activeSlug)}
                </aside>
                <div class="sdk-docs-content">
                    ${content}
                </div>
            </div>
        </section>
    `;
}

function renderSdkDocsNav(activeSlug = "") {
    const active = String(activeSlug || "").trim();
    const sdkPaths = getSdkSurfacePaths();
    const tutorialLinks = Object.values(SDK_TUTORIALS).map((tutorial) => {
        const href = `${sdkPaths.sdk}/tutorials/${tutorial.slug}`;
        const isActive = tutorial.slug === active;
        return `
            <button
                type="button"
                class="sdk-docs-nav-link${isActive ? " is-active" : ""}"
                data-page-nav="${escapeHtml(href)}"
            >
                <span>${escapeHtml(tutorial.title)}</span>
                <small>${escapeHtml(tutorial.summary)}</small>
            </button>
        `;
    }).join("");

    const backendDocLinks = [
        ["Overview", null, "Backend SDK overview, reference docs, and examples.", sdkPaths.sdkBackend, active === "backend"],
        ["Backend SDK Proposal", "backend-sdk-proposal", "Backend-side admission and token strategy."],
        ["Backend SDK Quickstart", "backend-sdk-quickstart", "Minimal backend admission flow for product teams."],
        ["Hotline Example", "backend-sdk-hotline-example", "Reference backend admission shape for Hotline-style flows."],
        ["Arguments Reference", "backend-sdk-arguments-reference", "Input contract for backend SDK functions."],
        ["Return Values Reference", "backend-sdk-return-values-reference", "Output contract for backend SDK functions."],
        ["Trust Boundary", "backend-sdk-trust-boundary", "Responsibility split across frontend, backend, and Realtime."],
        ["Migration Guide", "backend-sdk-migration-guide", "How to replace hand-built token code gradually."],
    ].map((item) => {
        const [title, docId, summary, href = "", isActive = false] = item;
        if (docId) {
            return `
                <button
                    type="button"
                    class="sdk-docs-nav-link"
                    data-sdk-doc="${escapeHtml(docId)}"
                >
                    <span>${escapeHtml(title)}</span>
                    <small>${escapeHtml(summary)}</small>
                </button>
            `;
        }

        return `
            <button
                type="button"
                class="sdk-docs-nav-link${isActive ? " is-active" : ""}"
                data-page-nav="${escapeHtml(href)}"
            >
                <span>${escapeHtml(title)}</span>
                <small>${escapeHtml(summary)}</small>
            </button>
        `;
    }).join("");

    return `
        <div>
            <p class="eyebrow">SDK</p>
            <h2 class="section-title">Navigation</h2>
        </div>
        <div class="sdk-docs-nav-group">
            <p class="sdk-docs-nav-label">Manual</p>
            <button
                type="button"
                class="sdk-docs-nav-link${active ? "" : " is-active"}"
                data-page-nav="${escapeHtml(sdkPaths.sdk)}"
            >
                <span>Overview</span>
                <small>Manual, quickstart, reference docs, and tutorial index.</small>
            </button>
        </div>
        <div class="sdk-docs-nav-group">
            <p class="sdk-docs-nav-label">Tutorials</p>
            ${tutorialLinks}
        </div>
        <div class="sdk-docs-nav-group">
            <p class="sdk-docs-nav-label">Backend SDK</p>
            ${backendDocLinks}
        </div>
    `;
}

function bindSdkPageEvents(host) {
    host.querySelectorAll("[data-page-nav]").forEach((button) => {
        button.addEventListener("click", () => {
            const target = String(button.getAttribute("data-page-nav") || "").trim();
            if (target) {
                navigateShell(target);
            }
        });
    });

    host.querySelectorAll("[data-sdk-doc]").forEach((button) => {
        button.addEventListener("click", () => {
            const docId = String(button.getAttribute("data-sdk-doc") || "").trim();
            if (docId) {
                void openSdkReferenceDocModal(docId);
            }
        });
    });

    host.querySelectorAll("[data-sdk-download]").forEach((button) => {
        button.addEventListener("click", () => {
            const url = String(button.getAttribute("data-sdk-download") || "").trim();
            if (url) {
                window.location.href = url;
            }
        });
    });
}

function absolutizeMarkdownReferences(root, markdownUrl) {
    if (!root || !markdownUrl) {
        return;
    }

    root.querySelectorAll("a[href]").forEach((link) => {
        const rawHref = String(link.getAttribute("href") || "").trim();
        if (!rawHref || rawHref.startsWith("#")) {
            return;
        }
        try {
            link.href = new URL(rawHref, markdownUrl).href;
            link.target = "_blank";
            link.rel = "noopener noreferrer";
        } catch {
            // noop
        }
    });

    root.querySelectorAll("img[src]").forEach((image) => {
        const rawSrc = String(image.getAttribute("src") || "").trim();
        if (!rawSrc) {
            return;
        }
        try {
            image.src = new URL(rawSrc, markdownUrl).href;
        } catch {
            // noop
        }
    });
}

function getSdkSurfacePaths() {
    if (isPublicSdkRouteKind()) {
        return {
            sdk: WEB.publicSdk,
            sdkBackend: WEB.publicSdkBackend,
        };
    }

    return {
        sdk: WEB.sdk,
        sdkBackend: WEB.sdkBackend,
    };
}

function getSdkDocsApiBase() {
    return isPublicSdkRouteKind() ? API.publicSdkDocs : API.sdkDocs;
}

function isPublicSdkRouteKind(kind = state.route?.kind) {
    return ["publicSdk", "publicSdkBackend", "publicSdkTutorial"].includes(String(kind || "").trim());
}

async function openSdkReferenceDocModal(docId) {
    const { response, data } = await requestJson(`${getSdkDocsApiBase()}/${encodeURIComponent(String(docId || "").trim())}`);
    if (!response.ok) {
        showToast(data?.message || "Unable to load SDK reference document.", { title: "SDK", type: "error" });
        return;
    }

    const payload = data?.data || {};
    const host = document.createElement("div");
    host.className = "sdk-doc-modal-body markdown-doc";
    host.innerHTML = marked.parse(String(payload.markdown || ""));
    absolutizeMarkdownReferences(host, `${window.location.origin}${getSdkDocsApiBase()}/${encodeURIComponent(String(docId || "").trim())}`);

    const modal = state.ui.actionModal({
        title: String(payload.title || "SDK reference"),
        size: "xl",
        className: "sdk-doc-modal",
        content: host,
        actions: [
            {
                id: "close",
                label: "Close",
                variant: "primary",
            },
        ],
    });

    const result = modal.open();
    if (result && typeof result.then === "function") {
        await result;
    }
}

function renderSandboxPage(data) {
    if (state.route.kind !== "sandbox") {
        return;
    }

    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    const sandbox = state.sandbox;
    destroySandboxUiInstances();

    sandbox.context = data || {};
    ensureSandboxSelectionState();
    if (sandbox.peers.secondary?.socket || sandbox.peers.secondary?.connectionStatus === "connected") {
        disconnectSandboxPeer("secondary");
    }

    const selectedClient = getSandboxSelectedClient();
    const selectedProject = getSandboxSelectedProject();
    const attachmentPolicy = getSandboxAttachmentPolicy();
    const primaryPeer = sandbox.peers.primary;
    const availableClients = getContextClients(sandbox.context);

    if (!availableClients.length && !isCurrentUserAdmin()) {
        pageHost.innerHTML = `
            <section class="panel panel-stack page-shell-fill sandbox-page-shell">
                <div class="page-head sandbox-page-head">
                    <div class="sandbox-page-head-main">
                        <p class="eyebrow">Sandbox</p>
                        <h1 class="page-title">Realtime transport sandbox</h1>
                        <p class="page-lede">Use your current client and project-scope setup inside Realtime itself, then compare the behavior against your own project environment.</p>
                    </div>
                </div>
                ${renderEmptyStateHtml(
                    "No assigned clients",
                    "This account cannot open the sandbox yet because no clients are assigned to it. Ask an admin to assign at least one client and project scope."
                )}
            </section>
        `;
        return;
    }

    pageHost.innerHTML = `
        <section class="panel panel-stack page-shell-fill sandbox-page-shell">
            <div class="page-head sandbox-page-head">
                <div class="sandbox-page-head-main">
                    <p class="eyebrow">Sandbox</p>
                    <h1 class="page-title">Realtime transport sandbox</h1>
                    <p class="page-lede">Use your current client and project-scope setup inside Realtime itself, then compare the behavior against your own project environment.</p>
                </div>
                <div class="grid-actions sandbox-page-head-actions">
                    ${renderSandboxSessionHeaderActions(primaryPeer)}
                    <button type="button" class="button button-ghost" id="sandboxRefreshContextButton">Refresh context</button>
                </div>
            </div>

            <div class="sandbox-layout">
                <section class="panel panel-stack sandbox-config-panel">
                    <div class="sandbox-column-heading">
                        <p class="eyebrow">Context</p>
                        <h2 class="section-title">Sandbox settings</h2>
                    </div>
                    <label class="form-stack">
                        <span class="label">Client</span>
                        <select id="sandboxClientSelect" class="field">
                            ${buildSandboxClientOptions(selectedClient?.client_code || "")}
                        </select>
                    </label>
                    <label class="form-stack">
                        <span class="label">Project scope</span>
                        <select id="sandboxProjectSelect" class="field">
                            ${buildSandboxProjectOptions(selectedClient, selectedProject?.project_code || "")}
                        </select>
                    </label>
                    <label class="form-stack">
                        <span class="label">Room</span>
                        <input id="sandboxRoomInput" class="field" type="text" value="${escapeHtml(sandbox.room)}" placeholder="chat.thread.demo-room">
                        <span class="help-text">If the value does not start with <code>chat.thread.</code>, the sandbox prefixes it automatically.</span>
                    </label>
                    <div class="sandbox-peer-fields sandbox-config-peer-fields">
                        <label class="form-stack">
                            <span class="label">Display name</span>
                            <input id="sandboxDisplayNameInput-primary" class="field" type="text" value="${escapeHtml(primaryPeer.displayName)}" placeholder="Realtime Sandbox User">
                        </label>
                        <label class="form-stack">
                            <span class="label">User identity</span>
                            <input id="sandboxUserIdInput-primary" class="field" type="text" value="${escapeHtml(primaryPeer.userId)}" placeholder="Optional stable user id">
                        </label>
                    </div>

                    <div class="sandbox-context-card">
                        <div class="detail-row">
                            <dt>Issuer identity</dt>
                            <dd>${escapeHtml(selectedClient?.issuer_identity || "Not set")}</dd>
                        </div>
                        <div class="detail-row">
                            <dt>Trusted signing profile</dt>
                            <dd>${escapeHtml(selectedClient?.trusted_signing_profile || "Not set")}</dd>
                        </div>
                        <div class="detail-row">
                            <dt>Policy profile</dt>
                            <dd>${escapeHtml(selectedProject?.policy_profile_name || selectedProject?.policy_profile_code || "Not assigned")}</dd>
                        </div>
                        <div class="detail-row">
                            <dt>Origin policy</dt>
                            <dd>${escapeHtml(formatLabel(selectedProject?.origin_policy_mode || "not_set"))}</dd>
                        </div>
                        ${renderSandboxAttachmentPolicyRows(attachmentPolicy)}
                    </div>
                </section>

                ${renderSandboxPeerPanel("primary", "Terminal", primaryPeer)}

                ${renderSandboxMediaPanel("primary", primaryPeer)}

                <section class="panel panel-stack sandbox-log-panel">
                    <div class="sandbox-column-heading">
                        <p class="eyebrow">Events</p>
                        <h2 class="section-title">Transport log</h2>
                    </div>
                    <div class="sandbox-log-wrap">
                        <div id="sandboxEventLog" class="sandbox-event-log" aria-label="Sandbox event log"></div>
                    </div>
                </section>
            </div>
        </section>
    `;

    mountSandboxUi();
    renderSandboxLogs();
    bindSandboxPageEvents();
}

function createSandboxState() {
    return {
        context: null,
        selectedClientCode: "",
        selectedProjectCode: "",
        room: "sandbox-room",
        audioPrimed: false,
        mediaPrimerBound: false,
        roster: {},
        lastMeshWarningCount: 0,
        logs: [],
        logList: null,
        peers: {
            primary: createSandboxPeerState("Realtime Sandbox User"),
            secondary: createSandboxPeerState("Realtime Sandbox Peer"),
        },
    };
}

function createPresenceInspectorState() {
    return {
        context: null,
        selectedClientCode: "",
        selectedProjectCode: "",
        room: "sandbox-room",
        connectionStatus: "idle",
        websocketUrl: "",
        tokenExpiresAt: "",
        effectiveRoom: "",
        reconnectCount: 0,
        lastError: "",
        realtimeClient: null,
        socket: null,
        session: null,
        requestSeq: 0,
        roster: {},
    };
}

function createSandboxPeerState(defaultName) {
    const conferenceState = createRealtimeConferenceState();
    return {
        effectiveRoom: "",
        callRoom: "",
        displayName: defaultName,
        userId: "",
        websocketUrl: "",
        tokenExpiresAt: "",
        connectionStatus: "idle",
        roomJoined: false,
        callRoomJoined: false,
        presenceState: "offline",
        reconnectCount: 0,
        lastError: "",
        realtimeClient: null,
        socket: null,
        session: null,
        messages: [],
        uploads: [],
        uploadHydration: null,
        receivedAttachments: {},
        requestSeq: 0,
        pendingOutgoing: [],
        callState: "idle",
        callMode: "",
        incomingCall: null,
        localStream: null,
        isMicEnabled: true,
        isCameraEnabled: true,
        ringtoneAudio: null,
        localAudioGraph: null,
        localAudioGraphHost: null,
        remoteAudioGraphs: {},
        remoteAudioGraphHosts: {},
        participantDisplayNames: {},
        thread: null,
        composer: null,
        uploadQueue: null,
        ...conferenceState,
    };
}

function ensureSandboxSelectionState() {
    const sandbox = state.sandbox;
    const clients = Array.isArray(sandbox.context?.clients) ? sandbox.context.clients : [];
    const selectedClient = clients.find((client) => client.client_code === sandbox.selectedClientCode)
        || clients.find((client) => client.status === "active")
        || clients[0]
        || null;

    sandbox.selectedClientCode = selectedClient?.client_code || "";

    const projects = Array.isArray(selectedClient?.projects) ? selectedClient.projects : [];
    const selectedProject = projects.find((project) => project.project_code === sandbox.selectedProjectCode)
        || projects.find((project) => project.status === "active")
        || projects[0]
        || null;

    sandbox.selectedProjectCode = selectedProject?.project_code || "";
}

function getSandboxSelectedClient() {
    const clients = getContextClients(state.sandbox.context);
    return clients.find((client) => client.client_code === state.sandbox.selectedClientCode) || null;
}

function getSandboxSelectedProject() {
    const client = getSandboxSelectedClient();
    const projects = Array.isArray(client?.projects) ? client.projects : [];
    return projects.find((project) => project.project_code === state.sandbox.selectedProjectCode) || null;
}

function buildSandboxClientOptions(selectedValue) {
    return buildContextClientOptions(state.sandbox.context, selectedValue);
}

function buildContextClientOptions(context, selectedValue) {
    const clients = getContextClients(context);

    return clients.map((client) => {
        const selected = client.client_code === selectedValue ? " selected" : "";
        const status = client.status && client.status !== "active" ? ` [${formatLabel(client.status)}]` : "";
        return `<option value="${escapeHtml(client.client_code)}"${selected}>${escapeHtml(client.name)} (${escapeHtml(client.client_code)})${escapeHtml(status)}</option>`;
    }).join("");
}

function buildSandboxProjectOptions(client, selectedValue) {
    const projects = Array.isArray(client?.projects) ? client.projects : [];

    return projects.map((project) => {
        const selected = project.project_code === selectedValue ? " selected" : "";
        const label = project.policy_profile_name
            ? `${project.name} (${project.policy_profile_name})`
            : `${project.name} (${project.project_code})`;
        return `<option value="${escapeHtml(project.project_code)}"${selected}>${escapeHtml(label)}</option>`;
    }).join("");
}

function getContextClients(context) {
    return Array.isArray(context?.clients) ? context.clients : [];
}

function getSandboxAttachmentPolicy() {
    const project = getSandboxSelectedProject();
    const policy = project?.attachment_policy;
    return {
        maxAttachmentCount: Math.max(0, Number(policy?.max_attachment_count) || 0),
        maxAttachmentBytes: Math.max(0, Number(policy?.max_attachment_bytes) || 0),
        maxTotalBytesPerMessage: Math.max(0, Number(policy?.max_total_bytes_per_message) || 0),
        chunkEventsPerMinute: Math.max(0, Number(policy?.chunk_events_per_minute) || 0),
        chunkBytesPerMinute: Math.max(0, Number(policy?.chunk_bytes_per_minute) || 0),
    };
}

function getSandboxRosterItems() {
    return listPresenceRosterItems(state.sandbox.roster || {});
}

function getSandboxParticipantCount() {
    return getSandboxRosterItems().length;
}

function maybeWarnSandboxMeshParticipantCount() {
    const count = getSandboxParticipantCount();
    const sandbox = state.sandbox;

    if (count < 4) {
        sandbox.lastMeshWarningCount = 0;
        return;
    }

    if (count <= sandbox.lastMeshWarningCount) {
        return;
    }

    sandbox.lastMeshWarningCount = count;
    showToast(getMeshConferenceWarning(count, { cautionAt: 4, limit: 5 }), {
        title: "Sandbox",
        type: "warning",
    });
}

function getSandboxPeerUserId(peer) {
    return String(peer?.session?.user_id || peer?.userId || "").trim();
}

function getSandboxRosterParticipantsForPeer(peerKey) {
    const peer = state.sandbox.peers[peerKey];
    const currentUserId = getSandboxPeerUserId(peer);
    const seen = new Set();

    return getSandboxRosterItems()
        .map((entry) => ({
            userId: String(entry?.userId || "").trim(),
            sessionId: String(entry?.sessionId || "").trim(),
            displayName: String(entry?.displayName || entry?.userId || "").trim(),
        }))
        .filter((entry) => entry.userId && entry.userId !== currentUserId)
        .filter((entry) => {
            if (seen.has(entry.userId)) {
                return false;
            }
            seen.add(entry.userId);
            return true;
        })
        .sort((a, b) => a.userId.localeCompare(b.userId));
}

function getSandboxIncomingOfferEntries(peer) {
    return Object.values(peer.incomingOffers || {}).filter(Boolean);
}

function refreshSandboxIncomingCallSummary(peerKey) {
    const peer = state.sandbox.peers[peerKey];
    const pendingOffers = getSandboxIncomingOfferEntries(peer);
    peer.incomingCall = pendingOffers[0] || null;

    if (!pendingOffers.length && peer.callState === "incoming") {
        peer.callState = hasActiveSandboxPeerConnections(peer)
            ? "connected"
            : "idle";
    }
}

function hasActiveSandboxPeerConnections(peer) {
    return Object.values(peer.peerConnections || {}).some((pc) => {
        return pc && ["new", "connecting", "connected"].includes(String(pc.connectionState || ""));
    });
}

function refreshSandboxAggregateCallState(peerKey) {
    const peer = state.sandbox.peers[peerKey];
    const connections = Object.values(peer.peerConnections || {}).filter(Boolean);
    const hasConnected = connections.some((pc) => String(pc.connectionState || "") === "connected");
    const hasConnecting = connections.some((pc) => ["new", "connecting"].includes(String(pc.connectionState || "")));
    const hasIncoming = getSandboxIncomingOfferEntries(peer).length > 0;

    if (hasConnected) {
        peer.callState = "connected";
    } else if (hasConnecting) {
        peer.callState = "connecting";
    } else if (hasIncoming) {
        peer.callState = "incoming";
    } else {
        peer.callState = "idle";
    }
}

function maybeAutoEndSandboxSoloCall(peerKey) {
    const peer = state.sandbox.peers[peerKey];
    const hasLocalMedia = Boolean(peer.localStream);
    const hasIncoming = getSandboxIncomingOfferEntries(peer).length > 0;
    const hasRemoteConnections = Object.values(peer.peerConnections || {}).some(Boolean);

    if (!hasLocalMedia || hasIncoming || hasRemoteConnections || peer.callState !== "idle") {
        return false;
    }

    cleanupSandboxCall(peerKey, { preserveMessages: true });
    return true;
}

function getSandboxRemoteMediaEntries(peer) {
    return Object.entries(peer.remoteStreams || {})
        .map(([userId, stream]) => ({
            userId,
            displayName: String(peer.participantDisplayNames?.[userId] || userId || "Remote peer").trim(),
            stream,
        }))
        .filter((entry) => entry.stream instanceof MediaStream)
        .sort((a, b) => a.displayName.localeCompare(b.displayName));
}

function shouldSandboxPeerOfferToRemote(peerKey, remoteUserId) {
    const peer = state.sandbox.peers[peerKey];
    const ownUserId = getSandboxPeerUserId(peer);
    if (!ownUserId || !remoteUserId || ownUserId === remoteUserId) {
        return false;
    }
    return ownUserId.localeCompare(remoteUserId) < 0;
}

function clearSandboxRoster() {
    state.sandbox.roster = {};
    state.sandbox.lastMeshWarningCount = 0;
}

function updateSandboxRosterFromPresence(payload) {
    const sandbox = state.sandbox;
    sandbox.roster = reducePresenceRosterEvent(sandbox.roster, payload);
    maybeWarnSandboxMeshParticipantCount();
}

function renderSandboxAttachmentPolicyRows(policy) {
    const attachmentCount = policy.maxAttachmentCount > 0
        ? `${policy.maxAttachmentCount} file(s)`
        : "Not limited";
    const attachmentBytes = policy.maxAttachmentBytes > 0
        ? formatFileSize(policy.maxAttachmentBytes)
        : "Not limited";
    const totalBytes = policy.maxTotalBytesPerMessage > 0
        ? formatFileSize(policy.maxTotalBytesPerMessage)
        : "Not limited";
    const chunkEvents = policy.chunkEventsPerMinute > 0
        ? `${policy.chunkEventsPerMinute}/minute`
        : "Not limited";
    const chunkBytes = policy.chunkBytesPerMinute > 0
        ? `${formatFileSize(policy.chunkBytesPerMinute)}/minute`
        : "Not limited";

    return `
        <div class="detail-row">
            <dt>Attachment count</dt>
            <dd>${escapeHtml(attachmentCount)}</dd>
        </div>
        <div class="detail-row">
            <dt>Max file size</dt>
            <dd>${escapeHtml(attachmentBytes)}</dd>
        </div>
        <div class="detail-row">
            <dt>Max total bytes</dt>
            <dd>${escapeHtml(totalBytes)}</dd>
        </div>
        <div class="detail-row">
            <dt>Chunk events</dt>
            <dd>${escapeHtml(chunkEvents)}</dd>
        </div>
        <div class="detail-row">
            <dt>Chunk bytes</dt>
            <dd>${escapeHtml(chunkBytes)}</dd>
        </div>
    `;
}

function renderPresenceInspectorPage(data) {
    if (state.route.kind !== "presenceInspector") {
        return;
    }

    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    const inspector = state.presenceInspector;
    inspector.context = data || {};
    ensurePresenceInspectorSelectionState();

    const selectedClient = getPresenceInspectorSelectedClient();
    const selectedProject = getPresenceInspectorSelectedProject();
    const roster = getPresenceInspectorRosterItems();
    const room = inspector.effectiveRoom || normalizePresenceInspectorRoom(inspector.room);
    const isConnected = Boolean(inspector.socket);
    const availableClients = getContextClients(inspector.context);

    if (!availableClients.length && !isCurrentUserAdmin()) {
        pageHost.innerHTML = `
            <section class="panel panel-stack page-shell-fill presence-page-shell">
                <div class="page-head sandbox-page-head">
                    <div class="sandbox-page-head-main">
                        <p class="eyebrow">Presence</p>
                        <h1 class="page-title">Room presence inspector</h1>
                        <p class="page-lede">Observe who is currently present in a selected room for a specific client and project scope.</p>
                    </div>
                </div>
                ${renderEmptyStateHtml(
                    "No assigned clients",
                    "This account cannot inspect presence yet because no clients are assigned to it. Ask an admin to assign at least one client and project scope."
                )}
            </section>
        `;
        return;
    }

    pageHost.innerHTML = `
        <section class="panel panel-stack page-shell-fill presence-page-shell">
            <div class="page-head sandbox-page-head">
                <div class="sandbox-page-head-main">
                    <p class="eyebrow">Presence</p>
                    <h1 class="page-title">Room presence inspector</h1>
                    <p class="page-lede">Observe who is currently present in a selected room for a specific client and project scope.</p>
                </div>
                <div class="grid-actions sandbox-page-head-actions">
                    <button type="button" class="button button-ghost" id="presenceRefreshContextButton">Refresh context</button>
                </div>
            </div>

            <div class="presence-layout">
                <section class="panel panel-stack sandbox-config-panel">
                    <div>
                        <p class="eyebrow">Context</p>
                        <h2 class="section-title">Inspector settings</h2>
                    </div>
                    <label class="form-stack">
                        <span class="label">Client</span>
                        <select id="presenceClientSelect" class="field">
                            ${buildContextClientOptions(inspector.context, selectedClient?.client_code || "")}
                        </select>
                    </label>
                    <label class="form-stack">
                        <span class="label">Project scope</span>
                        <select id="presenceProjectSelect" class="field">
                            ${buildSandboxProjectOptions(selectedClient, selectedProject?.project_code || "")}
                        </select>
                    </label>
                    <label class="form-stack">
                        <span class="label">Room</span>
                        <input id="presenceRoomInput" class="field" type="text" value="${escapeHtml(inspector.room)}" placeholder="demo-room">
                        <span class="help-text">The inspector uses the room value as entered.</span>
                    </label>

                    <div class="sandbox-context-card">
                        <div class="detail-row">
                            <dt>Effective room</dt>
                            <dd>${escapeHtml(room)}</dd>
                        </div>
                        <div class="detail-row">
                            <dt>Policy profile</dt>
                            <dd>${escapeHtml(selectedProject?.policy_profile_name || selectedProject?.policy_profile_code || "Not assigned")}</dd>
                        </div>
                        <div class="detail-row">
                            <dt>Origin policy</dt>
                            <dd>${escapeHtml(formatLabel(selectedProject?.origin_policy_mode || "not_set"))}</dd>
                        </div>
                    </div>
                </section>

                <section class="panel panel-stack presence-roster-panel">
                    <div class="sandbox-peer-head">
                        <div class="sandbox-peer-titlebar">
                            <div class="sandbox-peer-heading">
                                <p class="eyebrow">Presence</p>
                                <h2 class="section-title">Observed peers</h2>
                            </div>
                            <div class="grid-actions">
                                <button type="button" class="button ${isConnected ? "button-ghost" : ""}" id="presenceConnectButton">${isConnected ? "Reconnect" : "Connect"}</button>
                                <button type="button" class="button button-ghost" id="presenceDisconnectButton" ${isConnected ? "" : "disabled"}>Disconnect</button>
                            </div>
                        </div>
                        <div class="tiny muted">The inspector subscribes to presence for the selected room without acting as a chat terminal.</div>
                        <div class="presence-meta">
                            <div class="sandbox-peer-meta-item">
                                <span class="sandbox-peer-meta-label">Connection</span>
                                <span class="sandbox-peer-meta-value">${renderBadge(formatPresenceInspectorConnectionLabel(inspector))}</span>
                            </div>
                            <div class="sandbox-peer-meta-item">
                                <span class="sandbox-peer-meta-label">Peers</span>
                                <span class="sandbox-peer-meta-value">${escapeHtml(String(roster.length))}</span>
                            </div>
                        </div>
                    </div>
                    <div id="presenceRosterHost" class="presence-roster-host">
                        ${renderPresenceRoster(roster)}
                    </div>
                </section>
            </div>
        </section>
    `;

    bindPresenceInspectorPageEvents();
}

function ensurePresenceInspectorSelectionState() {
    const inspector = state.presenceInspector;
    const clients = getContextClients(inspector.context);
    const selectedClient = clients.find((client) => client.client_code === inspector.selectedClientCode)
        || clients.find((client) => client.status === "active")
        || clients[0]
        || null;

    inspector.selectedClientCode = selectedClient?.client_code || "";

    const projects = Array.isArray(selectedClient?.projects) ? selectedClient.projects : [];
    const selectedProject = projects.find((project) => project.project_code === inspector.selectedProjectCode)
        || projects.find((project) => project.status === "active")
        || projects[0]
        || null;

    inspector.selectedProjectCode = selectedProject?.project_code || "";
}

function getPresenceInspectorSelectedClient() {
    const clients = getContextClients(state.presenceInspector.context);
    return clients.find((client) => client.client_code === state.presenceInspector.selectedClientCode) || null;
}

function getPresenceInspectorSelectedProject() {
    const client = getPresenceInspectorSelectedClient();
    const projects = Array.isArray(client?.projects) ? client.projects : [];
    return projects.find((project) => project.project_code === state.presenceInspector.selectedProjectCode) || null;
}

function bindPresenceInspectorPageEvents() {
    document.getElementById("presenceClientSelect")?.addEventListener("change", (event) => {
        state.presenceInspector.selectedClientCode = String(event.target?.value || "").trim();
        state.presenceInspector.selectedProjectCode = "";
        disconnectPresenceInspector("Client context changed.");
        renderPresenceInspectorPage(state.presenceInspector.context);
    });

    document.getElementById("presenceProjectSelect")?.addEventListener("change", (event) => {
        state.presenceInspector.selectedProjectCode = String(event.target?.value || "").trim();
        disconnectPresenceInspector("Project scope changed.");
        renderPresenceInspectorPage(state.presenceInspector.context);
    });

    document.getElementById("presenceRoomInput")?.addEventListener("input", (event) => {
        state.presenceInspector.room = String(event.target?.value || "");
    });

    document.getElementById("presenceConnectButton")?.addEventListener("click", () => {
        void connectPresenceInspector();
    });

    document.getElementById("presenceDisconnectButton")?.addEventListener("click", () => {
        disconnectPresenceInspector("Disconnected by operator.");
        renderPresenceInspectorPage(state.presenceInspector.context);
    });

    document.getElementById("presenceRefreshContextButton")?.addEventListener("click", async () => {
        const data = await fetchPageData(API.sandboxContext);
        if (!data) return;
        renderPresenceInspectorPage(data);
        showToast("Presence inspector context refreshed.", { title: "Presence", type: "success" });
    });
}

async function connectPresenceInspector() {
    const inspector = state.presenceInspector;
    const client = getPresenceInspectorSelectedClient();
    const project = getPresenceInspectorSelectedProject();

    if (!client || !project) {
        showToast("Select an active client and project scope first.", { title: "Presence", type: "warning" });
        return;
    }

    disconnectPresenceInspector();
    inspector.connectionStatus = "connecting";
    inspector.lastError = "";
    inspector.roster = {};
    renderPresenceInspectorPage(inspector.context);

    const payload = {
        client_code: client.client_code,
        project_code: project.project_code,
        display_name: "Realtime Presence Inspector",
        user_id: "presence-inspector",
        room: String(inspector.room || "").trim(),
        room_mode: "raw",
    };

    const { response, data } = await requestJson(API.sandboxAdmission, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        inspector.connectionStatus = "idle";
        inspector.lastError = data?.message || "Unable to issue a presence inspector session token.";
        renderPresenceInspectorPage(inspector.context);
        showToast(inspector.lastError, { title: "Presence", type: "error" });
        return;
    }

    inspector.websocketUrl = String(data?.data?.websocket_url || "").trim();
    inspector.effectiveRoom = String(data?.data?.effective_room || "").trim();
    inspector.tokenExpiresAt = String(data?.data?.expires_at || "").trim();
    inspector.session = data?.data?.session || null;

    const preflight = await requestJson(API.realtimeSession, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token: String(data?.data?.token || "") }),
    });

    if (!preflight.response.ok) {
        inspector.connectionStatus = "idle";
        inspector.lastError = preflight.data?.message || "Presence inspector token preflight failed.";
        renderPresenceInspectorPage(inspector.context);
        showToast(inspector.lastError, { title: "Presence", type: "error" });
        return;
    }

    try {
        inspector.realtimeClient = new RealtimeSocketClient({
            websocketUrl: inspector.websocketUrl,
            token: String(data?.data?.token || ""),
            requestPrefix: "presence_req",
            onMessage(raw) {
                handlePresenceInspectorSocketMessage(raw);
            },
            onError() {
                inspector.lastError = "Realtime websocket error event received.";
                renderPresenceInspectorPage(inspector.context);
                showToast(inspector.lastError, { title: "Presence", type: "error" });
            },
            onClose() {
                const wasConnected = inspector.connectionStatus === "connected";
                if (wasConnected) {
                    inspector.reconnectCount += 1;
                }
                inspector.realtimeClient = null;
                inspector.socket = null;
                inspector.connectionStatus = "idle";
                renderPresenceInspectorPage(inspector.context);
            },
        });
        inspector.socket = inspector.realtimeClient.connect();
    } catch (error) {
        inspector.connectionStatus = "idle";
        inspector.realtimeClient = null;
        inspector.socket = null;
        inspector.lastError = error instanceof Error ? error.message : "Unable to open the realtime websocket.";
        renderPresenceInspectorPage(inspector.context);
        showToast(inspector.lastError, { title: "Presence", type: "error" });
        return;
    }
}

function disconnectPresenceInspector(reason = "") {
    const inspector = state.presenceInspector;
    inspector.realtimeClient?.close?.();

    inspector.realtimeClient = null;
    inspector.socket = null;
    inspector.connectionStatus = "idle";
    inspector.lastError = reason ? String(reason) : inspector.lastError;
    inspector.roster = {};
}

function destroyPresenceInspectorRuntime() {
    disconnectPresenceInspector();
}

function sendPresenceInspectorEnvelope(type, room, payload) {
    const inspector = state.presenceInspector;
    return inspector.realtimeClient?.sendRequest(type, room, payload) ?? null;
}

function handlePresenceInspectorSocketMessage(raw) {
    const inspector = state.presenceInspector;
    let envelope = null;

    try {
        envelope = parseRealtimeEnvelope(raw);
    } catch {
        return;
    }

    if (envelope.phase === "ack" && envelope.type === "session.auth.request") {
        inspector.connectionStatus = "connected";
        renderPresenceInspectorPage(inspector.context);
        sendPresenceInspectorEnvelope("room.join.request", inspector.effectiveRoom, buildRoomJoinPayload());
        return;
    }

    if (envelope.phase === "ack" && envelope.type === "room.join.request") {
        sendPresenceInspectorEnvelope("presence.subscribe", inspector.effectiveRoom, buildPresenceSubscribePayload(inspector.effectiveRoom));
        return;
    }

    if (envelope.phase === "event" && envelope.type === "presence.state.event") {
        inspector.roster = reducePresenceRosterEvent(inspector.roster, envelope.payload || {});
        renderPresenceInspectorPage(inspector.context);
    }
}

function getPresenceInspectorRosterItems() {
    const now = Date.now();
    return Object.values(state.presenceInspector.roster || {})
        .filter((entry) => {
            if (!entry.expiresAt) {
                return true;
            }
            const expires = new Date(entry.expiresAt).getTime();
            return Number.isNaN(expires) || expires >= now;
        })
        .sort((left, right) => {
            const leftTime = new Date(left.updatedAt || 0).getTime();
            const rightTime = new Date(right.updatedAt || 0).getTime();
            return rightTime - leftTime;
        });
}

function formatPresenceInspectorConnectionLabel(inspector) {
    return inspector.connectionStatus === "connected"
        ? "Connected"
        : inspector.connectionStatus === "connecting"
            ? "Connecting"
            : "Disconnected";
}

function renderPresenceRoster(items) {
    if (!items.length) {
        return `
            <div class="empty-state-card">
                <strong>No peers observed yet</strong>
                <span class="muted">Connect the inspector and subscribe to a room with active presence publishers.</span>
            </div>
        `;
    }

    return `
        <div class="presence-roster-list">
            ${items.map((item) => `
                <article class="presence-roster-item">
                    <div class="presence-roster-head">
                        <strong>${escapeHtml(item.userId || item.sessionId || "Unknown peer")}</strong>
                        ${renderBadge(formatLabel(item.state || "offline"))}
                    </div>
                    <div class="presence-roster-meta">
                        <div class="detail-row">
                            <dt>Session</dt>
                            <dd>${escapeHtml(item.sessionId || "Not available")}</dd>
                        </div>
                        <div class="detail-row">
                            <dt>Project</dt>
                            <dd>${escapeHtml(item.projectCode || "Not available")}</dd>
                        </div>
                        <div class="detail-row">
                            <dt>Status text</dt>
                            <dd>${escapeHtml(item.statusText || "Not set")}</dd>
                        </div>
                        <div class="detail-row">
                            <dt>Updated</dt>
                            <dd>${escapeHtml(formatSandboxTimestamp(item.updatedAt) || item.updatedAt || "Not available")}</dd>
                        </div>
                        <div class="detail-row">
                            <dt>Expires</dt>
                            <dd>${escapeHtml(item.expiresAt || "Not available")}</dd>
                        </div>
                    </div>
                </article>
            `).join("")}
        </div>
    `;
}

function validateSandboxDraftFiles(peerKey, files) {
    const peer = state.sandbox.peers[peerKey];
    const policy = getSandboxAttachmentPolicy();
    const { accepted, rejected } = validateDraftAttachments({
        existingItems: peer.uploads,
        files,
        policy,
    });
    return { accepted, rejected, policy };
}

function normalizeSandboxRoom(value) {
    const trimmed = String(value || "").trim();
    if (!trimmed) {
        return "chat.thread.sandbox-room";
    }

    if (trimmed.startsWith("chat.thread.")) {
        return trimmed;
    }

    return `chat.thread.${trimmed.replace(/[^A-Za-z0-9._-]+/g, "-").replace(/^[-.]+|[-.]+$/g, "") || "sandbox-room"}`;
}

function normalizePresenceInspectorRoom(value) {
    const trimmed = String(value || "").trim();
    if (!trimmed) {
        return "sandbox-room";
    }

    return trimmed.replace(/[^A-Za-z0-9._-]+/g, "-").replace(/^[-.]+|[-.]+$/g, "") || "sandbox-room";
}

function normalizeSandboxCallRoom(value) {
    const trimmed = String(value || "").trim();
    if (!trimmed) {
        return "call.session.sandbox-room";
    }

    if (trimmed.startsWith("call.session.")) {
        return trimmed;
    }

    const base = trimmed.startsWith("chat.thread.")
        ? trimmed.slice("chat.thread.".length)
        : trimmed;

    return `call.session.${base.replace(/[^A-Za-z0-9._-]+/g, "-").replace(/^[-.]+|[-.]+$/g, "") || "sandbox-room"}`;
}

function destroySandboxUiInstances() {
    Object.values(state.sandbox.peers).forEach((peer) => destroySandboxPeerUiInstances(peer));
    state.sandbox.logList?.destroy?.();
    state.sandbox.logList = null;
}

function destroySandboxRuntime() {
    destroySandboxUiInstances();
    disconnectAllSandboxPeers();
}

function buildSandboxMessageMenuItems(message) {
    const hasText = Boolean(String(message?.body || "").trim());
    return [
        ...(hasText ? [{ id: "copy-text", label: "Copy text" }] : []),
        { id: "copy-json", label: "Copy message JSON" },
    ];
}

async function handleSandboxMessageMenuSelect(peerKey, actionId, message) {
    try {
        if (actionId === "copy-text") {
            await navigator.clipboard.writeText(String(message?.body || "").trim());
            showToast("Message text copied.", { title: "Sandbox", type: "success" });
            return;
        }

        if (actionId === "copy-json") {
            await navigator.clipboard.writeText(JSON.stringify(message || {}, null, 2));
            showToast("Message JSON copied.", { title: "Sandbox", type: "success" });
        }
    } catch (error) {
        pushSandboxLog(`${peerKey}.message.menu.error`, {
            actionId,
            message: error instanceof Error ? error.message : String(error || "Unknown error"),
        });
        showToast("Unable to complete message action.", { title: "Sandbox", type: "error" });
    }
}

function openSandboxAttachment(attachment) {
    const url = String(attachment?.url || attachment?.previewUrl || "").trim();
    if (!url) {
        return;
    }

    window.open(url, "_blank", "noopener,noreferrer");
}

function downloadSandboxAttachment(attachment) {
    const url = String(attachment?.url || attachment?.previewUrl || "").trim();
    if (!url) {
        return;
    }

    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = String(attachment?.title || attachment?.name || "attachment").trim() || "attachment";
    anchor.rel = "noopener";
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
}

function mountSandboxUi() {
    const sandbox = state.sandbox;
    const logHost = document.getElementById("sandboxEventLog");

    if (logHost && state.ui.virtualList) {
        const logHeight = Math.max(240, Math.floor(logHost.getBoundingClientRect().height || logHost.clientHeight || 0));
        sandbox.logList = state.ui.virtualList(logHost, sandbox.logs, {
            className: "sandbox-log-list",
            chrome: false,
            ariaLabel: "Sandbox event log",
            height: logHeight,
            rowHeight: SANDBOX_LOG_ROW_HEIGHT,
            overscan: SANDBOX_LOG_OVERSCAN,
            emptyText: "No sandbox events yet.",
            renderItem: renderSandboxLogRow,
        });
    }

    Object.entries(sandbox.peers).forEach(([peerKey, peer]) => {
        const threadHost = document.getElementById(`sandboxThreadHost-${peerKey}`);
        const queueHost = document.getElementById(`sandboxUploadQueueHost-${peerKey}`);
        const composerHost = document.getElementById(`sandboxComposerHost-${peerKey}`);

        if (threadHost && state.ui.chatThread) {
            peer.thread = state.ui.chatThread(threadHost, { messages: peer.messages }, {
                className: "sandbox-chat-thread",
                emptyTitle: "No realtime messages yet",
                emptyText: "Connect this peer, then send a chat message through the selected project scope.",
                showMessageMenuTrigger: true,
                getMessageMenuItems(message) {
                    return buildSandboxMessageMenuItems(message);
                },
                onMessageMenuSelect(actionId, message) {
                    void handleSandboxMessageMenuSelect(peerKey, actionId, message);
                },
                onAttachmentOpen(message, attachment) {
                    pushSandboxLog(`${peerKey}.attachment.open`, {
                        messageId: message.id,
                        attachmentId: attachment.id,
                        kind: attachment.kind,
                    });
                    openSandboxAttachment(attachment);
                },
                onAttachmentDownload(message, attachment) {
                    pushSandboxLog(`${peerKey}.attachment.download`, {
                        messageId: message.id,
                        attachmentId: attachment.id,
                        kind: attachment.kind,
                    });
                    downloadSandboxAttachment(attachment);
                },
            });
        }

        if (queueHost && state.ui.chatUploadQueue) {
            peer.uploadQueue = state.ui.chatUploadQueue(queueHost, { items: peer.uploads }, {
                onRemove(item) {
                    peer.uploads = peer.uploads.filter((candidate) => candidate.id !== item.id);
                    peer.uploadQueue?.setItems?.(peer.uploads);
                    pushSandboxLog(`${peerKey}.draft.remove`, { id: item.id, name: item.name });
                },
            });
        }

        if (composerHost && state.ui.chatComposer) {
            peer.composer = state.ui.chatComposer(composerHost, { value: "" }, {
                helperText: peer.roomJoined
                    ? `Connected to ${peer.effectiveRoom || normalizeSandboxRoom(sandbox.room)}`
                    : "Connect this peer first. Enter sends, Shift+Enter adds a new line.",
                accept: "image/*,video/*,audio/*,.pdf,.txt",
                multiple: true,
                disabled: !peer.roomJoined,
                onSend(payload) {
                    void sendSandboxChatMessage(peerKey, payload);
                },
                onFilesSelected(files) {
                    void addSandboxDraftFiles(peerKey, files);
                },
            });
        }

        attachSandboxMediaStreams(peerKey);
    });
}

function bindSandboxPageEvents() {
    if (!state.sandbox.mediaPrimerBound) {
        const primeHandler = () => {
            primeSandboxMediaSurface();
        };
        document.addEventListener("pointerdown", primeHandler, { capture: true, passive: true });
        document.addEventListener("keydown", primeHandler, { capture: true });
        state.sandbox.mediaPrimerBound = true;
    }

    document.getElementById("sandboxClientSelect")?.addEventListener("change", (event) => {
        const value = String(event.target?.value || "").trim();
        state.sandbox.selectedClientCode = value;
        state.sandbox.selectedProjectCode = "";
        clearSandboxRoster();
        disconnectAllSandboxPeers("Client context changed.");
        renderSandboxPage(state.sandbox.context);
    });

    document.getElementById("sandboxProjectSelect")?.addEventListener("change", (event) => {
        const value = String(event.target?.value || "").trim();
        state.sandbox.selectedProjectCode = value;
        clearSandboxRoster();
        disconnectAllSandboxPeers("Project scope changed.");
        renderSandboxPage(state.sandbox.context);
    });

    document.getElementById("sandboxRoomInput")?.addEventListener("input", (event) => {
        state.sandbox.room = String(event.target?.value || "");
        clearSandboxRoster();
    });

    document.getElementById("sandboxPageConnectButton")?.addEventListener("click", () => {
        void connectSandboxPeer("primary");
    });

    document.getElementById("sandboxPageDisconnectButton")?.addEventListener("click", () => {
        disconnectSandboxPeer("primary", "Disconnected by operator.");
        renderSandboxPage(state.sandbox.context);
    });

    ["primary"].forEach((peerKey) => {
        document.getElementById(`sandboxDisplayNameInput-${peerKey}`)?.addEventListener("input", (event) => {
            state.sandbox.peers[peerKey].displayName = String(event.target?.value || "");
        });

        document.getElementById(`sandboxUserIdInput-${peerKey}`)?.addEventListener("input", (event) => {
            state.sandbox.peers[peerKey].userId = String(event.target?.value || "");
        });

        bindSandboxPeerActionEvents(peerKey);
    });

    document.getElementById("sandboxRefreshContextButton")?.addEventListener("click", async () => {
        const data = await fetchPageData(API.sandboxContext);
        if (!data) return;
        renderSandboxPage(data);
        showToast("Sandbox context refreshed.", { title: "Sandbox", type: "success" });
    });

}

function bindSandboxPeerActionEvents(peerKey) {
    document.getElementById(`sandboxAudioCallButton-${peerKey}`)?.addEventListener("click", () => {
        void startSandboxCall(peerKey, "audio");
    });

    document.getElementById(`sandboxVideoCallButton-${peerKey}`)?.addEventListener("click", () => {
        void startSandboxCall(peerKey, "video");
    });

    document.getElementById(`sandboxAnswerCallButton-${peerKey}`)?.addEventListener("click", () => {
        void answerSandboxCall(peerKey);
    });

        document.getElementById(`sandboxEndCallButton-${peerKey}`)?.addEventListener("click", () => {
            void endSandboxCall(peerKey, "hangup", { propagate: false });
        });

    document.getElementById(`sandboxToggleMicButton-${peerKey}`)?.addEventListener("click", () => {
        toggleSandboxMic(peerKey);
    });

    document.getElementById(`sandboxToggleCameraButton-${peerKey}`)?.addEventListener("click", () => {
        void toggleSandboxCamera(peerKey);
    });
}

async function addSandboxDraftFiles(peerKey, files) {
    const peer = state.sandbox.peers[peerKey];
    const { accepted, rejected } = validateSandboxDraftFiles(peerKey, files);
    if (rejected.length) {
        pushSandboxLog(`${peerKey}.draft.attachments.rejected`, { reasons: rejected });
        showToast(rejected[0], { title: "Sandbox", type: "warning" });
    }

    if (!accepted.length) {
        return;
    }

    const hydration = Promise.all(Array.from(accepted).map(async (file, index) => {
        const kind = inferSandboxAttachmentKind(file);
        const previewCapable = shouldPreviewSandboxFile(file);
        const objectUrl = previewCapable ? URL.createObjectURL(file) : "";
        const transportUrl = await readSandboxFileAsDataUrl(file);

        return {
            id: `${Date.now()}-${index}-${String(file.name || "file").replace(/\s+/g, "-")}`,
            kind,
            name: String(file.name || "attachment"),
            sizeLabel: formatFileSize(file.size),
            byteSize: Number(file.size) || 0,
            status: "queued",
            progress: null,
            progressLabel: "",
            previewUrl: objectUrl,
            transportUrl,
            mimeType: String(file.type || getAttachmentMimeType(kind)),
            file,
        };
    }));

    peer.uploadHydration = hydration;
    const nextItems = await hydration;
    if (peer.uploadHydration === hydration) {
        peer.uploadHydration = null;
    }

    peer.uploads = peer.uploads.concat(nextItems);
    peer.uploadQueue?.setItems?.(peer.uploads);
    pushSandboxLog(`${peerKey}.draft.attachments`, {
        count: nextItems.length,
        names: nextItems.map((item) => item.name),
    });
}

async function connectSandboxPeer(peerKey) {
    const sandbox = state.sandbox;
    const peer = sandbox.peers[peerKey];
    resumeSandboxAudioGraphs(peerKey);
    const client = getSandboxSelectedClient();
    const project = getSandboxSelectedProject();

    if (!client || !project) {
        showToast("Select an active client and project scope first.", { title: "Sandbox", type: "warning" });
        return;
    }

    if (!String(peer.displayName || "").trim()) {
        showToast("Display name is required before connecting.", { title: "Sandbox", type: "warning" });
        return;
    }

    disconnectSandboxPeer(peerKey);
    clearSandboxRoster();
    peer.connectionStatus = "connecting";
    peer.lastError = "";
    renderSandboxPage(sandbox.context);

    const payload = {
        client_code: client.client_code,
        project_code: project.project_code,
        display_name: peer.displayName.trim(),
        user_id: String(peer.userId || "").trim(),
        room: String(sandbox.room || "").trim(),
    };

    const { response, data } = await requestJson(API.sandboxAdmission, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        peer.connectionStatus = "idle";
        renderSandboxPage(sandbox.context);
        showToast(data?.message || "Unable to issue a sandbox session token.", { title: "Sandbox", type: "error" });
        return;
    }

    peer.websocketUrl = String(data?.data?.websocket_url || "").trim();
    peer.effectiveRoom = String(data?.data?.effective_room || "").trim();
    peer.callRoom = String(data?.data?.effective_call_room || normalizeSandboxCallRoom(sandbox.room)).trim();
    peer.tokenExpiresAt = String(data?.data?.expires_at || "").trim();
    peer.session = data?.data?.session || null;
    peer.roomJoined = false;
    peer.callRoomJoined = false;
    peer.presenceState = "offline";

    const preflight = await requestJson(API.realtimeSession, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
        },
        body: JSON.stringify({
            token: String(data?.data?.token || ""),
        }),
    });

    if (!preflight.response.ok) {
        peer.connectionStatus = "idle";
        peer.lastError = preflight.data?.message || "Sandbox token preflight failed.";
        renderSandboxPage(sandbox.context);
        showToast(peer.lastError, { title: "Sandbox", type: "error" });
        return;
    }

    pushSandboxLog(`${peerKey}.session.issued`, {
        client_code: peer.session?.client_code,
        project_code: peer.session?.project_code,
        effective_room: peer.effectiveRoom,
    });

    try {
        peer.realtimeClient = new RealtimeSocketClient({
            websocketUrl: peer.websocketUrl,
            token: String(data?.data?.token || ""),
            requestPrefix: `${peerKey}_req`,
            onOpen() {
                pushSandboxLog(`${peerKey}.socket.open`, {
                    websocket_url: peer.websocketUrl,
                });
            },
            onMessage(raw) {
                handleSandboxSocketMessage(peerKey, raw);
            },
            onError() {
                peer.lastError = "Realtime websocket error event received.";
                pushSandboxLog(`${peerKey}.socket.error`, { message: "WebSocket error event received." });
                showToast(peer.lastError, { title: "Sandbox", type: "error" });
            },
            onClose() {
                const wasConnected = peer.connectionStatus === "connected" || peer.roomJoined;
                if (wasConnected) {
                    peer.reconnectCount += 1;
                }
                peer.realtimeClient = null;
                peer.socket = null;
                peer.connectionStatus = "idle";
                peer.roomJoined = false;
                peer.callRoomJoined = false;
                peer.presenceState = "offline";
                cleanupSandboxCall(peerKey, { preserveMessages: true });
                renderSandboxPage(sandbox.context);

                if (wasConnected) {
                    showToast(`${peerKey === "primary" ? "Peer A" : "Peer B"} websocket disconnected.`, { title: "Sandbox", type: "warning" });
                }
            },
        });
        peer.socket = peer.realtimeClient.connect();
    } catch (error) {
        peer.connectionStatus = "idle";
        peer.realtimeClient = null;
        peer.socket = null;
        renderSandboxPage(sandbox.context);
        showToast(error instanceof Error ? error.message : "Unable to open the realtime websocket.", { title: "Sandbox", type: "error" });
        return;
    }
}

function disconnectSandboxPeer(peerKey, reason = "") {
    const peer = state.sandbox.peers[peerKey];
    if (reason) {
        pushSandboxLog(`${peerKey}.session.disconnect`, { reason });
    }

    void endSandboxCall(peerKey, "hangup", { silent: true });

    peer.realtimeClient?.close?.();

    peer.realtimeClient = null;
    peer.socket = null;
    peer.connectionStatus = "idle";
    peer.roomJoined = false;
    peer.callRoomJoined = false;
    peer.presenceState = "offline";
    peer.receivedAttachments = {};

    if (peerKey === "primary") {
        clearSandboxRoster();
    }
}

function disconnectAllSandboxPeers(reason = "") {
    disconnectSandboxPeer("primary", reason);
    disconnectSandboxPeer("secondary", reason);
}

function handleSandboxSocketMessage(peerKey, raw) {
    const sandbox = state.sandbox;
    const peer = sandbox.peers[peerKey];
    let envelope = null;

    try {
        envelope = parseRealtimeEnvelope(raw);
    } catch {
        pushSandboxLog(`${peerKey}.socket.raw`, { message: String(raw || "") });
        return;
    }

    pushSandboxLog(`${peerKey}.${envelope.phase || "unknown"}.${envelope.type || "message"}`, envelope.payload || {});

    if (envelope.phase === "ack" && envelope.type === "session.auth.request") {
        peer.connectionStatus = "connected";
        peer.session = {
            ...(peer.session || {}),
            session_id: String(envelope.payload?.session_id || "").trim(),
            user_id: String(envelope.payload?.user_id || "").trim(),
        };
        renderSandboxPage(sandbox.context);
        sendSandboxEnvelope(peerKey, "room.join.request", peer.effectiveRoom, buildRoomJoinPayload());
        if (peer.callRoom) {
            sendSandboxEnvelope(peerKey, "room.join.request", peer.callRoom, buildRoomJoinPayload());
        }
        return;
    }

    if (envelope.phase === "error" && envelope.type === "room.join.request") {
        if (envelope.room === peer.callRoom && envelope.payload?.code === "call.mesh-limit-exceeded") {
            peer.callRoomJoined = false;
            peer.lastError = String(envelope.payload?.message || "Mesh call room limit exceeded.");
            refreshSandboxPeerChrome(peerKey);
            showToast(peer.lastError, { title: "Sandbox", type: "warning" });
            return;
        }
    }

    if (envelope.phase === "ack" && envelope.type === "room.join.request") {
        if (envelope.room === peer.effectiveRoom) {
            peer.roomJoined = true;
            renderSandboxPage(sandbox.context);
            sendSandboxEnvelope(peerKey, "presence.subscribe", peer.effectiveRoom, buildPresenceSubscribePayload(peer.effectiveRoom));
            sendSandboxEnvelope(peerKey, "presence.publish", peer.effectiveRoom, {
                ...buildPresencePublishPayload(peer.effectiveRoom, "online", "Sandbox active"),
                updated_at: new Date().toISOString(),
            });
            showToast(`${peerKey === "primary" ? "Peer A" : "Peer B"} joined ${peer.effectiveRoom}.`, { title: "Sandbox", type: "success" });
        }

        if (envelope.room === peer.callRoom) {
            peer.callRoomJoined = true;
            renderSandboxPage(sandbox.context);
        }
        return;
    }

    if (envelope.phase === "ack" && envelope.type === "presence.publish") {
        peer.presenceState = "online";
        renderSandboxPage(sandbox.context);
        return;
    }

    if (envelope.phase === "event" && envelope.type === "presence.state.event") {
        const subject = envelope.payload?.subject || {};
        const eventSessionId = String(subject.session_id || "").trim();
        const eventUserId = String(subject.user_id || "").trim();
        const ownSessionId = String(peer.session?.session_id || "").trim();
        const ownUserId = String(peer.session?.user_id || peer.userId || "").trim();

        updateSandboxRosterFromPresence(envelope.payload || {});

        if (
            (ownSessionId && eventSessionId && ownSessionId === eventSessionId)
            || (!ownSessionId && ownUserId && eventUserId && ownUserId === eventUserId)
        ) {
            peer.presenceState = String(envelope.payload?.state || "online").trim() || "online";
        }
        renderSandboxPage(sandbox.context);
        if (isSandboxCallActive(peer) && ["online", "connecting", "connected"].includes(String(peer.presenceState || ""))) {
            void ensureSandboxMeshOffersToRoomParticipants(peerKey, peer.callMode || "audio");
        }
        return;
    }

    if (envelope.phase === "ack" && envelope.type === "chat.message.publish") {
        const pending = peer.pendingOutgoing.find((item) => item.requestId === envelope.id);
        if (pending) {
            const message = peer.messages.find((entry) => entry.id === pending.localId);
            if (message) {
                message.state = "sent";
            }
            pending.serverMessageId = envelope.payload?.message_id || null;
            peer.thread?.setMessages?.(peer.messages);
        }
        return;
    }

    if (envelope.phase === "event" && envelope.type === "sandbox.attachment.chunk.event") {
        absorbSandboxAttachmentChunk(peer, envelope.payload || {});
        return;
    }

    if (envelope.phase === "event" && envelope.type === "call.signal.event") {
        void handleSandboxCallSignalEvent(peerKey, envelope.payload || {});
        return;
    }

    if (envelope.phase === "event" && envelope.type === "chat.message.event") {
        const incoming = normalizeSandboxChatEvent(envelope.payload || {}, peerKey);
        const pendingIndex = peer.pendingOutgoing.findIndex((item) => {
            return item.text === incoming.text
                && String(item.userId || "") === String(incoming.meta?.senderUserId || "");
        });

        if (pendingIndex >= 0) {
            const pending = peer.pendingOutgoing[pendingIndex];
            const messageIndex = peer.messages.findIndex((entry) => entry.id === pending.localId);
            if (messageIndex >= 0) {
                peer.messages[messageIndex] = {
                    ...peer.messages[messageIndex],
                    ...incoming,
                    id: incoming.id,
                    attachments: pending.attachments,
                    state: "delivered",
                };
            } else {
                peer.messages.push({
                    ...incoming,
                    attachments: pending.attachments,
                    state: "delivered",
                });
            }
            peer.pendingOutgoing.splice(pendingIndex, 1);
        } else {
            peer.messages.push(incoming);
        }

        peer.thread?.setMessages?.(peer.messages);
    }
}

function sendSandboxEnvelope(peerKey, type, room, payload) {
    const peer = state.sandbox.peers[peerKey];
    return peer.realtimeClient?.sendRequest(type, room, payload) ?? null;
}

function attachSandboxMediaStreams(peerKey) {
    const peer = state.sandbox.peers[peerKey];
    const localVideo = document.getElementById(`sandboxLocalVideo-${peerKey}`);
    const remoteVideos = Array.from(document.querySelectorAll(`[data-sandbox-remote-video-peer="${peerKey}"]`));

    bindSandboxMediaElement(localVideo, peer.localStream || null, { muted: true });

    remoteVideos.forEach((videoEl) => {
        const remoteUserId = String(videoEl.getAttribute("data-sandbox-remote-user") || "").trim();
        bindSandboxMediaElement(videoEl, peer.remoteStreams?.[remoteUserId] || null, { muted: false });
    });

    syncSandboxAudioGraphs(peerKey, peer.localStream || null, peer.remoteStreams || {});
    if (state.sandbox.audioPrimed) {
        resumeSandboxAudioGraphs(peerKey);
    }
}

function bindSandboxMediaElement(mediaEl, stream, options = {}) {
    bindMediaElementStream(mediaEl, stream, options);
}

function syncSandboxAudioGraphs(peerKey, localStream, remoteStreams) {
    const peer = state.sandbox.peers[peerKey];
    const createAudioGraph = state.ui.audioGraph;
    if (typeof createAudioGraph !== "function") {
        return;
    }

    const localHost = document.getElementById(`sandboxLocalAudioGraph-${peerKey}`);

    syncSandboxAudioGraphInstance(peer, "local", localHost, localStream, {
        role: `sandbox-local-${peerKey}`,
        roleLabel: "Local audio",
        muted: false,
        isPlaying: Boolean(peer.localStream?.getAudioTracks?.().length),
        isLive: Boolean(peer.localStream?.getAudioTracks?.().length),
        isActive: Boolean(peer.localStream?.getAudioTracks?.().length),
    });

    const activeRemoteUserIds = new Set();
    Array.from(document.querySelectorAll(`[data-sandbox-remote-audiograph-peer="${peerKey}"]`)).forEach((host) => {
        const remoteUserId = String(host.getAttribute("data-sandbox-remote-user") || "").trim();
        if (!remoteUserId) {
            return;
        }
        activeRemoteUserIds.add(remoteUserId);
        const remoteStream = remoteStreams?.[remoteUserId] || null;
        syncSandboxRemoteAudioGraphInstance(peer, remoteUserId, host, remoteStream, {
            role: `sandbox-remote-${peerKey}-${remoteUserId}`,
            roleLabel: `Remote audio ${remoteUserId}`,
            muted: false,
            isPlaying: Boolean(remoteStream?.getAudioTracks?.().length),
            isLive: Boolean(remoteStream?.getAudioTracks?.().length),
            isActive: Boolean(remoteStream?.getAudioTracks?.().length),
        });
    });

    Object.keys(peer.remoteAudioGraphs || {}).forEach((remoteUserId) => {
        if (!activeRemoteUserIds.has(remoteUserId)) {
            peer.remoteAudioGraphs?.[remoteUserId]?.destroy?.();
            delete peer.remoteAudioGraphs[remoteUserId];
            delete peer.remoteAudioGraphHosts[remoteUserId];
        }
    });
}

function syncSandboxAudioGraphInstance(peer, kind, host, mediaStream, data) {
    const graphKey = kind === "local" ? "localAudioGraph" : "remoteAudioGraph";
    const hostKey = kind === "local" ? "localAudioGraphHost" : "remoteAudioGraphHost";
    const createAudioGraph = state.ui.audioGraph;

    if (!host || typeof createAudioGraph !== "function") {
        peer[graphKey]?.destroy?.();
        peer[graphKey] = null;
        peer[hostKey] = null;
        return;
    }

    if (peer[graphKey] && peer[hostKey] !== host) {
        peer[graphKey].destroy?.();
        peer[graphKey] = null;
        peer[hostKey] = null;
    }

    if (!peer[graphKey]) {
        peer[graphKey] = createAudioGraph(host, data, {
            className: "sandbox-audiograph",
            ariaLabel: data.roleLabel,
            style: "tsunami",
            overlayHeader: false,
            transparentBackground: true,
            showMute: false,
        });
        peer[hostKey] = host;
    }

    peer[graphKey].attachMediaStream?.(mediaStream);
    peer[graphKey].update?.(data, {
        style: "tsunami",
        overlayHeader: false,
        transparentBackground: true,
        showMute: false,
    });
}

function syncSandboxRemoteAudioGraphInstance(peer, remoteUserId, host, mediaStream, data) {
    const createAudioGraph = state.ui.audioGraph;
    if (!host || typeof createAudioGraph !== "function") {
        peer.remoteAudioGraphs?.[remoteUserId]?.destroy?.();
        delete peer.remoteAudioGraphs[remoteUserId];
        delete peer.remoteAudioGraphHosts[remoteUserId];
        return;
    }

    if (!peer.remoteAudioGraphs) {
        peer.remoteAudioGraphs = {};
    }
    if (!peer.remoteAudioGraphHosts) {
        peer.remoteAudioGraphHosts = {};
    }

    if (peer.remoteAudioGraphs[remoteUserId] && peer.remoteAudioGraphHosts[remoteUserId] !== host) {
        peer.remoteAudioGraphs[remoteUserId]?.destroy?.();
        delete peer.remoteAudioGraphs[remoteUserId];
        delete peer.remoteAudioGraphHosts[remoteUserId];
    }

    if (!peer.remoteAudioGraphs[remoteUserId]) {
        peer.remoteAudioGraphs[remoteUserId] = createAudioGraph(host, data, {
            className: "sandbox-audiograph",
            ariaLabel: data.roleLabel,
            style: "tsunami",
            overlayHeader: false,
            transparentBackground: true,
            showMute: false,
        });
        peer.remoteAudioGraphHosts[remoteUserId] = host;
    }

    peer.remoteAudioGraphs[remoteUserId].attachMediaStream?.(mediaStream);
    peer.remoteAudioGraphs[remoteUserId].update?.(data, {
        style: "tsunami",
        overlayHeader: false,
        transparentBackground: true,
        showMute: false,
    });
}

function resumeSandboxAudioGraphs(peerKey) {
    const peer = state.sandbox.peers[peerKey];
    void peer.localAudioGraph?.resume?.();
    Object.values(peer.remoteAudioGraphs || {}).forEach((graph) => {
        void graph?.resume?.();
    });
}

function refreshSandboxPeerChrome(peerKey) {
    const peer = state.sandbox.peers[peerKey];
    const actionsHost = document.getElementById(`sandboxPeerActions-${peerKey}`);
    const metaHost = document.getElementById(`sandboxPeerMeta-${peerKey}`);

    if (actionsHost) {
        actionsHost.innerHTML = renderSandboxPeerActionButtons(peerKey, peer);
        bindSandboxPeerActionEvents(peerKey);
    }

    if (metaHost) {
        metaHost.innerHTML = renderSandboxPeerMeta(peer);
    }
}

function refreshSandboxMediaPanel(peerKey) {
    const peer = state.sandbox.peers[peerKey];
    const panel = document.getElementById(`sandboxMediaPanel-${peerKey}`);
    if (!panel) {
        return;
    }

    panel.outerHTML = renderSandboxMediaPanel(peerKey, peer);
    attachSandboxMediaStreams(peerKey);
}

function primeSandboxMediaSurface() {
    if (state.sandbox.audioPrimed) {
        return;
    }

    state.sandbox.audioPrimed = true;
    Object.keys(state.sandbox.peers).forEach((peerKey) => {
        resumeSandboxAudioGraphs(peerKey);
    });
}

async function startSandboxCall(peerKey, mode) {
    const peer = state.sandbox.peers[peerKey];
    resumeSandboxAudioGraphs(peerKey);
    if (!peer.socket || !peer.callRoomJoined) {
        showToast("Connect this terminal before starting a call.", { title: "Sandbox", type: "warning" });
        return;
    }

    try {
        await ensureSandboxLocalMedia(peerKey, mode);
        peer.callMode = mode;
        peer.callState = "outgoing";
        renderSandboxPage(state.sandbox.context);

        const participants = getSandboxRosterParticipantsForPeer(peerKey);
        for (const participant of participants) {
            sendSandboxCallSignal(peerKey, "ring", {
                targetUserId: participant.userId,
                meta: {
                    mode,
                    display_name: peer.displayName,
                },
            });
        }

        await ensureSandboxMeshOffersToRoomParticipants(peerKey, mode);
        refreshSandboxAggregateCallState(peerKey);
        renderSandboxPage(state.sandbox.context);
    } catch (error) {
        cleanupSandboxCall(peerKey, { preserveMessages: true });
        renderSandboxPage(state.sandbox.context);
        showToast(error instanceof Error ? error.message : "Unable to start the call.", { title: "Sandbox", type: "error" });
    }
}

async function answerSandboxCall(peerKey) {
    const peer = state.sandbox.peers[peerKey];
    resumeSandboxAudioGraphs(peerKey);
    const pendingOffers = getSandboxIncomingOfferEntries(peer);
    if (!pendingOffers.length) {
        showToast("No incoming call offer is waiting.", { title: "Sandbox", type: "warning" });
        return;
    }

    const mode = pendingOffers.some((offer) => String(offer.mode || "audio") === "video") ? "video" : "audio";

    try {
        stopSandboxRingtone(peerKey);
        await ensureSandboxLocalMedia(peerKey, mode);
        for (const offerEntry of pendingOffers) {
            await answerSandboxOffer(peerKey, offerEntry.userId, String(offerEntry.mode || "audio"), String(offerEntry.sdp || ""));
        }

        peer.callMode = mode;
        refreshSandboxIncomingCallSummary(peerKey);
        refreshSandboxAggregateCallState(peerKey);
        await ensureSandboxMeshOffersToRoomParticipants(peerKey, mode);
        renderSandboxPage(state.sandbox.context);
    } catch (error) {
        cleanupSandboxCall(peerKey, { preserveMessages: true });
        renderSandboxPage(state.sandbox.context);
        showToast(error instanceof Error ? error.message : "Unable to answer the call.", { title: "Sandbox", type: "error" });
    }
}

async function endSandboxCall(peerKey, signalType = "hangup", options = {}) {
    const peer = state.sandbox.peers[peerKey];
    const shouldPropagate = options.propagate === true;
    if (shouldPropagate && peer.socket && peer.callRoomJoined && peer.callState !== "idle") {
        Object.keys(peer.peerConnections || {}).forEach((remoteUserId) => {
            sendSandboxCallSignal(peerKey, signalType, {
                targetUserId: remoteUserId,
                meta: { mode: peer.callMode || "audio" },
            });
        });
    }

    cleanupSandboxCall(peerKey, { preserveMessages: true });
    renderSandboxPage(state.sandbox.context);

    if (!options.silent) {
        showToast("Call ended.", { title: "Sandbox", type: "info" });
    }
}

function cleanupSandboxCall(peerKey, options = {}) {
    const peer = state.sandbox.peers[peerKey];
    stopSandboxRingtone(peerKey);

    Object.values(peer.peerConnections || {}).forEach((pc) => {
        try {
            pc.onicecandidate = null;
            pc.ontrack = null;
            pc.onconnectionstatechange = null;
            pc.close();
        } catch {
            // noop
        }
    });

    [peer.localStream, ...Object.values(peer.remoteStreams || {})].forEach((stream) => {
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

    peer.peerConnections = {};
    peer.localStream = null;
    peer.remoteStreams = {};
    peer.callState = "idle";
    peer.callMode = "";
    peer.incomingCall = null;
    peer.incomingOffers = {};
    peer.pendingIceCandidatesByUser = {};
    peer.isMicEnabled = true;
    peer.isCameraEnabled = true;

    if (!options.preserveMessages) {
        peer.messages = [];
    }
}

async function ensureSandboxLocalMedia(peerKey, mode) {
    const peer = state.sandbox.peers[peerKey];
    if (peer.localStream) {
        if (mode === "video" && !peer.localStream.getVideoTracks?.().length) {
            await ensureSandboxVideoTrack(peerKey);
        }
        return peer.localStream;
    }

    const wantsVideo = mode === "video";
    const stream = await navigator.mediaDevices.getUserMedia({
        audio: true,
        video: wantsVideo,
    });

    peer.localStream = stream;
    peer.isMicEnabled = true;
    peer.isCameraEnabled = wantsVideo;
    attachSandboxMediaStreams(peerKey);
    return stream;
}

async function ensureSandboxVideoTrack(peerKey) {
    const peer = state.sandbox.peers[peerKey];
    const existingTrack = peer.localStream?.getVideoTracks?.()[0] || null;
    if (existingTrack) {
        existingTrack.enabled = true;
        peer.isCameraEnabled = true;
        attachSandboxMediaStreams(peerKey);
        return existingTrack;
    }

    const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
    const track = stream.getVideoTracks()[0];
    if (!track) {
        throw new Error("Unable to acquire a camera track.");
    }

    if (!peer.localStream) {
        peer.localStream = new MediaStream();
    }

    peer.localStream.addTrack(track);
    peer.isCameraEnabled = true;
    attachSandboxMediaStreams(peerKey);
    return track;
}

function ensureSandboxPeerConnection(peerKey) {
    throw new Error("ensureSandboxPeerConnection(remoteUserId) is required.");
}

function ensureSandboxPeerConnectionForRemote(peerKey, remoteUserId) {
    const peer = state.sandbox.peers[peerKey];
    return ensureConferencePeerConnection(peer.peerConnections, remoteUserId, () => {
        const pc = new RTCPeerConnection({
            iceServers: [
                { urls: "stun:stun.l.google.com:19302" },
            ],
        });

        const remoteStream = ensureConferenceRemoteStream(peer.remoteStreams, remoteUserId, () => new MediaStream());

        if (peer.localStream) {
            peer.localStream.getTracks().forEach((track) => {
                pc.addTrack(track, peer.localStream);
            });
        }

        pc.onicecandidate = (event) => {
            if (!event.candidate) {
                return;
            }

            sendSandboxCallSignal(peerKey, "ice-candidate", {
                targetUserId: remoteUserId,
                candidate: event.candidate.toJSON(),
                meta: { mode: peer.callMode || "audio" },
            });
        };

        pc.ontrack = (event) => {
            const incomingTracks = event.streams[0]?.getTracks?.() || [event.track].filter(Boolean);
            incomingTracks.forEach((track) => {
                if (!remoteStream.getTracks().some((candidate) => candidate.id === track.id)) {
                    remoteStream.addTrack(track);
                }
            });
            attachSandboxMediaStreams(peerKey);
        };

        pc.onconnectionstatechange = () => {
            if (["failed", "closed", "disconnected"].includes(String(pc.connectionState || ""))) {
                try {
                    pc.close();
                } catch {
                    // noop
                }
                delete peer.peerConnections[remoteUserId];
                delete peer.remoteStreams[remoteUserId];
                delete peer.pendingIceCandidatesByUser[remoteUserId];
                peer.remoteAudioGraphs?.[remoteUserId]?.destroy?.();
                delete peer.remoteAudioGraphs?.[remoteUserId];
                delete peer.remoteAudioGraphHosts?.[remoteUserId];
                attachSandboxMediaStreams(peerKey);
            }
            refreshSandboxAggregateCallState(peerKey);
            maybeAutoEndSandboxSoloCall(peerKey);
            refreshSandboxPeerChrome(peerKey);
            refreshSandboxMediaPanel(peerKey);
        };

        return pc;
    });
}

async function flushSandboxPendingIceCandidates(peerKey, remoteUserId) {
    const peer = state.sandbox.peers[peerKey];
    const pc = peer.peerConnections?.[remoteUserId];
    if (!pc?.remoteDescription) {
        return;
    }

    const pending = Array.isArray(peer.pendingIceCandidatesByUser?.[remoteUserId])
        ? peer.pendingIceCandidatesByUser[remoteUserId].splice(0)
        : [];
    for (const candidate of pending) {
        try {
            await pc.addIceCandidate(new RTCIceCandidate(candidate));
        } catch (error) {
            pushSandboxLog(`${peerKey}.rtc.ice.error`, {
                message: error instanceof Error ? error.message : "Failed to add queued ICE candidate.",
                remote_user_id: remoteUserId,
            });
        }
    }
}

async function negotiateSandboxOfferToRemote(peerKey, remoteUserId, modeOverride = "") {
    const peer = state.sandbox.peers[peerKey];
    if (!remoteUserId || !peer.callRoomJoined || !peer.localStream) {
        return;
    }

    const mode = String(modeOverride || peer.callMode || "audio");
    const pc = ensureSandboxPeerConnectionForRemote(peerKey, remoteUserId);
    const offer = await pc.createOffer({
        offerToReceiveAudio: true,
        offerToReceiveVideo: mode === "video",
    });
    await pc.setLocalDescription(offer);
    sendSandboxCallSignal(peerKey, "offer", {
        sdp: offer.sdp || "",
        targetUserId: remoteUserId,
        meta: { mode },
    });
}

async function ensureSandboxMeshOffersToRoomParticipants(peerKey, modeOverride = "") {
    const peer = state.sandbox.peers[peerKey];
    const participants = getSandboxRosterParticipantsForPeer(peerKey);
    for (const participant of participants) {
        if (!shouldSandboxPeerOfferToRemote(peerKey, participant.userId)) {
            continue;
        }
        if (peer.peerConnections?.[participant.userId]) {
            continue;
        }
        if (peer.incomingOffers?.[participant.userId]) {
            continue;
        }
        await negotiateSandboxOfferToRemote(peerKey, participant.userId, modeOverride);
    }
}

async function answerSandboxOffer(peerKey, remoteUserId, mode, sdp) {
    const peer = state.sandbox.peers[peerKey];
    const pc = ensureSandboxPeerConnectionForRemote(peerKey, remoteUserId);
    await pc.setRemoteDescription(new RTCSessionDescription({
        type: "offer",
        sdp: normalizeRealtimeSdp(sdp),
    }));
    await flushSandboxPendingIceCandidates(peerKey, remoteUserId);
    const answer = await pc.createAnswer();
    await pc.setLocalDescription(answer);
    peer.callMode = mode === "video" ? "video" : (peer.callMode || "audio");
    delete peer.incomingOffers[remoteUserId];
    refreshSandboxIncomingCallSummary(peerKey);
    sendSandboxCallSignal(peerKey, "answer", {
        sdp: answer.sdp || "",
        targetUserId: remoteUserId,
        meta: { mode },
    });
}

function sendSandboxCallSignal(peerKey, signalType, options = {}) {
    const peer = state.sandbox.peers[peerKey];
    if (!peer.socket || !peer.callRoomJoined) {
        return null;
    }

    return sendSandboxEnvelope(peerKey, "call.signal.publish", peer.callRoom, buildCallSignalPayload(signalType, options));
}

async function handleSandboxCallSignalEvent(peerKey, payload) {
    const peer = state.sandbox.peers[peerKey];
    const signalType = String(payload?.signal_type || "").trim();
    const sender = payload?.sender || {};
    const senderUserId = String(sender.user_id || "").trim();
    const currentUserId = String(peer.session?.user_id || peer.userId || "").trim();
    const targetUserId = String(payload?.target_user_id || "").trim();
    if (senderUserId && senderUserId === currentUserId) {
        return;
    }
    if (targetUserId && currentUserId && targetUserId !== currentUserId) {
        return;
    }

    const meta = parseRealtimeSignalJson(payload?.meta_json);
    const candidate = parseRealtimeSignalJson(payload?.candidate_json);
    const mode = String(meta?.mode || peer.callMode || "audio");
    const senderDisplayName = String(sender.display_name || meta?.display_name || senderUserId || "Caller").trim();
    if (senderUserId) {
        peer.participantDisplayNames[senderUserId] = senderDisplayName;
    }

    if (signalType === "ring") {
        if (!isSandboxCallActive(peer)) {
            peer.incomingCall = {
                userId: senderUserId,
                displayName: senderDisplayName,
                mode,
            };
            peer.callState = "incoming";
            void playSandboxRingtone(peerKey);
            refreshSandboxPeerChrome(peerKey);
            showToast(`${peer.incomingCall.displayName} is calling.`, { title: "Sandbox", type: "info" });
        }
        return;
    }

    if (signalType === "offer") {
        if (["connecting", "connected"].includes(String(peer.callState || ""))) {
            stopSandboxRingtone(peerKey);
            await ensureSandboxLocalMedia(peerKey, mode);
            await answerSandboxOffer(peerKey, senderUserId, mode, String(payload?.sdp || ""));
            refreshSandboxAggregateCallState(peerKey);
            refreshSandboxPeerChrome(peerKey);
            refreshSandboxMediaPanel(peerKey);
            await ensureSandboxMeshOffersToRoomParticipants(peerKey, peer.callMode || mode);
            return;
        }

        peer.incomingOffers[senderUserId] = {
            userId: senderUserId,
            displayName: senderDisplayName,
            mode,
            sdp: String(payload?.sdp || ""),
        };
        refreshSandboxIncomingCallSummary(peerKey);
        peer.callState = "incoming";
        void playSandboxRingtone(peerKey);
        refreshSandboxPeerChrome(peerKey);
        return;
    }

    if (signalType === "answer") {
        stopSandboxRingtone(peerKey);
        const pc = ensureSandboxPeerConnectionForRemote(peerKey, senderUserId);
        await pc.setRemoteDescription(new RTCSessionDescription({
            type: "answer",
            sdp: normalizeRealtimeSdp(payload?.sdp),
        }));
        await flushSandboxPendingIceCandidates(peerKey, senderUserId);
        refreshSandboxAggregateCallState(peerKey);
        refreshSandboxPeerChrome(peerKey);
        refreshSandboxMediaPanel(peerKey);
        await ensureSandboxMeshOffersToRoomParticipants(peerKey, peer.callMode || mode);
        return;
    }

    if (signalType === "ice-candidate" && candidate) {
        const pc = peer.peerConnections?.[senderUserId];
        if (pc?.remoteDescription) {
            await pc.addIceCandidate(new RTCIceCandidate(candidate));
        } else {
            if (!Array.isArray(peer.pendingIceCandidatesByUser[senderUserId])) {
                peer.pendingIceCandidatesByUser[senderUserId] = [];
            }
            peer.pendingIceCandidatesByUser[senderUserId].push(candidate);
        }
        return;
    }

    if (signalType === "hangup") {
        const remotePc = peer.peerConnections?.[senderUserId];
        if (remotePc) {
            try {
                remotePc.close();
            } catch {
                // noop
            }
        }
        delete peer.peerConnections?.[senderUserId];
        delete peer.remoteStreams?.[senderUserId];
        delete peer.pendingIceCandidatesByUser?.[senderUserId];
        delete peer.incomingOffers?.[senderUserId];
        peer.remoteAudioGraphs?.[senderUserId]?.destroy?.();
        delete peer.remoteAudioGraphs?.[senderUserId];
        delete peer.remoteAudioGraphHosts?.[senderUserId];
        refreshSandboxIncomingCallSummary(peerKey);
        refreshSandboxAggregateCallState(peerKey);
        maybeAutoEndSandboxSoloCall(peerKey);
        attachSandboxMediaStreams(peerKey);
        refreshSandboxPeerChrome(peerKey);
        refreshSandboxMediaPanel(peerKey);
        showToast("Remote party ended the call.", { title: "Sandbox", type: "warning" });
    }
}

function ensureSandboxRingtone(peerKey) {
    const peer = state.sandbox.peers[peerKey];
    if (peer.ringtoneAudio) {
        return peer.ringtoneAudio;
    }

    const audio = new Audio(SANDBOX_RINGTONE_SRC);
    audio.loop = true;
    audio.preload = "auto";
    peer.ringtoneAudio = audio;
    return audio;
}

async function playSandboxRingtone(peerKey) {
    const audio = ensureSandboxRingtone(peerKey);
    try {
        audio.currentTime = 0;
        await audio.play();
    } catch {
        // Browser autoplay policy may block this until the operator interacts.
    }
}

function stopSandboxRingtone(peerKey) {
    const peer = state.sandbox.peers[peerKey];
    const audio = peer.ringtoneAudio;
    if (!audio) {
        return;
    }

    try {
        audio.pause();
        audio.currentTime = 0;
    } catch {
        // noop
    }
}

function toggleSandboxMic(peerKey) {
    const peer = state.sandbox.peers[peerKey];
    peer.localStream?.getAudioTracks?.().forEach((track) => {
        track.enabled = !track.enabled;
        peer.isMicEnabled = track.enabled;
    });
    refreshSandboxPeerChrome(peerKey);
}

async function toggleSandboxCamera(peerKey) {
    const peer = state.sandbox.peers[peerKey];
    const currentTrack = peer.localStream?.getVideoTracks?.()[0] || null;

    if (peer.callMode === "video" && currentTrack) {
        currentTrack.enabled = false;
        peer.isCameraEnabled = false;
        peer.callMode = "audio";
        attachSandboxMediaStreams(peerKey);
        refreshSandboxPeerChrome(peerKey);
        refreshSandboxMediaPanel(peerKey);
        return;
    }

    try {
        const track = await ensureSandboxVideoTrack(peerKey);
        const remoteUserIds = Object.keys(peer.peerConnections || {});
        for (const remoteUserId of remoteUserIds) {
            const pc = ensureSandboxPeerConnectionForRemote(peerKey, remoteUserId);
            const sender = pc.getSenders().find((candidate) => candidate.track?.kind === "video");
            if (sender) {
                await sender.replaceTrack(track);
            } else {
                pc.addTrack(track, peer.localStream);
            }
        }

        peer.callMode = "video";
        peer.isCameraEnabled = true;
        refreshSandboxPeerChrome(peerKey);
        refreshSandboxMediaPanel(peerKey);

        if (["connecting", "connected"].includes(String(peer.callState || ""))) {
            const remoteUserIds = Object.keys(peer.peerConnections || {});
            for (const remoteUserId of remoteUserIds) {
                const targetPc = ensureSandboxPeerConnectionForRemote(peerKey, remoteUserId);
                const offer = await targetPc.createOffer();
                await targetPc.setLocalDescription(offer);
                sendSandboxCallSignal(peerKey, "offer", {
                    sdp: offer.sdp || "",
                    targetUserId: remoteUserId,
                    meta: { mode: "video", renegotiate: true },
                });
            }
        }
    } catch (error) {
        showToast(error instanceof Error ? error.message : "Unable to enable the camera.", { title: "Sandbox", type: "error" });
    }
}

async function sendSandboxChatMessage(peerKey, payload) {
    const sandbox = state.sandbox;
    const peer = sandbox.peers[peerKey];
    if (!peer.socket || peer.socket.readyState !== WebSocket.OPEN || !peer.roomJoined) {
        showToast("Connect and join the sandbox room before sending chat.", { title: "Sandbox", type: "warning" });
        return;
    }

    if (peer.uploadHydration) {
        await peer.uploadHydration;
    }

    const text = String(payload?.text || "").trim();
    if (!text) {
        return;
    }

    const localId = `local_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`;
    const draftAttachments = peer.uploads.map(createThreadAttachment);
    const transportAttachments = [];

    for (const upload of peer.uploads) {
        transportAttachments.push(await transferSandboxAttachment(peerKey, upload));
    }

    const requestId = sendSandboxEnvelope(peerKey, "chat.message.publish", peer.effectiveRoom, buildChatPublishPayload(text, transportAttachments));

    if (!requestId) {
        showToast("Unable to send chat because the realtime socket is not open.", { title: "Sandbox", type: "error" });
        return;
    }

    peer.messages.push({
        id: localId,
        direction: "outgoing",
        senderName: peer.session?.display_name || peer.displayName,
        text,
        timestamp: new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }),
        state: "sending",
        attachments: draftAttachments,
        meta: {
            senderUserId: peer.session?.user_id || peer.userId,
        },
    });

    peer.pendingOutgoing.push({
        requestId,
        localId,
        text,
        attachments: draftAttachments,
        userId: peer.session?.user_id || peer.userId,
        serverMessageId: null,
    });

    peer.thread?.setMessages?.(peer.messages);
    peer.composer?.clear?.();

    window.setTimeout(() => {
        const currentPeer = state.sandbox.peers[peerKey];
        currentPeer.uploads = [];
        currentPeer.uploadQueue?.setItems?.(currentPeer.uploads);
    }, 1500);
}

function normalizeSandboxChatEvent(payload, peerKey) {
    const peer = state.sandbox.peers[peerKey];
    return normalizeChatMessageEvent(payload, {
        currentUserId: String(peer.session?.user_id || peer.userId || "").trim(),
        resolveAttachmentUrl: (attachment, field) => resolveSandboxAttachmentUrl(peer, attachment, field),
        fallbackSenderName: "Realtime user",
    });
}

function formatSandboxTimestamp(value) {
    return formatRealtimeTimestamp(value);
}

function inferSandboxAttachmentKind(file) {
    return inferAttachmentKind(file);
}

function shouldPreviewSandboxFile(file) {
    return shouldPreviewAttachmentFile(file);
}

function formatFileSize(size) {
    return formatAttachmentFileSize(size);
}

function readSandboxFileAsDataUrl(file) {
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

function updateSandboxUploadItem(peerKey, itemId, patch = {}) {
    const peer = state.sandbox.peers[peerKey];
    let changed = false;
    peer.uploads = peer.uploads.map((item) => {
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
        peer.uploadQueue?.setItems?.(peer.uploads);
    }
}

async function transferSandboxAttachment(peerKey, item) {
    const transferId = item.transferId || `xfer_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`;
    item.transferId = transferId;
    const attachment = await transferAttachmentInChunks(item, {
        onChunk: (chunkPayload) => {
            sendSandboxEnvelope(peerKey, "sandbox.attachment.chunk.publish", state.sandbox.peers[peerKey].effectiveRoom, chunkPayload);
        },
        onProgress: (progress, progressLabel) => {
            updateSandboxUploadItem(peerKey, item.id, {
                status: progress >= 100 ? "uploaded" : "uploading",
                progress,
                progressLabel,
            });
        },
    });
    item.mimeType = attachment.mime_type || item.mimeType || "";
    return attachment;
}

function absorbSandboxAttachmentChunk(peer, payload) {
    const transferId = String(payload?.transfer_id || "").trim();
    peer.receivedAttachments = reduceAttachmentChunkStore(peer.receivedAttachments, payload);
    const current = transferId ? peer.receivedAttachments?.[transferId] : null;
    if (!current?.completed) {
        return;
    }

    let updated = false;
    peer.messages = peer.messages.map((message) => {
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
        peer.thread?.setMessages?.(peer.messages);
    }
}

function resolveSandboxAttachmentUrl(peer, attachment, field) {
    return resolveAttachmentUrlFromStore(peer.receivedAttachments, attachment, field);
}

function isSandboxChunkLogLabel(label) {
    return String(label || "").includes("sandbox.attachment.chunk.");
}

function createSandboxLogEntry(label, payload) {
    const timestamp = new Date().toISOString();
    return {
        id: `log_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`,
        timestamp,
        label: String(label || "sandbox.event"),
        payload: payload ?? {},
        text: JSON.stringify(payload ?? {}, null, 2),
    };
}

function pushSandboxLog(label, payload) {
    if (isSandboxChunkLogLabel(label)) {
        return;
    }

    const sandbox = state.sandbox;
    sandbox.logs.unshift(createSandboxLogEntry(label, payload));
    sandbox.logs = sandbox.logs.slice(0, SANDBOX_LOG_LIMIT);

    renderSandboxLogs();
}

function renderSandboxLogs() {
    const logs = state.sandbox.logs;
    if (state.sandbox.logList) {
        state.sandbox.logList.setItems?.(logs);
    }
    return logs.length ? "" : "No sandbox events yet.";
}

function renderSandboxLogRow(entry) {
    const preview = entry.text.length > 240 ? `${entry.text.slice(0, 240)}...` : entry.text;
    const row = document.createElement("article");
    row.className = "sandbox-log-entry";
    row.title = entry.text;

    const head = document.createElement("div");
    head.className = "sandbox-log-entry-head";

    const time = document.createElement("span");
    time.className = "sandbox-log-entry-time";
    time.textContent = `[${entry.timestamp}]`;

    const label = document.createElement("strong");
    label.className = "sandbox-log-entry-label";
    label.textContent = entry.label;

    const body = document.createElement("pre");
    body.className = "sandbox-log-entry-body";
    body.textContent = preview;

    head.appendChild(time);
    head.appendChild(label);
    row.appendChild(head);
    row.appendChild(body);
    return row;
}

function destroySandboxPeerUiInstances(peer) {
    peer.localAudioGraph?.destroy?.();
    Object.values(peer.remoteAudioGraphs || {}).forEach((graph) => {
        graph?.destroy?.();
    });
    peer.thread?.destroy?.();
    peer.composer?.destroy?.();
    peer.uploadQueue?.destroy?.();
    peer.localAudioGraph = null;
    peer.localAudioGraphHost = null;
    peer.remoteAudioGraphs = {};
    peer.remoteAudioGraphHosts = {};
    peer.thread = null;
    peer.composer = null;
    peer.uploadQueue = null;
}

function formatSandboxPeerStatusLabel(peer) {
    return peer.connectionStatus === "connected" && peer.roomJoined
        ? "Connected"
        : peer.connectionStatus === "connecting"
            ? "Connecting"
            : "Disconnected";
}

function renderSandboxPeerPanel(peerKey, title, peer) {
    return `
        <section class="panel panel-stack sandbox-chat-panel sandbox-peer-panel">
            <div class="sandbox-peer-head">
                <div class="sandbox-peer-titlebar">
                    <div class="sandbox-peer-heading">
                        <p class="eyebrow">Chat</p>
                        <h2 class="section-title">${escapeHtml(title)}</h2>
                    </div>
                    <div id="sandboxPeerActions-${peerKey}" class="grid-actions">
                        ${renderSandboxPeerActionButtons(peerKey, peer)}
                    </div>
                </div>
                <div>
                    <div class="tiny muted">Independent websocket session using the shared room and project scope.</div>
                </div>
                <div id="sandboxPeerMeta-${peerKey}" class="sandbox-peer-meta">
                    ${renderSandboxPeerMeta(peer)}
                </div>
            </div>
            <div id="sandboxThreadHost-${peerKey}" class="sandbox-thread-host"></div>
            <div id="sandboxUploadQueueHost-${peerKey}" class="sandbox-upload-host"></div>
            <div class="tiny muted">Chunked attachment transfer in this sandbox is demo-only. Production project ingestion should stay project-owned.</div>
            <div id="sandboxComposerHost-${peerKey}" class="sandbox-composer-host"></div>
        </section>
    `;
}

function renderSandboxPeerActionButtons(peerKey, peer) {
    const isCallLive = isSandboxCallActive(peer);
    const canAnswer = peer.callState === "incoming" && getSandboxIncomingOfferEntries(peer).length > 0;
    const canStartCall = peer.connectionStatus === "connected" && peer.callRoomJoined && !isCallLive;
    const hasAudioTrack = Boolean(peer.localStream?.getAudioTracks?.().length);
    const hasEnabledLocalVideo = Boolean(peer.localStream?.getVideoTracks?.().some((track) => track.enabled));
    const showMuteMic = isCallLive && hasAudioTrack && peer.isMicEnabled;
    const showUnmuteMic = isCallLive && hasAudioTrack && !peer.isMicEnabled;
    const showCameraOff = isCallLive && hasEnabledLocalVideo && peer.isCameraEnabled;
    const showCameraOn = isCallLive && !hasEnabledLocalVideo;

    return `
        ${canStartCall ? `<button type="button" class="button button-ghost" id="sandboxAudioCallButton-${peerKey}">Audio call</button>` : ""}
        ${canStartCall ? `<button type="button" class="button button-ghost" id="sandboxVideoCallButton-${peerKey}">Video call</button>` : ""}
        ${canAnswer ? `<button type="button" class="button" id="sandboxAnswerCallButton-${peerKey}">Answer</button>` : ""}
        ${isCallLive ? `<button type="button" class="button button-ghost" id="sandboxEndCallButton-${peerKey}">End</button>` : ""}
        ${showMuteMic ? `<button type="button" class="button button-ghost" id="sandboxToggleMicButton-${peerKey}">Mute mic</button>` : ""}
        ${showUnmuteMic ? `<button type="button" class="button button-ghost" id="sandboxToggleMicButton-${peerKey}">Unmute mic</button>` : ""}
        ${showCameraOn ? `<button type="button" class="button button-ghost" id="sandboxToggleCameraButton-${peerKey}">Camera on</button>` : ""}
        ${showCameraOff ? `<button type="button" class="button button-ghost" id="sandboxToggleCameraButton-${peerKey}">Camera off</button>` : ""}
    `;
}

function renderSandboxPeerMeta(peer) {
    const connectionLabel = formatSandboxPeerStatusLabel(peer);
    const presenceLabel = peer.presenceState ? formatLabel(peer.presenceState) : "Offline";

    return `
        <div class="sandbox-peer-meta-item">
            <span class="sandbox-peer-meta-label">Connection</span>
            <span class="sandbox-peer-meta-value">${renderBadge(connectionLabel)}</span>
        </div>
        <div class="sandbox-peer-meta-item">
            <span class="sandbox-peer-meta-label">Presence</span>
            <span class="sandbox-peer-meta-value">${renderBadge(presenceLabel)}</span>
        </div>
        <div class="sandbox-peer-meta-item">
            <span class="sandbox-peer-meta-label">Call</span>
            <span class="sandbox-peer-meta-value">${renderBadge(formatLabel(peer.callState || "idle"))}</span>
        </div>
    `;
}

function renderSandboxMediaPanel(peerKey, peer) {
    const remoteItems = buildSandboxRemoteMediaItems(peerKey, peer);

    return `
        <section id="sandboxMediaPanel-${peerKey}" class="panel panel-stack sandbox-media-panel">
            <div class="sandbox-column-heading">
                <p class="eyebrow">Media</p>
                <h2 class="section-title">Display</h2>
            </div>
            <div class="sandbox-media-display">
                <section class="sandbox-media-section">
                    <div class="sandbox-media-section-head">
                        <span class="eyebrow">Local media</span>
                    </div>
                    <div class="sandbox-media-card">
                        <div class="sandbox-media-frame">
                            <video id="sandboxLocalVideo-${peerKey}" class="sandbox-media-video" autoplay playsinline muted></video>
                            <div id="sandboxLocalAudioGraph-${peerKey}" class="sandbox-audiograph-host sandbox-audiograph-overlay" aria-label="Local audio graph"></div>
                        </div>
                        <div class="sandbox-media-caption">
                            <strong>${escapeHtml(peer.displayName || "Local peer")}</strong>
                            <span class="muted">${escapeHtml(renderSandboxLocalMediaLabel(peer))}</span>
                        </div>
                    </div>
                </section>
                <section class="sandbox-media-section sandbox-media-section-remote">
                    <div class="sandbox-media-section-head">
                        <span class="eyebrow">Remote media</span>
                    </div>
                    <div class="sandbox-remote-media-stack">
                        ${remoteItems}
                    </div>
                </section>
            </div>
        </section>
    `;
}

function buildSandboxRemoteMediaItems(peerKey, peer) {
    const remoteEntries = getSandboxRemoteMediaEntries(peer);
    if (!remoteEntries.length) {
        return `
            <div class="sandbox-media-card sandbox-media-card-empty">
                <div class="muted">No remote media streams yet.</div>
            </div>
        `;
    }

    return remoteEntries.map(({ userId, displayName, stream }) => {
        return `
            <article class="sandbox-media-card sandbox-remote-media-item">
                <div class="sandbox-media-frame">
                    <video
                        data-sandbox-remote-video-peer="${escapeHtml(peerKey)}"
                        data-sandbox-remote-user="${escapeHtml(userId)}"
                        class="sandbox-media-video"
                        autoplay
                        playsinline
                    ></video>
                    <div
                        data-sandbox-remote-audiograph-peer="${escapeHtml(peerKey)}"
                        data-sandbox-remote-user="${escapeHtml(userId)}"
                        class="sandbox-audiograph-host sandbox-audiograph-overlay"
                        aria-label="Remote audio graph"
                    ></div>
                </div>
                <div class="sandbox-media-caption">
                    <strong>${escapeHtml(displayName || userId || "Remote peer")}</strong>
                    <span class="muted">${escapeHtml(renderSandboxRemoteMediaLabel(stream))}</span>
                </div>
            </article>
        `;
    }).join("");
}

function renderSandboxLocalMediaLabel(peer) {
    const hasAudioTrack = Boolean(peer.localStream?.getAudioTracks?.().length);
    const hasVideoTrack = Boolean(peer.localStream?.getVideoTracks?.().length && peer.isCameraEnabled);
    if (hasVideoTrack && hasAudioTrack) {
        return "Audio + video";
    }
    if (hasVideoTrack) {
        return "Video only";
    }
    if (hasAudioTrack) {
        return "Audio only";
    }
    return "No local media";
}

function renderSandboxRemoteMediaLabel(stream) {
    const hasAudioTrack = Boolean(stream?.getAudioTracks?.().length);
    const hasVideoTrack = Boolean(stream?.getVideoTracks?.().length);
    if (hasVideoTrack && hasAudioTrack) {
        return "Remote audio + video";
    }
    if (hasVideoTrack) {
        return "Remote video";
    }
    if (hasAudioTrack) {
        return "Remote audio";
    }
    return "No remote media";
}

function renderSandboxInspectorCard(title, peer, defaultWebsocketUrl, selectedProject) {
    return `
        <div class="sandbox-context-card">
            <div class="eyebrow">${escapeHtml(title)}</div>
            <div class="detail-row">
                <dt>WebSocket</dt>
                <dd>${escapeHtml(peer.websocketUrl || defaultWebsocketUrl || "Not connected")}</dd>
            </div>
            <div class="detail-row">
                <dt>Session token</dt>
                <dd>${escapeHtml(peer.session?.token_id || "Not issued")}</dd>
            </div>
            <div class="detail-row">
                <dt>Project code</dt>
                <dd>${escapeHtml(peer.session?.project_code || selectedProject?.project_code || "Not selected")}</dd>
            </div>
            <div class="detail-row">
                <dt>Presence</dt>
                <dd>${escapeHtml(formatLabel(peer.presenceState || "offline"))}</dd>
            </div>
            <div class="detail-row">
                <dt>Expires</dt>
                <dd>${escapeHtml(peer.tokenExpiresAt || "Not connected")}</dd>
            </div>
            <div class="detail-row">
                <dt>Reconnect count</dt>
                <dd>${escapeHtml(String(peer.reconnectCount || 0))}</dd>
            </div>
            <div class="detail-row">
                <dt>Latest error</dt>
                <dd>${escapeHtml(peer.lastError || "None")}</dd>
            </div>
        </div>
    `;
}

function renderSandboxCallHint(peer) {
    if (peer.callState === "incoming") {
        const caller = String(peer.incomingCall?.displayName || peer.incomingCall?.userId || "Caller").trim();
        const mode = formatLabel(peer.incomingCall?.mode || "audio");
        return `${caller} is calling (${mode}).`;
    }

    if (peer.callState === "outgoing") {
        return `Calling through ${peer.callRoom || normalizeSandboxCallRoom(state.sandbox.room)}.`;
    }

    if (peer.callState === "connected") {
        return `Connected on ${peer.callRoom || normalizeSandboxCallRoom(state.sandbox.room)}.`;
    }

    if (peer.callState === "connecting") {
        return "Negotiating audio/video session.";
    }

    return `Call room: ${peer.callRoom || normalizeSandboxCallRoom(state.sandbox.room)}.`;
}

function renderSandboxSessionHeaderActions(peer) {
    const isSocketLive = Boolean(peer.socket) && ["connecting", "connected"].includes(String(peer.connectionStatus || ""));
    const canReconnect = !isSocketLive && hasSandboxPeerConnectedBefore(peer);

    return `
        ${isSocketLive ? "" : `<button type="button" class="button" id="sandboxPageConnectButton">${canReconnect ? "Reconnect" : "Connect"}</button>`}
        ${isSocketLive ? `<button type="button" class="button button-ghost" id="sandboxPageDisconnectButton">Disconnect</button>` : ""}
    `;
}

function hasSandboxPeerConnectedBefore(peer) {
    return Boolean(peer.reconnectCount > 0 || peer.session || peer.effectiveRoom || peer.websocketUrl);
}

function isSandboxCallActive(peer) {
    return isRealtimeCallActive(peer.callState);
}

function renderProjectsIndexPage(data) {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    const items = Array.isArray(data?.items) ? data.items : [];
    const meta = data?.meta || {};
    const projectsGridHostId = "projectsGridHost";
    const projectNameWidth = measureStackedColumnWidth(items, {
        label: "Name",
        min: 300,
        max: 460,
        primary: (row) => row?.name || row?.project_code || "Project",
        secondary: (row) => row?.project_code || "",
    });
    const projectClientWidth = measureStackedColumnWidth(items, {
        label: "Client",
        min: 290,
        max: 420,
        primary: (row) => row?.client_name || row?.client_code || "Client",
        secondary: (row) => row?.client_code || "",
    });
    const projectStatusWidth = measureColumnWidth(items, {
        label: "Status",
        min: 110,
        max: 130,
        value: (row) => row?.status || "",
        charWidth: 9,
    });
    const policyProfileWidth = measureStackedColumnWidth(items, {
        label: "Policy profile",
        min: 320,
        max: 480,
        primary: (row) => row?.policy_profile_name || row?.policy_profile_code || "",
        secondary: (row) => row?.policy_profile_code || "",
    });
    const projectActionsWidth = 150;

    state.ui.projectsGrid?.destroy?.();
    state.ui.projectsGrid = null;

    pageHost.innerHTML = `
        <section class="panel panel-stack page-shell page-shell-fill">
            <div class="page-head">
                <div>
                    <p class="eyebrow">Projects</p>
                    <h1 class="page-title">Project scope management</h1>
                    <p class="page-lede">Define per-project browser access and policy boundaries for trusted clients.</p>
                </div>
                <div class="actions">
                    <button class="button" id="openNewProjectButton" type="button">New project scope</button>
                </div>
            </div>
            <div id="${projectsGridHostId}" class="page-grid-host" aria-label="Projects grid"></div>
            ${renderPager(meta, WEB.projects)}
        </section>
    `;

    document.getElementById("openNewProjectButton")?.addEventListener("click", () => {
        void openProjectModal("create");
    });

    const projectsGridHost = document.getElementById(projectsGridHostId);
    if (projectsGridHost && state.ui.grid) {
        state.ui.projectsGrid = state.ui.grid(projectsGridHost, items, {
            mode: "local",
            className: "page-grid projects-grid",
            chrome: false,
            columns: [
                {
                    key: "name",
                    label: "Name",
                    sortable: true,
                    resizable: true,
                    width: projectNameWidth,
                    renderCell: ({ row }) => buildRowText(row?.name || row?.project_code || "Project", row?.project_code || ""),
                },
                {
                    key: "client",
                    label: "Client",
                    sortable: true,
                    resizable: true,
                    width: projectClientWidth,
                    renderCell: ({ row }) => buildRowText(row?.client_name || row?.client_code || "Client", row?.client_code || ""),
                },
                {
                    key: "status",
                    label: "Status",
                    sortable: true,
                    resizable: true,
                    width: projectStatusWidth,
                    renderCell: ({ value }) => renderBadgeElement(value),
                },
                {
                    key: "policy_profile",
                    label: "Policy profile",
                    sortable: true,
                    resizable: true,
                    width: policyProfileWidth,
                    renderCell: ({ row }) => buildRowText(row?.policy_profile_name || row?.policy_profile_code || "", row?.policy_profile_code || ""),
                },
                {
                    key: "actions",
                    label: "Actions",
                    sortable: false,
                    resizable: true,
                    width: projectActionsWidth,
                    align: "right",
                    renderCell: ({ row }) => {
                        const host = document.createElement("div");
                        host.className = "grid-actions";

                        const viewButton = document.createElement("button");
                        viewButton.type = "button";
                        viewButton.className = "button-secondary tiny";
                        viewButton.textContent = "View";
                        viewButton.addEventListener("click", (event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            navigateShell(`${WEB.projects}/${encodeURIComponent(String(row?.project_code || "").trim())}`);
                        });

                        const editButton = document.createElement("button");
                        editButton.type = "button";
                        editButton.className = "button-secondary tiny";
                        editButton.textContent = "Edit";
                        editButton.addEventListener("click", (event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            void openProjectModal("edit", String(row?.project_code || "").trim());
                        });

                        host.append(viewButton, editButton);
                        return host;
                    },
                },
            ],
            selectable: "none",
            wrapCellContent: false,
            enableSort: true,
            enableSearch: false,
            enablePagination: false,
            enableColumnResize: true,
            onRowClick(row) {
                const projectCode = String(row?.project_code || "").trim();
                if (!projectCode) return;
                navigateShell(`${WEB.projects}/${encodeURIComponent(projectCode)}`);
            },
        });
    }

    pageHost.querySelectorAll("[data-page-nav]").forEach((button) => {
        button.addEventListener("click", () => {
            const target = String(button.getAttribute("data-page-nav") || "").trim();
            if (!target) return;
            navigateShell(target);
        });
    });

    pageHost.querySelectorAll("[data-project-open]").forEach((button) => {
        button.addEventListener("click", () => {
            const projectId = String(button.getAttribute("data-project-open") || "").trim();
            if (!projectId) return;
            navigateShell(`${WEB.projects}/${encodeURIComponent(projectId)}`);
        });
    });

    pageHost.querySelectorAll("[data-project-edit]").forEach((button) => {
        button.addEventListener("click", () => {
            const projectId = String(button.getAttribute("data-project-edit") || "").trim();
            if (!projectId) return;
            void openProjectModal("edit", projectId);
        });
    });
}

function renderProjectDetailPage(data) {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    const project = data?.project || {};
    pageHost.dataset.clientCode = String(project.client_code || "").trim();

    pageHost.innerHTML = `
        <section class="panel page-shell page-shell-fill detail-page-shell">
            <div class="page-head">
                <div>
                    <p class="eyebrow">Projects</p>
                    <h1 class="page-title">${escapeHtml(project.name || project.project_code || "Project")}</h1>
                    <div class="muted tiny">${escapeHtml(project.project_code || "")}</div>
                    <p class="page-lede">Detailed view for the project scope assigned to a trusted client.</p>
                    <div class="summary-chips">
                        <span class="summary-chip">
                            <span class="summary-chip-label">Status</span>
                            <span class="summary-chip-value">${renderBadge(project.status)}</span>
                        </span>
                        <span class="summary-chip">
                            <span class="summary-chip-label">Client</span>
                            <span class="summary-chip-value">${escapeHtml(project.client_name || project.client_code || "")}</span>
                        </span>
                        <span class="summary-chip">
                            <span class="summary-chip-label">Origin policy</span>
                            <span class="summary-chip-value">${escapeHtml(project.origin_policy_mode || "")}</span>
                        </span>
                    </div>
                </div>
                <div class="actions">
                    <button class="button-secondary" id="backToClientButton" type="button">Back to client</button>
                    <button class="button" id="editProjectButton" type="button">Edit</button>
                    <button class="button-danger" id="disableProjectButton" type="button">Deactivate</button>
                </div>
            </div>

            <div class="detail-page-body">
                <section class="detail-column detail-column-meta">
                    ${renderDetailCard("Context", [
                        ["Client", escapeHtml(project.client_name || project.client_code)],
                        ["Client code", escapeHtml(project.client_code)],
                        ["Summary", project.description],
                        ["Scope notes", project.scope_notes],
                    ])}
                </section>

                <section class="panel detail-card detail-scroll-surface">
                    <div class="row">
                        <div>
                            <p class="eyebrow">Access and policy</p>
                            <div class="muted tiny">${escapeHtml(project.project_code || "")}</div>
                        </div>
                    </div>
                    <div class="detail-scroll-body stack">
                        ${renderDetailCard("Policy", [
                            ["Policy profile", escapeHtml(project.policy_profile_name || project.policy_profile_code)],
                            ["Capability profile", escapeHtml(project.capability_profile_code)],
                            ["Room policy profile", escapeHtml(project.room_policy_profile_code)],
                        ], true)}
                        ${renderBlockCard("Allowed origins", `
                            <div class="stack">
                                ${renderCodeBlock((project.allowed_origins || []).join("\n") || "No allowed origins configured.")}
                            </div>
                        `)}
                    </div>
                </section>
            </div>
        </section>
    `;

    document.getElementById("backToClientButton")?.addEventListener("click", () => {
        if (project.client_code) {
            navigateShell(`${WEB.clients}/${encodeURIComponent(project.client_code)}`);
        } else {
            navigateShell(WEB.clients);
        }
    });

    document.getElementById("editProjectButton")?.addEventListener("click", () => {
        const projectId = String(project.project_code || "").trim();
        if (!projectId) return;
        void openProjectModal("edit", projectId);
    });

    document.getElementById("disableProjectButton")?.addEventListener("click", () => {
        void deactivateProject(project);
    });
}

function renderPoliciesIndexPage(data) {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    const items = Array.isArray(data?.items) ? data.items : [];
    const meta = data?.meta || {};
    const policiesGridHostId = "policiesGridHost";
    const policyNameWidth = measureStackedColumnWidth(items, {
        label: "Name",
        min: 260,
        max: 380,
        primary: (row) => row?.name || row?.policy_code || "Policy",
        secondary: (row) => row?.policy_code || "",
    });
    const policyStatusWidth = measureColumnWidth(items, {
        label: "Status",
        min: 120,
        max: 160,
        value: (row) => row?.status || "",
        charWidth: 9,
    });
    const policyCategoryWidth = measureColumnWidth(items, {
        label: "Category",
        min: 150,
        max: 220,
        value: (row) => row?.policy_category || "",
        charWidth: 8.6,
    });
    const allowDenyWidth = measureColumnWidth(items, {
        label: "Allow/deny",
        min: 150,
        max: 210,
        value: (row) => row?.allow_deny_mode || "",
        charWidth: 8.6,
    });
    const policyActionsWidth = 180;

    state.ui.policiesGrid?.destroy?.();
    state.ui.policiesGrid = null;

    pageHost.innerHTML = `
        <section class="panel panel-stack page-shell page-shell-fill">
            <div class="page-head">
                <div>
                    <p class="eyebrow">Policies</p>
                    <h1 class="page-title">Policy and capability management</h1>
                    <p class="page-lede">Review client-owned policies. Create and maintain policies from each client detail page.</p>
                </div>
            </div>
            <div id="${policiesGridHostId}" class="page-grid-host" aria-label="Policies grid"></div>
            ${renderPager(meta, WEB.policies)}
        </section>
    `;

    const policiesGridHost = document.getElementById(policiesGridHostId);
    if (policiesGridHost && state.ui.grid) {
        state.ui.policiesGrid = state.ui.grid(policiesGridHost, items, {
            mode: "local",
            className: "page-grid policies-grid",
            chrome: false,
            columns: [
                {
                    key: "name",
                    label: "Name",
                    sortable: true,
                    resizable: true,
                    width: policyNameWidth,
                    renderCell: ({ row }) => buildRowText(row?.name || row?.policy_code || "Policy", row?.policy_code || ""),
                },
                {
                    key: "client_name",
                    label: "Client",
                    sortable: true,
                    resizable: true,
                    width: 240,
                    renderCell: ({ row }) => buildRowText(row?.client_name || "Unassigned", row?.client_code || ""),
                },
                {
                    key: "status",
                    label: "Status",
                    sortable: true,
                    resizable: true,
                    width: policyStatusWidth,
                    renderCell: ({ value }) => renderBadgeElement(value),
                },
                {
                    key: "policy_category",
                    label: "Category",
                    sortable: true,
                    resizable: true,
                    width: policyCategoryWidth,
                },
                {
                    key: "allow_deny_mode",
                    label: "Allow/deny",
                    sortable: true,
                    resizable: true,
                    width: allowDenyWidth,
                },
                {
                    key: "actions",
                    label: "Actions",
                    sortable: false,
                    resizable: true,
                    width: policyActionsWidth,
                    align: "right",
                    renderCell: ({ row }) => {
                        const host = document.createElement("div");
                        host.className = "grid-actions";

                        const viewButton = document.createElement("button");
                        viewButton.type = "button";
                        viewButton.className = "button-secondary tiny";
                        viewButton.textContent = "View";
                        viewButton.addEventListener("click", (event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            navigateShell(`${WEB.policies}/${encodeURIComponent(String(row?.policy_code || "").trim())}`);
                        });

                        const editButton = document.createElement("button");
                        editButton.type = "button";
                        editButton.className = "button-secondary tiny";
                        editButton.textContent = "Edit";
                        editButton.addEventListener("click", (event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            void openPolicyModal("edit", String(row?.policy_code || "").trim());
                        });

                        host.append(viewButton, editButton);
                        return host;
                    },
                },
            ],
            selectable: "none",
            wrapCellContent: false,
            enableSort: true,
            enableSearch: false,
            enablePagination: false,
            enableColumnResize: true,
            onRowClick(row) {
                const policyCode = String(row?.policy_code || "").trim();
                if (!policyCode) return;
                navigateShell(`${WEB.policies}/${encodeURIComponent(policyCode)}`);
            },
        });
    }

    pageHost.querySelectorAll("[data-page-nav]").forEach((button) => {
        button.addEventListener("click", () => {
            const target = String(button.getAttribute("data-page-nav") || "").trim();
            if (!target) return;
            navigateShell(target);
        });
    });

    pageHost.querySelectorAll("[data-policy-open]").forEach((button) => {
        button.addEventListener("click", () => {
            const policyId = String(button.getAttribute("data-policy-open") || "").trim();
            if (!policyId) return;
            navigateShell(`${WEB.policies}/${encodeURIComponent(policyId)}`);
        });
    });

    pageHost.querySelectorAll("[data-policy-edit]").forEach((button) => {
        button.addEventListener("click", () => {
            const policyId = String(button.getAttribute("data-policy-edit") || "").trim();
            if (!policyId) return;
            void openPolicyModal("edit", policyId);
        });
    });
}

function renderPolicyDetailPage(data) {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    const policy = data?.policy || {};

    pageHost.innerHTML = `
        <section class="panel page-shell page-shell-fill detail-page-shell">
            <div class="page-head">
                <div>
                    <p class="eyebrow">Policies</p>
                    <h1 class="page-title">${escapeHtml(policy.name || policy.policy_code || "Policy")}</h1>
                    <div class="muted tiny">${escapeHtml(policy.policy_code || "")}</div>
                    <p class="page-lede">Detailed view for the policy and capability profile assigned to realtime clients.</p>
                    <div class="summary-chips">
                        <span class="summary-chip">
                            <span class="summary-chip-label">Status</span>
                            <span class="summary-chip-value">${renderBadge(policy.status)}</span>
                        </span>
                        <span class="summary-chip">
                            <span class="summary-chip-label">Client</span>
                            <span class="summary-chip-value">${escapeHtml(policy.client_name || "Unassigned")}</span>
                        </span>
                        <span class="summary-chip">
                            <span class="summary-chip-label">Category</span>
                            <span class="summary-chip-value">${escapeHtml(policy.policy_category || "")}</span>
                        </span>
                        <span class="summary-chip">
                            <span class="summary-chip-label">Allow/deny</span>
                            <span class="summary-chip-value">${escapeHtml(policy.allow_deny_mode || "")}</span>
                        </span>
                    </div>
                </div>
                <div class="actions">
                    <button class="button-secondary" id="backToPoliciesButton" type="button">${policy.client_code ? "Back to client" : "Back to list"}</button>
                    <button class="button" id="editPolicyButton" type="button">Edit</button>
                    <button class="button-danger" id="deprecatePolicyButton" type="button">Deprecate</button>
                </div>
            </div>

            <div class="detail-page-body">
                <section class="detail-column detail-column-meta">
                    ${renderDetailCard("Notes", [
                        ["Owned by client", escapeHtml(policy.client_name || policy.client_code || "Unassigned")],
                        ["Description", policy.description || "No description provided."],
                    ])}
                </section>

                <section class="panel detail-card detail-scroll-surface">
                    <div class="row">
                        <div>
                            <p class="eyebrow">Profiles</p>
                            <div class="muted tiny">${escapeHtml(policy.policy_code || "")}</div>
                        </div>
                    </div>
                    <div class="detail-scroll-body stack">
                        ${renderDetailCard("Capability profile", [
                            ["Definition", renderCodeBlock(formatStructuredValue(policy.capability_profile))],
                        ], true)}
                        ${renderDetailCard("Room policy profile", [
                            ["Definition", renderCodeBlock(formatStructuredValue(policy.room_policy_profile))],
                        ], true)}
                        ${renderDetailCard("Rate limit profile", [
                            ["Definition", renderCodeBlock(formatStructuredValue(policy.rate_limit_profile))],
                        ], true)}
                        ${renderDetailCard("Session limit profile", [
                            ["Definition", renderCodeBlock(formatStructuredValue(policy.session_limit_profile))],
                        ], true)}
                    </div>
                </section>
            </div>
        </section>
    `;

    document.getElementById("backToPoliciesButton")?.addEventListener("click", () => {
        if (policy.client_code) {
            navigateShell(`${WEB.clients}/${encodeURIComponent(policy.client_code)}`);
            return;
        }
        navigateShell("/admin/policies");
    });

    document.getElementById("editPolicyButton")?.addEventListener("click", () => {
        const policyId = String(policy.policy_code || "").trim();
        if (!policyId) return;
        void openPolicyModal("edit", policyId, {
            id: String(policy.client_id || "").trim(),
            code: String(policy.client_code || "").trim(),
            label: `${String(policy.client_name || policy.client_code || "Client").trim()} (${String(policy.client_code || "").trim()})`,
            name: String(policy.client_name || policy.client_code || "Client").trim(),
        });
    });

    document.getElementById("deprecatePolicyButton")?.addEventListener("click", () => {
        void deprecatePolicy(policy);
    });
}

function renderSessionsPage(data) {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    const items = Array.isArray(data?.items) ? data.items : [];
    const meta = data?.meta || {};
    const sessionsGridHostId = "sessionsGridHost";
    const clientWidth = measureStackedColumnWidth(items, {
        label: "Client",
        min: 240,
        max: 360,
        primary: (row) => row?.client_name || row?.client_code || "Client",
        secondary: (row) => row?.client_code || "",
    });
    const projectWidth = measureStackedColumnWidth(items, {
        label: "Project",
        min: 260,
        max: 380,
        primary: (row) => row?.project_name || row?.project_code || "Project",
        secondary: (row) => row?.project_code || "",
    });
    const userWidth = measureColumnWidth(items, {
        label: "User",
        min: 180,
        max: 260,
        value: (row) => row?.display_name || row?.user_identity || "",
        charWidth: 8.6,
    });
    const sessionWidth = measureColumnWidth(items, {
        label: "Session",
        min: 260,
        max: 360,
        value: (row) => row?.session_id || "",
        charWidth: 8.2,
    });
    const statusWidth = measureColumnWidth(items, {
        label: "Status",
        min: 120,
        max: 160,
        value: (row) => row?.status || "",
    });
    const roomsWidth = measureColumnWidth(items, {
        label: "Rooms",
        min: 90,
        max: 120,
        value: (row) => row?.room_count ?? 0,
        charWidth: 8,
    });
    const activityWidth = measureColumnWidth(items, {
        label: "Last activity",
        min: 170,
        max: 220,
        value: (row) => formatDateTime(row?.last_activity_at),
        charWidth: 8.4,
    });

    state.ui.sessionsGrid?.destroy?.();
    state.ui.sessionsGrid = null;

    pageHost.innerHTML = `
        <section class="panel panel-stack page-shell page-shell-fill">
            <div class="page-head">
                <div>
                    <p class="eyebrow">Sessions</p>
                    <h1 class="page-title">Session monitoring</h1>
                    <p class="page-lede">Read-only visibility into realtime sessions and connection state.</p>
                </div>
            </div>
            <div id="${sessionsGridHostId}" class="page-grid-host" aria-label="Sessions grid"></div>
            ${renderPager(meta, WEB.sessions)}
        </section>
    `;

    const host = document.getElementById(sessionsGridHostId);
    if (host && state.ui.grid) {
        state.ui.sessionsGrid = state.ui.grid(host, items, {
            mode: "local",
            className: "sessions-grid",
            chrome: false,
            columns: [
                {
                    key: "client",
                    label: "Client",
                    sortable: true,
                    resizable: true,
                    width: clientWidth,
                    value: (row) => String(row?.client_name || row?.client_code || ""),
                    renderCell: ({ row }) => {
                        const shell = document.createElement("div");
                        shell.className = "grid-stacked";
                        const title = document.createElement("strong");
                        title.textContent = String(row?.client_name || row?.client_code || "Client");
                        title.title = title.textContent;
                        const subtext = document.createElement("div");
                        subtext.className = "muted tiny";
                        subtext.textContent = String(row?.client_code || "");
                        subtext.title = subtext.textContent;
                        shell.append(title, subtext);
                        return shell;
                    },
                },
                {
                    key: "project",
                    label: "Project",
                    sortable: true,
                    resizable: true,
                    width: projectWidth,
                    value: (row) => String(row?.project_name || row?.project_code || ""),
                    renderCell: ({ row }) => {
                        const shell = document.createElement("div");
                        shell.className = "grid-stacked";
                        const title = document.createElement("strong");
                        title.textContent = String(row?.project_name || row?.project_code || "Project");
                        title.title = title.textContent;
                        const subtext = document.createElement("div");
                        subtext.className = "muted tiny";
                        subtext.textContent = String(row?.project_code || "");
                        subtext.title = subtext.textContent;
                        shell.append(title, subtext);
                        return shell;
                    },
                },
                {
                    key: "user",
                    label: "User",
                    sortable: true,
                    resizable: true,
                    width: userWidth,
                    value: (row) => String(row?.display_name || row?.user_identity || ""),
                    renderCell: ({ row }) => {
                        const shell = document.createElement("div");
                        shell.className = "grid-stacked";
                        const title = document.createElement("strong");
                        title.textContent = String(row?.display_name || row?.user_identity || "");
                        title.title = title.textContent;
                        const identity = String(row?.user_identity || "").trim();
                        if (identity && identity !== title.textContent) {
                            const subtext = document.createElement("div");
                            subtext.className = "muted tiny";
                            subtext.textContent = identity;
                            subtext.title = identity;
                            shell.append(title, subtext);
                            return shell;
                        }
                        shell.append(title);
                        return shell;
                    },
                },
                {
                    key: "session_id",
                    label: "Session",
                    sortable: true,
                    resizable: true,
                    width: sessionWidth,
                    renderCell: ({ value }) => {
                        const text = document.createElement("strong");
                        text.className = "session-inline-value";
                        text.textContent = String(value || "");
                        text.title = text.textContent;
                        return text;
                    },
                },
                {
                    key: "status",
                    label: "Status",
                    sortable: true,
                    resizable: true,
                    width: statusWidth,
                    renderCell: ({ value }) => renderBadgeElement(value),
                },
                {
                    key: "room_count",
                    label: "Rooms",
                    sortable: true,
                    resizable: true,
                    width: roomsWidth,
                    align: "center",
                },
                {
                    key: "last_activity_at",
                    label: "Last activity",
                    sortable: true,
                    resizable: true,
                    width: activityWidth,
                    value: (row) => formatDateTime(row?.last_activity_at),
                    renderCell: ({ row }) => {
                        const text = document.createElement("span");
                        text.className = "session-inline-value session-last-activity";
                        text.textContent = formatDateTime(row?.last_activity_at);
                        text.title = text.textContent;
                        return text;
                    },
                },
            ],
            selectable: "none",
            wrapCellContent: false,
            enableSort: true,
            enableSearch: false,
            enablePagination: false,
            enableColumnResize: true,
        });
    }

    pageHost.querySelectorAll("[data-page-nav]").forEach((button) => {
        button.addEventListener("click", () => {
            const target = String(button.getAttribute("data-page-nav") || "").trim();
            if (!target) return;
            navigateShell(target);
        });
    });
}

function renderAuditPage(data) {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    const items = Array.isArray(data?.items) ? data.items : [];
    const meta = data?.meta || {};
    const auditGridHostId = "auditGridHost";
    const timeWidth = measureColumnWidth(items, {
        label: "Time",
        min: 170,
        max: 220,
        value: (row) => formatDateTime(row?.occurred_at),
        charWidth: 8.4,
    });
    const actionWidth = measureColumnWidth(items, {
        label: "Action",
        min: 140,
        max: 190,
        value: (row) => row?.action_type || "",
        charWidth: 8.8,
    });
    const targetWidth = measureStackedColumnWidth(items, {
        label: "Target",
        min: 240,
        max: 380,
        primary: (row) => row?.target_type || "",
        secondary: (row) => row?.target_code || "",
    });
    const actorWidth = measureColumnWidth(items, {
        label: "Actor",
        min: 180,
        max: 260,
        value: (row) => row?.actor_identity || "",
        charWidth: 8.6,
    });

    state.ui.auditGrid?.destroy?.();
    state.ui.auditGrid = null;

    pageHost.innerHTML = `
        <section class="panel panel-stack page-shell page-shell-fill">
            <div class="page-head">
                <div>
                    <p class="eyebrow">Audit</p>
                    <h1 class="page-title">Audit review</h1>
                    <p class="page-lede">Operator actions and policy changes recorded by the admin surface.</p>
                </div>
            </div>
            <div id="${auditGridHostId}" class="page-grid-host" aria-label="Audit grid"></div>
            ${renderPager(meta, WEB.audit)}
        </section>
    `;

    const host = document.getElementById(auditGridHostId);
    if (host && state.ui.grid) {
        state.ui.auditGrid = state.ui.grid(host, items, {
            mode: "local",
            className: "audit-grid",
            chrome: false,
            columns: [
                {
                    key: "occurred_at",
                    label: "Time",
                    sortable: true,
                    resizable: true,
                    width: timeWidth,
                    value: (row) => formatDateTime(row?.occurred_at),
                    renderCell: ({ row }) => {
                        const text = document.createElement("span");
                        text.className = "session-inline-value session-last-activity";
                        text.textContent = formatDateTime(row?.occurred_at);
                        text.title = text.textContent;
                        return text;
                    },
                },
                {
                    key: "action_type",
                    label: "Action",
                    sortable: true,
                    resizable: true,
                    width: actionWidth,
                    renderCell: ({ value }) => {
                        const text = document.createElement("span");
                        text.className = "session-inline-value";
                        text.textContent = String(value || "");
                        text.title = text.textContent;
                        return text;
                    },
                },
                {
                    key: "target",
                    label: "Target",
                    sortable: false,
                    resizable: true,
                    width: targetWidth,
                    value: (row) => String(row?.target_type || ""),
                    renderCell: ({ row }) => {
                        const shell = document.createElement("div");
                        shell.className = "grid-stacked";
                        const title = document.createElement("strong");
                        title.textContent = String(row?.target_type || "");
                        title.title = title.textContent;
                        const subtext = document.createElement("div");
                        subtext.className = "muted tiny";
                        subtext.textContent = String(row?.target_code || "");
                        subtext.title = subtext.textContent;
                        shell.append(title, subtext);
                        return shell;
                    },
                },
                {
                    key: "actor_identity",
                    label: "Actor",
                    sortable: true,
                    resizable: true,
                    width: actorWidth,
                    renderCell: ({ value }) => {
                        const text = document.createElement("span");
                        text.className = "session-inline-value";
                        text.textContent = String(value || "");
                        text.title = text.textContent;
                        return text;
                    },
                },
            ],
            selectable: "none",
            wrapCellContent: false,
            enableSort: true,
            enableSearch: false,
            enablePagination: false,
            enableColumnResize: true,
        });
    }

    pageHost.querySelectorAll("[data-page-nav]").forEach((button) => {
        button.addEventListener("click", () => {
            const target = String(button.getAttribute("data-page-nav") || "").trim();
            if (!target) return;
            navigateShell(target);
        });
    });
}

function renderOperationsPage(data) {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    const clients = data?.clients || {};
    const sessions = data?.sessions || {};
    const gateway = data?.gateway || {};
    pageHost.innerHTML = `
        <section class="panel panel-stack page-shell page-shell-top">
            <div class="page-head">
                <div>
                    <p class="eyebrow">Operations</p>
                    <h1 class="page-title">Operations console</h1>
                    <p class="page-lede">Incident controls and health summary for the private platform admin surface.</p>
                </div>
            </div>

            <div class="field-grid three">
                ${renderStatCard("Clients", clients.total ?? 0, `${clients.active ?? 0} active, ${clients.quarantined ?? 0} quarantined`)}
                ${renderStatCard("Sessions", sessions.total ?? 0, `${sessions.connected ?? 0} connected, ${sessions.disconnected ?? 0} disconnected`)}
                ${renderStatCard("Gateway", String(gateway.status || "ready").toUpperCase(), "Control plane status")}
            </div>

            <div class="panel panel-stack">
                <div class="page-head">
                    <div>
                        <p class="eyebrow">Telemetry</p>
                        <h2 class="section-title">Usage telemetry moved</h2>
                        <p class="empty-note">Persisted client and project usage telemetry now lives on a dedicated page.</p>
                    </div>
                    <div class="actions">
                        <button class="button" id="openTelemetryButton" type="button">Open telemetry</button>
                    </div>
                </div>
            </div>
        </section>
    `;

    document.getElementById("openTelemetryButton")?.addEventListener("click", () => {
        navigateShell(WEB.telemetry);
    });
}

function renderTelemetryPage(data) {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    const usageSummary = data?.summary || {};
    const usageWindowHours = Number(data?.window_hours || 24);
    const retentionDays = Number(data?.retention_days || 90);

    pageHost.innerHTML = `
        <div class="telemetry-page">
        <section class="panel panel-stack page-shell page-shell-top">
            <div class="page-head">
                <div>
                    <p class="eyebrow">Telemetry</p>
                    <h1 class="page-title">Usage telemetry</h1>
                    <p class="page-lede">Persisted hourly usage aggregates for client, project, and transport event activity.</p>
                </div>
            </div>

            <div class="field-grid three">
                ${renderStatCard(`Usage ${usageWindowHours}h`, usageSummary.event_count ?? 0, `${formatFileSize(usageSummary.bytes_in ?? 0) || "0 B"} inbound`)}
                ${renderStatCard("Transport out", formatFileSize(usageSummary.bytes_out ?? 0) || "0 B", `${usageSummary.rate_limited_count ?? 0} rate-limited`)}
                ${renderStatCard("Retention", `${retentionDays} day(s)`, `${usageSummary.error_count ?? 0} error(s) in retained buckets`)}
            </div>
        </section>

        <section class="telemetry-main-grid">
            <div class="field-grid two telemetry-rank-grid">
                <article class="panel panel-stack telemetry-panel">
                    <div>
                        <p class="eyebrow">Usage</p>
                        <h2 class="section-title">Top clients</h2>
                    </div>
                    ${renderUsageRankList(data?.top_clients || [], "client_name", "client_code")}
                </article>
                <article class="panel panel-stack telemetry-panel">
                    <div>
                        <p class="eyebrow">Usage</p>
                        <h2 class="section-title">Top project scopes</h2>
                    </div>
                    ${renderUsageRankList(data?.top_projects || [], "project_name", "project_code")}
                </article>
            </div>

            <aside class="panel panel-stack telemetry-panel telemetry-event-panel">
                <div>
                    <p class="eyebrow">Usage</p>
                    <h2 class="section-title">Event mix</h2>
                </div>
                ${renderUsageEventMix(data?.event_mix || [])}
            </aside>
        </section>
        </div>
    `;
}

function renderNotFoundPage() {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    pageHost.innerHTML = `
        <section class="panel panel-stack">
            <div class="page-head">
                <div>
                    <p class="eyebrow">Not found</p>
                    <h1 class="page-title">Unknown admin page</h1>
                    <p class="page-lede">The requested admin route could not be resolved by the shell.</p>
                </div>
            </div>
        </section>
    `;
}

function renderErrorPage(error) {
    const pageHost = document.getElementById("pageHost");
    if (!pageHost) return;

    pageHost.innerHTML = `
        <section class="panel panel-stack">
            <div class="page-head">
                <div>
                    <p class="eyebrow">Error</p>
                    <h1 class="page-title">Failed to load page data</h1>
                    <p class="page-lede">${escapeHtml(error?.message || "The admin shell could not load the requested data.")}</p>
                </div>
            </div>
        </section>
    `;
}

function renderStatCard(label, value, caption) {
    return `
        <article class="panel stat-card">
            <p class="eyebrow">${escapeHtml(label)}</p>
            <h2 class="page-title" style="font-size: 2rem; margin-top: 6px;">${escapeHtml(String(value))}</h2>
            <p class="empty-note">${escapeHtml(String(caption || ""))}</p>
        </article>
    `;
}

function renderUsageRankList(items, primaryKey, secondaryKey) {
    if (!Array.isArray(items) || !items.length) {
        return renderEmptyStateHtml("No usage yet", "Persisted usage rows will appear here once traffic is recorded.");
    }

    return `
        <div class="stack">
            ${items.map((item) => `
                <div class="panel telemetry-row">
                    <div class="telemetry-row-main">
                        <strong>${escapeHtml(item[primaryKey] || item[secondaryKey] || "Unknown")}</strong>
                        ${item[primaryKey] && item[secondaryKey] && item[primaryKey] !== item[secondaryKey]
                            ? `<div class="muted tiny">${escapeHtml(item[secondaryKey] || "")}</div>`
                            : ""}
                        <div class="muted tiny">${escapeHtml(String(item.event_count ?? 0))} event(s)</div>
                    </div>
                    <div class="muted tiny telemetry-row-metrics">
                        <div>${escapeHtml(formatFileSize(item.bytes_in ?? 0) || "0 B")} in</div>
                        <div>${escapeHtml(formatFileSize(item.bytes_out ?? 0) || "0 B")} out</div>
                    </div>
                </div>
            `).join("")}
        </div>
    `;
}

function renderUsageEventMix(items) {
    if (!Array.isArray(items) || !items.length) {
        return renderEmptyStateHtml("No usage yet", "Event mix will appear once usage telemetry is recorded.");
    }

    return `
        <div class="stack">
            ${items.map((item) => `
                <div class="panel telemetry-row">
                    <div class="telemetry-row-main">
                        <strong>${escapeHtml(item.event_type || "event")}</strong>
                        <div class="muted tiny">${escapeHtml(String(item.event_count ?? 0))} event(s)</div>
                    </div>
                    <div class="muted tiny telemetry-row-metrics">
                        <div>${escapeHtml(formatFileSize(item.bytes_in ?? 0) || "0 B")} in</div>
                        <div>${escapeHtml(String(item.error_count ?? 0))} error(s)</div>
                    </div>
                </div>
            `).join("")}
        </div>
    `;
}

function renderClientMiniList(items) {
    if (!items.length) {
        return renderEmptyStateHtml("No recent clients", "Registered clients will appear here.");
    }

    return `
        <div class="stack">
            ${items.map((item) => `
                <div class="panel mini-row">
                    <div>
                        <strong>${escapeHtml(item.name || item.client_code || "Client")}</strong>
                        <div class="muted tiny">${escapeHtml(item.client_code || "")}</div>
                        <div class="muted tiny">${escapeHtml(String(item.project_count ?? 0))} project(s)</div>
                    </div>
                    <div class="actions">
        <button class="button-secondary" type="button" data-client-open="${escapeHtml(item.client_code || "")}">View</button>
        <button class="button-secondary" type="button" data-client-edit="${escapeHtml(item.client_code || "")}">Edit</button>
                    </div>
                </div>
            `).join("")}
        </div>
    `;
}

function renderProjectMiniList(items) {
    if (!items.length) {
        return renderEmptyStateHtml("No recent project scopes", "Project scopes will appear here.");
    }

    return `
        <div class="stack">
            ${items.map((item) => `
                <div class="panel mini-row">
                    <div>
                        <strong>${escapeHtml(item.name || item.project_code || "Project")}</strong>
                        <div class="muted tiny">${escapeHtml(item.project_code || "")}</div>
                        <div class="muted tiny">${escapeHtml(item.client_code || "")}</div>
                    </div>
                    <div class="actions">
                        <button class="button-secondary" type="button" data-project-open="${escapeHtml(item.project_code || "")}">View</button>
                        <button class="button-secondary" type="button" data-project-edit="${escapeHtml(item.project_code || "")}">Edit</button>
                    </div>
                </div>
            `).join("")}
        </div>
    `;
}

function renderAuditMiniList(items) {
    if (!items.length) {
        return renderEmptyStateHtml("No recent events", "Audit events will appear here.");
    }

    return `
        <div class="stack">
            ${items.map((item) => `
                <div class="panel mini-row">
                    <div>
                        <strong>${escapeHtml(item.action_type || "Event")}</strong>
                        <div class="muted tiny">${escapeHtml(item.target_type || "")} · ${escapeHtml(item.target_code || "")}</div>
                    </div>
                    <div class="muted tiny">${escapeHtml(formatDateTime(item.occurred_at))}</div>
                </div>
            `).join("")}
        </div>
    `;
}

function renderClientTable(items) {
    if (!items.length) {
        return renderEmptyStateHtml("No clients yet", "Create the first realtime client to begin.");
    }

    return `
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Projects</th>
                        <th>Status</th>
                        <th>Token issuance</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map((item) => `
                        <tr>
                            <td>
                                <strong>${escapeHtml(item.name || item.client_code || "")}</strong>
                                <div class="muted tiny">${escapeHtml(item.client_code || "")}</div>
                            </td>
                            <td>${escapeHtml(String(item.project_count ?? 0))}</td>
                            <td>${renderBadge(item.status)}</td>
                            <td>${escapeHtml(item.token_issuance_mode || "")}</td>
                            <td>
                                <div class="actions">
                                    <button class="button-secondary tiny" type="button" data-client-open="${escapeHtml(item.client_code || "")}">View</button>
                                    <button class="button-secondary tiny" type="button" data-client-edit="${escapeHtml(item.client_code || "")}">Edit</button>
                                </div>
                            </td>
                        </tr>
                    `).join("")}
                </tbody>
            </table>
        </div>
    `;
}

function renderProjectTable(items) {
    if (!items.length) {
        return renderEmptyStateHtml("No project scopes yet", "Create the first project scope to begin.");
    }

    return `
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Client</th>
                        <th>Status</th>
                        <th>Policy profile</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map((item) => `
                        <tr>
                            <td>
                                <strong>${escapeHtml(item.name || item.project_code || "")}</strong>
                                <div class="muted tiny">${escapeHtml(item.project_code || "")}</div>
                            </td>
                            <td>
                                <strong>${escapeHtml(item.client_name || item.client_code || "")}</strong>
                                <div class="muted tiny">${escapeHtml(item.client_code || "")}</div>
                            </td>
                            <td>${renderBadge(item.status)}</td>
                            <td>
                                <strong>${escapeHtml(item.policy_profile_name || item.policy_profile_code || "")}</strong>
                                <div class="muted tiny">${escapeHtml(item.policy_profile_code || "")}</div>
                            </td>
                            <td>
                                <div class="actions">
                                    <button class="button-secondary tiny" type="button" data-project-open="${escapeHtml(item.project_code || "")}">View</button>
                                    <button class="button-secondary tiny" type="button" data-project-edit="${escapeHtml(item.project_code || "")}">Edit</button>
                                </div>
                            </td>
                        </tr>
                    `).join("")}
                </tbody>
            </table>
        </div>
    `;
}

function renderProjectDetailList(items) {
    if (!items.length) {
        return renderEmptyStateHtml("No project scopes yet", "Create a project scope for this client.");
    }

    return `
        <div class="entity-list">
            ${items.map((item) => `
                <div class="entity-row">
                    <div class="entity-main">
                        <strong>${escapeHtml(item.name || item.project_code || "Project")}</strong>
                        <div class="muted tiny">${escapeHtml(item.project_code || "")} · ${escapeHtml(item.status || "")}</div>
                    </div>
                    <div class="actions">
                        ${renderActionIconButton("button-secondary", "project-edit", item.project_code, "Edit project scope", "edit")}
                        ${String(item.status || "").toLowerCase() === "active"
                            ? renderActionIconButton("button-danger", "project-deactivate", item.project_code, "Deactivate project scope", "deactivate")
                            : ""}
                        ${String(item.status || "").toLowerCase() === "inactive"
                            ? renderActionIconButton("button", "project-reactivate", item.project_code, "Reactivate project scope", "reactivate")
                            : ""}
                    </div>
                </div>
            `).join("")}
        </div>
    `;
}

function renderPolicyDetailList(items) {
    if (!items.length) {
        return renderEmptyStateHtml("No policies yet", "Create a policy for this client.");
    }

    return `
        <div class="entity-list">
            ${items.map((item) => {
                const status = String(item.status || "").toLowerCase();
                const canManage = status !== "deprecated";

                return `
                <div class="entity-row">
                    <div class="entity-main">
                        <strong>${escapeHtml(item.name || item.policy_code || "Policy")}</strong>
                        <div class="muted tiny">${escapeHtml(item.policy_code || "")} · ${escapeHtml(item.status || "")}</div>
                    </div>
                    <div class="grid-actions">
                        ${canManage
                            ? renderActionIconButton("button-secondary", "policy-edit", item.policy_code, "Edit policy", "edit")
                            : ""}
                        ${canManage
                            ? renderActionIconButton("button-danger", "policy-deprecate", item.policy_code, "Deprecate policy", "deprecate")
                            : ""}
                        ${renderActionIconButton("button", "policy-duplicate", item.policy_code, "Duplicate policy", "duplicate")}
                    </div>
                </div>
            `;
            }).join("")}
        </div>
    `;
}

function renderPolicyTable(items) {
    if (!items.length) {
        return renderEmptyStateHtml("No policies yet", "Create the first policy profile to begin.");
    }

    return `
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Category</th>
                        <th>Allow/deny</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map((item) => `
                        <tr>
                            <td>
                                <strong>${escapeHtml(item.name || item.policy_code || "")}</strong>
                                <div class="muted tiny">${escapeHtml(item.policy_code || "")}</div>
                            </td>
                            <td>${renderBadge(item.status)}</td>
                            <td>${escapeHtml(item.policy_category || "")}</td>
                            <td>${escapeHtml(item.allow_deny_mode || "")}</td>
                            <td>
                                <div class="actions">
                                    <button class="button-secondary tiny" type="button" data-policy-open="${escapeHtml(item.policy_code || "")}">View</button>
                                    <button class="button-secondary tiny" type="button" data-policy-edit="${escapeHtml(item.policy_code || "")}">Edit</button>
                                </div>
                            </td>
                        </tr>
                    `).join("")}
                </tbody>
            </table>
        </div>
    `;
}

function renderSessionTable(items) {
    if (!items.length) {
        return renderEmptyStateHtml("No sessions yet", "Realtime sessions will appear here as the gateway records them.");
    }

    return `
        <div class="table-wrap session-table-wrap">
            <table class="session-table">
                <colgroup>
                    <col class="session-col-client">
                    <col class="session-col-project">
                    <col class="session-col-user">
                    <col class="session-col-session">
                    <col class="session-col-status">
                    <col class="session-col-rooms">
                    <col class="session-col-last-activity">
                </colgroup>
                <thead>
                    <tr>
                        <th class="session-table-head session-table-head-client">Client</th>
                        <th class="session-table-head session-table-head-project">Project</th>
                        <th class="session-table-head session-table-head-user">User</th>
                        <th class="session-table-head session-table-head-session">Session</th>
                        <th class="session-table-head session-table-head-status">Status</th>
                        <th class="session-table-head session-table-head-rooms">Rooms</th>
                        <th class="session-table-head session-table-head-last-activity">Last activity</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map((item) => `
                        <tr>
                            <td class="session-table-cell session-table-cell-client">
                                <div class="grid-stacked session-stack">
                                    <strong title="${escapeHtml(item.client_name || item.client_code || "")}">${escapeHtml(item.client_name || item.client_code || "")}</strong>
                                    <div class="muted tiny" title="${escapeHtml(item.client_code || "")}">${escapeHtml(item.client_code || "")}</div>
                                </div>
                            </td>
                            <td class="session-table-cell session-table-cell-project">
                                <div class="grid-stacked session-stack">
                                    <strong title="${escapeHtml(item.project_name || item.project_code || "")}">${escapeHtml(item.project_name || item.project_code || "")}</strong>
                                    <div class="muted tiny" title="${escapeHtml(item.project_code || "")}">${escapeHtml(item.project_code || "")}</div>
                                </div>
                            </td>
                            <td class="session-table-cell session-table-cell-user">
                                <span class="session-inline-value" title="${escapeHtml(item.user_identity || "")}">${escapeHtml(item.user_identity || "")}</span>
                            </td>
                            <td class="session-table-cell session-table-cell-session">
                                <strong class="session-inline-value" title="${escapeHtml(item.session_id || "")}">${escapeHtml(item.session_id || "")}</strong>
                            </td>
                            <td class="session-table-cell session-table-cell-status">${renderBadge(item.status)}</td>
                            <td class="session-table-cell session-table-cell-rooms">${escapeHtml(String(item.room_count ?? 0))}</td>
                            <td class="session-table-cell session-table-cell-last-activity">
                                <span class="session-inline-value session-last-activity" title="${escapeHtml(formatDateTime(item.last_activity_at))}">${escapeHtml(formatDateTime(item.last_activity_at))}</span>
                            </td>
                        </tr>
                    `).join("")}
                </tbody>
            </table>
        </div>
    `;
}

function renderAuditTable(items) {
    if (!items.length) {
        return renderEmptyStateHtml("No audit events", "Audit events will appear here as operators make changes.");
    }

    return `
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>Actor</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map((item) => `
                        <tr>
                            <td>${escapeHtml(formatDateTime(item.occurred_at))}</td>
                            <td>${escapeHtml(item.action_type || "")}</td>
                            <td>
                                <strong>${escapeHtml(item.target_type || "")}</strong>
                                <div class="muted tiny">${escapeHtml(item.target_code || "")}</div>
                            </td>
                            <td>${escapeHtml(item.actor_identity || "")}</td>
                        </tr>
                    `).join("")}
                </tbody>
            </table>
        </div>
    `;
}

function renderPager(meta, basePath) {
    const current = Math.max(1, Number(meta?.current_page || 1) || 1);
    const last = Math.max(1, Number(meta?.last_page || 1) || 1);
    if (last <= 1) {
        return "";
    }

    const prev = Math.max(1, current - 1);
    const next = Math.min(last, current + 1);

    return `
        <div class="pager">
            <span class="muted tiny">Page ${current} of ${last}</span>
            <div class="actions">
                <button class="button-secondary tiny${current <= 1 ? " is-disabled" : ""}" type="button" data-page-nav="${escapeHtml(pageUrl(basePath, prev))}" ${current <= 1 ? 'disabled aria-disabled="true"' : ""}>Previous</button>
                <button class="button-secondary tiny${current >= last ? " is-disabled" : ""}" type="button" data-page-nav="${escapeHtml(pageUrl(basePath, next))}" ${current >= last ? 'disabled aria-disabled="true"' : ""}>Next</button>
            </div>
        </div>
    `;
}

function renderDetailCard(title, rows, allowHtml = false) {
    return `
        <article class="panel detail-card">
            <p class="eyebrow">${escapeHtml(title)}</p>
            <dl class="detail-list">
                ${rows.map(([label, value]) => `
                    <div class="detail-row">
                        <dt>${escapeHtml(label)}</dt>
                        <dd>${allowHtml ? String(value || "") : escapeHtml(value == null ? "" : String(value))}</dd>
                    </div>
                `).join("")}
            </dl>
        </article>
    `;
}

function renderBlockCard(title, contentHtml) {
    return `
        <article class="panel detail-card">
            <p class="eyebrow">${escapeHtml(title)}</p>
            ${contentHtml}
        </article>
    `;
}

function renderPlainBlock(text) {
    return `<div class="empty-note">${escapeHtml(String(text || ""))}</div>`;
}

function renderCodeBlock(text) {
    return `<pre class="code-block">${escapeHtml(String(text || ""))}</pre>`;
}

function renderBadgeElement(value) {
    const text = String(value || "").trim();
    const badge = document.createElement("span");
    badge.className = `badge ${badgeClassName(text)}`.trim();
    badge.textContent = text || "n/a";
    return badge;
}

function renderBadge(value) {
    const text = String(value || "").trim();
    const className = badgeClassName(text);
    return `<span class="badge ${className}">${escapeHtml(text || "n/a")}</span>`;
}

function renderActionIconButton(variant, actionName, value, title, icon) {
    const safeValue = escapeHtml(String(value || "").trim());
    const safeTitle = escapeHtml(String(title || "").trim());

    return `
        <button
            class="${variant} tiny action-icon-button"
            type="button"
            data-${actionName}="${safeValue}"
            title="${safeTitle}"
            aria-label="${safeTitle}"
        >
            <span class="action-icon-slot" data-ui-icon="${escapeHtml(String(icon || "").trim())}"></span>
        </button>
    `;
}

function hydrateActionIcons(host) {
    const container = host instanceof Element ? host : document;
    const createIcon = state.ui.icons?.createIcon;
    if (typeof createIcon !== "function") {
        return;
    }

    const iconMap = {
        edit: "actions.edit",
        duplicate: "actions.copy",
        deprecate: "actions.delete",
        deactivate: "actions.close",
        reactivate: "actions.check",
    };

    container.querySelectorAll("[data-ui-icon]").forEach((slot) => {
        if (!(slot instanceof HTMLElement) || slot.dataset.iconHydrated === "true") {
            return;
        }

        const iconName = iconMap[String(slot.getAttribute("data-ui-icon") || "").trim()];
        if (!iconName) {
            return;
        }

        slot.textContent = "";
        slot.appendChild(createIcon(iconName, {
            size: 16,
            decorative: true,
            className: "action-icon-svg",
        }));
        slot.dataset.iconHydrated = "true";
    });
}

async function copyTextToClipboard(value, successTitle = "Copied") {
    const text = String(value || "").trim();
    if (!text) {
        return false;
    }

    try {
        if (navigator?.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
        } else {
            const input = document.createElement("textarea");
            input.value = text;
            input.setAttribute("readonly", "readonly");
            input.style.position = "fixed";
            input.style.opacity = "0";
            document.body.appendChild(input);
            input.select();
            document.execCommand("copy");
            document.body.removeChild(input);
        }
        showToast("Code copied to clipboard.", { title: successTitle, type: "success" });
        return true;
    } catch (error) {
        showToast("Unable to copy code.", { title: "Clipboard", type: "error" });
        return false;
    }
}

function createInlineCopyButton(value, title = "Copy code") {
    return `
        <button
            class="button-secondary tiny action-icon-button inline-copy-button"
            type="button"
            data-copy-value="${escapeHtml(String(value || "").trim())}"
            title="${escapeHtml(title)}"
            aria-label="${escapeHtml(title)}"
        >
            <span class="action-icon-slot" data-ui-icon="duplicate"></span>
        </button>
    `;
}

function bindCopyButtons(host) {
    const container = host instanceof Element ? host : document;
    container.querySelectorAll("[data-copy-value]").forEach((button) => {
        if (!(button instanceof HTMLElement) || button.dataset.copyBound === "true") {
            return;
        }
        button.addEventListener("click", () => {
            void copyTextToClipboard(button.getAttribute("data-copy-value") || "");
        });
        button.dataset.copyBound = "true";
    });
    hydrateActionIcons(container);
}

function attachDisplayCopyButton(modal, labelText, value, title = "Copy code") {
    const shell = modal?.refs?.shell;
    const code = String(value || "").trim();
    if (!(shell instanceof Element) || !code) {
        return;
    }

    const fields = Array.from(shell.querySelectorAll(".ui-form-modal-field.is-display"));
    const targetField = fields.find((field) => {
        const label = field.querySelector(".ui-label");
        return String(label?.textContent || "").trim().toLowerCase() === String(labelText || "").trim().toLowerCase();
    });
    if (!targetField) {
        return;
    }

    const valueEl = targetField.querySelector(".ui-form-modal-display-value");
    if (!(valueEl instanceof HTMLElement)) {
        return;
    }

    valueEl.classList.add("display-value-with-copy");
    const copyButton = document.createElement("button");
    copyButton.className = "button-secondary tiny action-icon-button inline-copy-button";
    copyButton.type = "button";
    copyButton.setAttribute("data-copy-value", code);
    copyButton.setAttribute("title", title);
    copyButton.setAttribute("aria-label", title);
    copyButton.innerHTML = '<span class="action-icon-slot" data-ui-icon="duplicate"></span>';
    valueEl.appendChild(copyButton);
    bindCopyButtons(valueEl);
}

function badgeClassName(value) {
    const status = String(value || "").toLowerCase();
    if (["active", "connected", "ready", "online"].includes(status)) {
        return "badge-active";
    }
    if (["quarantined", "inactive", "disconnected", "deprecated", "pending", "draft"].includes(status)) {
        return "badge-warning";
    }
    return "";
}

function renderEmptyStateHtml(title, description) {
    if (!state.ui.emptyState) {
        return `
            <div class="page-empty-state">
                <div class="panel">
                <h3 class="page-title" style="font-size: 1.2rem;">${escapeHtml(title)}</h3>
                <p class="empty-note">${escapeHtml(description)}</p>
                </div>
            </div>
        `;
    }

    const host = document.createElement("div");
    state.ui.emptyState(host, { title, description }, { chrome: false });
    return `<div class="page-empty-state">${host.innerHTML}</div>`;
}

function formatDateTime(value) {
    if (!value) {
        return "n/a";
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return "n/a";
    }

    return date.toLocaleString([], {
        month: "short",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
    });
}

function formatStructuredValue(value) {
    if (value == null || value === "") {
        return "";
    }

    if (typeof value === "string") {
        return value;
    }

    if (Array.isArray(value)) {
        return value.map((item) => (typeof item === "string" ? item : JSON.stringify(item, null, 2))).join("\n");
    }

    return JSON.stringify(value, null, 2);
}

function formatLabel(value) {
    return String(value == null ? "" : value)
        .trim()
        .replace(/[_-]+/g, " ")
        .replace(/\s+/g, " ")
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function escapeHtml(value) {
    return String(value == null ? "" : value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function pageUrl(basePath, page) {
    const url = new URL(basePath, window.location.origin);
    url.searchParams.set("page", String(Math.max(1, Number(page) || 1)));
    return `${url.pathname}${url.search}`;
}

function getCurrentListPage() {
    const page = Number(new URLSearchParams(window.location.search).get("page") || 1);
    return Math.max(1, Number.isFinite(page) ? page : 1);
}

function redirectToLogin() {
    if (isAccountSsoEnabled()) {
        beginAccountSso();
        return;
    }

    void switchToStatusShell();
}

function startLoginFlow() {
    if (isAccountSsoEnabled()) {
        beginAccountSso();
        return;
    }

    void openLoginModal();
}

function isAccountSsoEnabled() {
    return Boolean(state.accountSso?.enabled && state.accountSso?.redirectUrl);
}

function beginAccountSso() {
    const redirectUrl = new URL(String(state.accountSso?.redirectUrl || "/auth/account/redirect"), window.location.origin);
    const returnPath = normalizeAccountReturnPath(window.location.pathname, window.location.search);
    redirectUrl.searchParams.set("return", returnPath);
    window.location.href = `${redirectUrl.pathname}${redirectUrl.search}`;
}

function normalizeAccountReturnPath(pathname, search = "") {
    const path = normalizePath(pathname || WEB.dashboard);
    const suffix = String(search || "");

    if (path.startsWith("/admin")) {
        return `${path}${suffix}`;
    }

    return WEB.dashboard;
}

function navigateShell(targetPath) {
    const targetUrl = new URL(String(targetPath || WEB.status), window.location.origin);
    state.route = resolveRouteState(normalizePath(targetUrl.pathname));

    if (window.location.pathname !== targetUrl.pathname || window.location.search !== targetUrl.search) {
        window.history.pushState({}, "", `${targetUrl.pathname}${targetUrl.search}`);
    }

    if (appEl) {
        appEl.dataset.page = getPathForRoute(state.route);
    }

    renderShell();
    renderNavbar();
    void renderCurrentPage();
}

async function openLoginModal() {
    if (isAccountSsoEnabled()) {
        beginAccountSso();
        return;
    }

    if (state.loginModalOpen) {
        return;
    }

    state.loginModalOpen = true;

    const modal = state.ui.loginFormModal({
        title: "PBB Realtime Admin Login",
        message: "Sign in with an authorized operator account to continue.",
        identifierLabel: "Email",
        identifierPlaceholder: "admin@pbb.ph",
        passwordLabel: "Password",
        submitLabel: "Sign in",
        busyMessage: "Signing in...",
        initialValues: {
            email: state.account?.email || "admin@pbb.ph",
        },
        fields: {
            identifier: "email",
            password: "password",
        },
        async onSubmit(values, ctx) {
            const { response, data } = await requestJson(API.login, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                },
                body: JSON.stringify({
                    email: String(values.email || values.identifier || "").trim(),
                    password: String(values.password || ""),
                }),
                handleSessionExpiry: false,
            });

            if (!response.ok) {
                if (data?.errors) {
                    ctx.setErrors(data.errors);
                } else {
                    ctx.setFormError(data?.message || "Login failed.");
                }
                return false;
            }

            if (data?.data?.csrf_token) {
                setCsrfToken(data.data.csrf_token);
            }
            applySessionTimingPayload(data?.data || {});

            await activateAuthenticatedShell(WEB.dashboard, data?.data?.account);
            return true;
        },
        onClose() {
            state.loginModalOpen = false;
        },
    });

    const result = modal.open();
    if (result && typeof result.then === "function") {
        await result;
    }
}

async function openReauthModal() {
    if (state.sessionPromptOpen) {
        return;
    }

    state.sessionPromptOpen = true;

    const modal = state.ui.reauthFormModal({
        title: "Session expired",
        message: "Your session has expired. Please re-enter your password to continue.",
        identifierValue: state.account?.email || "",
        initialValues: {
            email: state.account?.email || "",
        },
        async onSubmit(values, ctx) {
            const pingOk = await pingSessionKeepalive();
            if (!pingOk) {
                const csrfRefreshed = await refreshCsrfToken();
                if (!csrfRefreshed) {
                    ctx.setFormError("Unable to refresh the session. Please try again.");
                    return false;
                }
            }

            const { response, data } = await requestJson(API.login, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                },
                body: JSON.stringify({
                    email: String(values.email || values.identifier || "").trim(),
                    password: String(values.password || ""),
                }),
                handleSessionExpiry: false,
            });

            if (!response.ok) {
                if (data?.errors) {
                    ctx.setErrors(data.errors);
                } else {
                    ctx.setFormError(data?.message || "Re-authentication failed.");
                }
                return false;
            }

            if (data?.data?.csrf_token) {
                setCsrfToken(data.data.csrfToken || data.data.csrf_token);
            }
            applySessionTimingPayload(data?.data || {});

            state.sessionPromptOpen = false;
            state.lastServerTouchAt = Date.now();
            state.lastUserActivityAt = Date.now();
            state.lastKeepaliveAt = 0;
            await renderCurrentPage();
            startKeepaliveWatcher();
            return true;
        },
        onClose(meta) {
            state.sessionPromptOpen = false;
            if (meta?.reason === "cancel") {
                redirectToLogin();
            }
        },
    });

    const result = modal.open();
    if (result && typeof result.then === "function") {
        await result;
    }
}

async function openAccountModal() {
    if (state.accountModalOpen) {
        return;
    }

    const assignedClients = Array.isArray(state.account?.assigned_clients) ? state.account.assigned_clients : [];
    const roleLabel = isCurrentUserAdmin() ? "Admin" : "Regular";
    const assignmentSummary = isCurrentUserAdmin()
        ? "Access: all clients."
        : (assignedClients.length
            ? `Assigned clients: ${assignedClients.map((client) => client?.name || client?.client_code || "Client").join(", ")}.`
            : "Assigned clients: none.");

    state.accountModalOpen = true;

    const modal = state.ui.accountFormModal({
        title: "Account",
        message: `Role: ${roleLabel}. ${assignmentSummary} Update the name and email used for the operator account.`,
        initialValues: {
            name: state.account?.name || "",
            email: state.account?.email || "",
        },
        extraActions: [
            {
                id: "change-password",
                label: "Change Password",
                variant: "ghost",
                closeOnClick: false,
                onClick() {
                    return openChangePasswordModal();
                },
            },
        ],
        async onSubmit(values, ctx) {
            const { response, data } = await requestJson(API.me, {
                method: "PATCH",
                headers: {
                    "Content-Type": "application/json",
                },
                body: JSON.stringify(values),
            });

            if (!response.ok) {
                if (data?.errors) {
                    ctx.setErrors(data.errors);
                } else {
                    ctx.setFormError(data?.message || "Account update failed.");
                }
                return false;
            }

            if (data?.data?.account) {
                state.account = normalizeAccount(data.data.account);
                renderNavbar();
            }

            if (data?.data?.csrf_token) {
                setCsrfToken(data.data.csrf_token);
            }

            showToast("Account updated.", { title: "Success", type: "success" });
            return true;
        },
        onClose() {
            state.accountModalOpen = false;
        },
    });

    const result = modal.open();
    if (result && typeof result.then === "function") {
        await result;
    }
}

async function openChangePasswordModal() {
    if (state.passwordModalOpen) {
        return;
    }

    state.passwordModalOpen = true;

    const modal = state.ui.changePasswordFormModal({
        title: "Change Password",
        message: "Choose a new password for the current operator account.",
        initialValues: {
            current_password: "",
            password: "",
            password_confirmation: "",
        },
        fields: {
            currentPassword: "current_password",
            newPassword: "password",
            confirmPassword: "password_confirmation",
        },
        async onSubmit(values, ctx) {
            const { response, data } = await requestJson(API.mePassword, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                },
                body: JSON.stringify(values),
            });

            if (!response.ok) {
                if (data?.errors) {
                    ctx.setErrors(data.errors);
                } else {
                    ctx.setFormError(data?.message || "Password update failed.");
                }
                return false;
            }

            if (data?.data?.csrf_token) {
                setCsrfToken(data.data.csrf_token);
            }

            showToast("Password updated.", { title: "Success", type: "success" });
            return true;
        },
        onClose() {
            state.passwordModalOpen = false;
        },
    });

    const result = modal.open();
    if (result && typeof result.then === "function") {
        await result;
    }
}

async function openRealtimeSettingsModal() {
    if (!state.account || !isCurrentUserAdmin() || !state.ui.formModal) {
        return;
    }

    const runtimeSettings = state.route.kind === "operations"
        ? (state.pageData?.data?.runtime_settings || {})
        : ((await fetchPageData(API.operations))?.runtime_settings || {});
    const currentSettings = runtimeSettings.maestro_telemetry || {};
    const accountSettings = runtimeSettings.account || {};
    const accountSso = accountSettings.sso || {};
    const accountAdmin = accountSettings.app_admin || {};

    const modal = state.ui.formModal({
        title: "Realtime settings",
        size: "lg",
        submitLabel: "Save settings",
        busyMessage: "Saving Realtime settings...",
        rows: [
            [
                {
                    type: "text",
                    content: "Account SSO",
                    span: 2,
                },
            ],
            [
                {
                    type: "select",
                    name: "account_sso_enabled",
                    label: "Account SSO",
                    value: String(accountSso.enabled ? "1" : "0"),
                    options: [
                        { label: "Enabled", value: "1" },
                        { label: "Disabled", value: "0" },
                    ],
                },
                {
                    type: "input",
                    name: "account_sso_client_id",
                    label: "Client ID",
                    value: String(accountSso.client_id || "pbb-realtime"),
                    placeholder: "pbb-realtime",
                },
            ],
            [
                {
                    type: "input",
                    name: "account_sso_base_url",
                    label: "Account base URL",
                    input: "url",
                    value: String(accountSso.base_url || "https://account.pbb.ph"),
                    placeholder: "https://account.pbb.ph",
                    span: 2,
                },
            ],
            [
                {
                    type: "input",
                    name: "account_sso_redirect_uri",
                    label: "Callback URL",
                    input: "url",
                    value: String(accountSso.redirect_uri || "https://realtime.pbb.ph/auth/account/callback"),
                    placeholder: "https://realtime.pbb.ph/auth/account/callback",
                    span: 2,
                },
            ],
            [
                {
                    type: "input",
                    name: "account_sso_post_logout_redirect_uri",
                    label: "Post-logout URL",
                    input: "url",
                    value: String(accountSso.post_logout_redirect_uri || "https://realtime.pbb.ph"),
                    placeholder: "https://realtime.pbb.ph",
                    span: 2,
                },
            ],
            [
                {
                    type: "input",
                    name: "account_sso_scopes",
                    label: "OAuth scopes",
                    value: Array.isArray(accountSso.scopes) ? accountSso.scopes.join(" ") : String(accountSso.scopes || "openid profile"),
                    placeholder: "openid profile",
                },
                {
                    type: "input",
                    name: "account_sso_timeout_seconds",
                    label: "Account timeout (s)",
                    input: "number",
                    value: String(accountSso.timeout_seconds || 10),
                    min: 1,
                    max: 120,
                    step: 1,
                },
            ],
            [
                {
                    type: "input",
                    name: "account_sso_client_secret",
                    label: "OAuth client secret",
                    input: "password",
                    value: "",
                    placeholder: accountSso.client_secret_configured
                        ? "Leave blank to keep current secret"
                        : "Enter OAuth client secret",
                    span: 2,
                },
            ],
            [
                {
                    type: "input",
                    name: "account_sso_ca_bundle",
                    label: "Account CA bundle",
                    value: String(accountSso.ca_bundle || ""),
                    placeholder: "Optional absolute CA bundle path",
                    span: 2,
                },
            ],
            [
                {
                    type: "text",
                    content: `OAuth secret: ${accountSso.client_secret_configured ? "configured" : "not configured"}. Leave blank to keep the current stored value.`,
                    span: 2,
                },
            ],
            [
                {
                    type: "text",
                    content: "Account app-admin API",
                    span: 2,
                },
            ],
            [
                {
                    type: "select",
                    name: "account_admin_enabled",
                    label: "App-admin API",
                    value: String(accountAdmin.enabled ? "1" : "0"),
                    options: [
                        { label: "Enabled", value: "1" },
                        { label: "Disabled", value: "0" },
                    ],
                },
                {
                    type: "input",
                    name: "account_admin_client",
                    label: "Account client header",
                    value: String(accountAdmin.client || "pbb-account"),
                    placeholder: "pbb-account",
                },
            ],
            [
                {
                    type: "input",
                    name: "account_admin_token",
                    label: "App-admin token",
                    input: "password",
                    value: "",
                    placeholder: accountAdmin.token_configured
                        ? "Leave blank to keep current token"
                        : "Enter dedicated app-admin token",
                    span: 2,
                },
            ],
            [
                {
                    type: "text",
                    content: `App-admin token: ${accountAdmin.token_configured ? "configured" : "not configured"}. Entering a new token rotates the stored service token.`,
                    span: 2,
                },
            ],
            [
                {
                    type: "text",
                    content: "Maestro telemetry",
                    span: 2,
                },
            ],
            [
                {
                    type: "select",
                    name: "enabled",
                    label: "Maestro telemetry",
                    value: String(currentSettings.enabled ? "1" : "0"),
                    options: [
                        { label: "Enabled", value: "1" },
                        { label: "Disabled", value: "0" },
                    ],
                },
                {
                    type: "input",
                    name: "app_code",
                    label: "App code",
                    value: String(currentSettings.app_code || "realtime"),
                    placeholder: "realtime",
                },
            ],
            [
                {
                    type: "input",
                    name: "connect_timeout_seconds",
                    label: "Connect timeout (s)",
                    input: "number",
                    value: String(currentSettings.connect_timeout_seconds || 3),
                    min: 1,
                    max: 60,
                    step: 1,
                },
                {
                    type: "input",
                    name: "timeout_seconds",
                    label: "Request timeout (s)",
                    input: "number",
                    value: String(currentSettings.timeout_seconds || 5),
                    min: 1,
                    max: 120,
                    step: 1,
                },
            ],
            [
                {
                    type: "input",
                    name: "base_url",
                    label: "Base URL",
                    input: "url",
                    value: String(currentSettings.base_url || ""),
                    placeholder: "https://maestro.pbb.ph/",
                    span: 2,
                },
            ],
            [
                {
                    type: "input",
                    name: "token",
                    label: "Telemetry token",
                    input: "password",
                    value: "",
                    placeholder: currentSettings.token_configured
                        ? "Leave blank to keep current token"
                        : "Enter telemetry token",
                    span: 2,
                },
            ],
            [
                {
                    type: "text",
                    content: `Current token header: ${String(currentSettings.token_header || "X-Telemetry-Token")}. Leave token blank to keep the existing stored value.`,
                    span: 2,
                },
            ],
        ],
        async onSubmit(values, ctx) {
            const accountPayload = {
                sso: {
                    enabled: String(values.account_sso_enabled || "0") === "1",
                    base_url: String(values.account_sso_base_url || "").trim(),
                    client_id: String(values.account_sso_client_id || "").trim(),
                    client_secret: String(values.account_sso_client_secret || "").trim(),
                    redirect_uri: String(values.account_sso_redirect_uri || "").trim(),
                    post_logout_redirect_uri: String(values.account_sso_post_logout_redirect_uri || "").trim(),
                    scopes: String(values.account_sso_scopes || "").trim(),
                    timeout_seconds: Number(values.account_sso_timeout_seconds || 0),
                    ca_bundle: String(values.account_sso_ca_bundle || "").trim(),
                },
                app_admin: {
                    enabled: String(values.account_admin_enabled || "0") === "1",
                    client: String(values.account_admin_client || "").trim(),
                    token: String(values.account_admin_token || "").trim(),
                },
            };
            const maestroPayload = {
                enabled: String(values.enabled || "0") === "1",
                base_url: String(values.base_url || "").trim(),
                app_code: String(values.app_code || "").trim(),
                token: String(values.token || "").trim(),
                connect_timeout_seconds: Number(values.connect_timeout_seconds || 0),
                timeout_seconds: Number(values.timeout_seconds || 0),
            };

            const accountResult = await requestJson(API.runtimeSettingsAccount, {
                method: "PATCH",
                headers: {
                    "Content-Type": "application/json",
                },
                body: JSON.stringify(accountPayload),
            });

            if (!accountResult.response.ok || accountResult.data?.status === false) {
                ctx.setFormError?.(accountResult.data?.message || "Unable to save Account settings.");
                return false;
            }

            const { response, data } = await requestJson(API.runtimeSettingsMaestroTelemetry, {
                method: "PATCH",
                headers: {
                    "Content-Type": "application/json",
                },
                body: JSON.stringify(maestroPayload),
            });

            if (!response.ok || data?.status === false) {
                ctx.setFormError?.(data?.message || "Unable to save Realtime settings.");
                return false;
            }

            showToast("Realtime settings updated.", { title: "Success", type: "success" });

            if (state.route.kind === "operations") {
                await renderCurrentRoute();
            }

            return true;
        },
    });

    const result = modal.open();
    if (result && typeof result.then === "function") {
        await result;
    }
}

async function openClientModal(mode, clientId = null) {
    const client = mode === "edit" && clientId
        ? await fetchRecordJson(`${API.clients}/${encodeURIComponent(clientId)}`, "client")
        : null;

    const initial = buildClientInitialValues(client?.client || null);
    const rows = buildClientFormRows(initial);

    const modal = state.ui.formModal({
        title: mode === "create" ? "New client" : "Edit client",
        size: "lg",
        submitLabel: mode === "create" ? "Create client" : "Save client",
        busyMessage: mode === "create" ? "Creating client..." : "Saving client...",
        rows,
        async onSubmit(values, ctx) {
            ctx.clearErrors?.();
            ctx.clearFormError?.();

            const submitValues = normalizeClientSubmission(values);
            const url = mode === "create"
                ? API.clients
                : `${API.clients}/${encodeURIComponent(clientId)}`;

            const method = mode === "create" ? "POST" : "PATCH";
            const { response, data } = await requestJson(url, {
                method,
                headers: {
                    "Content-Type": "application/json",
                },
                body: JSON.stringify(submitValues),
            });

            if (!response.ok) {
                if (data?.errors) {
                    ctx.setErrors?.(data.errors);
                } else {
                    ctx.setFormError?.(data?.message || "Client save failed.");
                }
                return false;
            }

            await renderCurrentPage();
            return true;
        },
    });

    if (initial.client_code) {
        attachDisplayCopyButton(modal, "Client code", initial.client_code, "Copy client code");
    }

    const result = modal.open();
    if (result && typeof result.then === "function") {
        await result;
    }
}

async function openProjectModal(mode, projectId = null, clientContext = null) {
    const currentClientPage = state.pageData?.kind === "client" ? state.pageData?.data?.client || null : null;
    const project = mode === "edit" && projectId
        ? await fetchRecordJson(`${API.projects}/${encodeURIComponent(projectId)}`, "project")
        : null;
    const boundClientId = project?.project?.client_id || clientContext?.id || null;
    const isCurrentClientContext = Boolean(
        currentClientPage
        && boundClientId
        && String(currentClientPage.id || "").trim() === String(boundClientId || "").trim()
    );
    const clientOptions = boundClientId ? [] : await loadClientOptions();
    const policyOptions = isCurrentClientContext
        ? (Array.isArray(currentClientPage?.policies) ? currentClientPage.policies : []).map((item) => ({
            label: `${item.name || item.policy_code || "Policy"} (${item.policy_code || ""})`,
            value: String(item.policy_code || "").trim(),
        })).filter((item) => item.value)
        : await loadPolicyOptions(boundClientId);
    const boundClientLabel = String(clientContext?.label || "").trim();

    const initial = buildProjectInitialValues(project?.project || null, boundClientId);
    const modal = state.ui.formModal({
        title: mode === "create" ? "New project scope" : "Edit project scope",
        size: "lg",
        rows: buildProjectFormRows(initial, clientOptions, policyOptions, {
            id: boundClientId,
            label: boundClientLabel,
        }, mode === "create"),
        initialValues: initial,
        submitLabel: mode === "create" ? "Create project" : "Save project",
        busyMessage: mode === "create" ? "Creating project..." : "Saving project...",
        closeOnSuccess: true,
        async onSubmit(values, ctx) {
            const nextValues = normalizeProjectSubmission(values, mode === "create" ? boundClientId : project?.project?.client_id);
            const url = mode === "create"
                ? API.projects
                : `${API.projects}/${encodeURIComponent(projectId)}`;

            const method = mode === "create" ? "POST" : "PATCH";
            const { response, data } = await requestJson(url, {
                method,
                headers: {
                    "Content-Type": "application/json",
                },
                body: JSON.stringify(nextValues),
            });

            if (!response.ok) {
                if (data?.errors) {
                    ctx.setErrors(data.errors);
                } else {
                    ctx.setFormError(data?.message || "Project save failed.");
                }
                return false;
            }

            await renderCurrentPage();
            return true;
        },
    });

    if (initial.project_code) {
        attachDisplayCopyButton(modal, "Project code", initial.project_code, "Copy project code");
    }

    const result = modal.open();
    if (result && typeof result.then === "function") {
        await result;
    }
}

async function loadClientOptions() {
    const data = await fetchPageData(`${API.clients}?page=1`);
    const items = Array.isArray(data?.items) ? data.items : [];

    return items.map((item) => ({
        label: `${item.name || item.client_code || "Client"} (${item.client_code || ""})`,
        value: String(item.id || item.client_code || "").trim(),
    })).filter((item) => item.value);
}

async function loadUserOptions() {
    const data = await fetchPageData(API.userOptions);
    return Array.isArray(data?.clients) ? data.clients : [];
}

async function loadPolicyOptions(clientId = null) {
    const suffix = clientId ? `?page=1&client_id=${encodeURIComponent(String(clientId).trim())}` : "?page=1";
    const data = await fetchPageData(`${API.policies}${suffix}`);
    const items = Array.isArray(data?.items) ? data.items : [];

    return items.map((item) => ({
        label: `${item.name || item.policy_code || "Policy"} (${item.policy_code || ""})`,
        value: String(item.policy_code || "").trim(),
    })).filter((item) => item.value);
}

async function openUserModal(mode, userId = null) {
    const record = mode === "edit" && userId
        ? await fetchRecordJson(`${API.users}/${encodeURIComponent(String(userId).trim())}`, "user")
        : null;
    const clientOptions = await loadUserOptions();
    const initial = buildUserInitialValues(record?.user || null);
    const rows = buildUserFormRows(initial, clientOptions, mode === "create");

    const modal = state.ui.formModal({
        title: mode === "create" ? "New user" : "Edit user",
        size: "lg",
        submitLabel: mode === "create" ? "Create user" : "Save user",
        busyMessage: mode === "create" ? "Creating user..." : "Saving user...",
        rows,
        async onSubmit(values, ctx) {
            ctx.clearErrors?.();
            ctx.clearFormError?.();

            const submitValues = normalizeUserSubmission(values, clientOptions);
            const url = mode === "create"
                ? API.users
                : `${API.users}/${encodeURIComponent(String(userId).trim())}`;
            const method = mode === "create" ? "POST" : "PATCH";

            const { response, data } = await requestJson(url, {
                method,
                headers: {
                    "Content-Type": "application/json",
                },
                body: JSON.stringify(submitValues),
            });

            if (!response.ok) {
                if (data?.errors) {
                    ctx.setErrors?.(data.errors);
                } else {
                    ctx.setFormError?.(data?.message || "User save failed.");
                }
                return false;
            }

            await renderCurrentPage();
            return true;
        },
    });

    const result = modal.open();
    if (result && typeof result.then === "function") {
        await result;
    }
}

async function openPolicyModal(mode, policyId = null, clientContext = null, policySeed = null) {
    const policy = mode === "edit" && policyId
        ? await fetchRecordJson(`${API.policies}/${encodeURIComponent(policyId)}`, "policy")
        : null;

    const effectiveClientContext = clientContext || (policy?.policy?.client_id
        ? {
            id: String(policy.policy.client_id || "").trim(),
            code: String(policy.policy.client_code || "").trim(),
            label: `${String(policy.policy.client_name || policy.policy.client_code || "Client").trim()} (${String(policy.policy.client_code || "").trim()})`,
            name: String(policy.policy.client_name || policy.policy.client_code || "Client").trim(),
        }
        : null);

    const initial = buildPolicyInitialValues(policySeed || policy?.policy || null, effectiveClientContext);
    const surface = buildPolicyEditorSurface(initial);
    const modal = state.ui.actionModal({
        title: mode === "create" ? "New policy" : "Edit policy",
        size: "xl",
        className: "policy-editor-modal",
        content: surface.root,
        actions: [
            {
                id: "cancel",
                label: "Cancel",
                variant: "ghost",
            },
            {
                id: "save",
                label: mode === "create" ? "Create policy" : "Save policy",
                variant: "primary",
                autoFocus: true,
                busyMessage: mode === "create" ? "Creating policy..." : "Saving policy...",
                async onClick() {
                    clearPolicyEditorErrors(surface);

                    const nextValues = normalizePolicySubmission(
                        collectPolicyEditorSubmission(surface, initial)
                    );
                    const url = mode === "create"
                        ? API.policies
                        : `${API.policies}/${encodeURIComponent(policyId)}`;

                    const method = mode === "create" ? "POST" : "PATCH";
                    const { response, data } = await requestJson(url, {
                        method,
                        headers: {
                            "Content-Type": "application/json",
                        },
                        body: JSON.stringify(nextValues),
                    });

                    if (!response.ok) {
                        applyPolicyEditorErrors(surface, data?.errors || null, data?.message || "Policy save failed.");
                        return false;
                    }

                    await renderCurrentPage();
                    return true;
                },
            },
        ],
    });

    const result = modal.open();
    if (result && typeof result.then === "function") {
        await result;
    }
}

async function fetchRecordJson(url, key) {
    const { response, data } = await requestJson(url);

    if (!response.ok) {
        if (response.status === 401 || response.status === 419) {
            await handleSessionExpiry();
            return null;
        }

        throw new Error(data?.message || `Unable to load ${key}.`);
    }

    return data?.data || null;
}

function buildClientInitialValues(client) {
    return {
        client_code: client?.client_code || "",
        name: client?.name || "",
        project_code: client?.project_code || "",
        status: client?.status || "active",
        issuer_identity: client?.issuer_identity || "",
        token_issuance_mode: client?.token_issuance_mode || "app_backend_signed",
        trusted_signing_profile: client?.trusted_signing_profile || "",
        integration_owner: client?.integration_owner || client?.name || "",
        description: client?.description || "",
        integration_notes: client?.integration_notes || "",
        trust_notes: client?.trust_notes || "",
        allowed_origins_text: Array.isArray(client?.allowed_origins) ? client.allowed_origins.join("\n") : "",
        origin_policy_mode: client?.origin_policy_mode || "allowlist",
        policy_profile_code: client?.policy_profile_code || "",
        capability_profile_code: client?.capability_profile_code || "",
        room_policy_profile_code: client?.room_policy_profile_code || "",
        has_backend_ingress_secret: Boolean(client?.has_backend_ingress_secret),
        backend_ingress_secret: "",
    };
}

function buildUserInitialValues(user) {
    return {
        id: user?.id || "",
        name: user?.name || "",
        email: user?.email || "",
        user_type: user?.user_type || "regular",
        assigned_client_ids: Array.isArray(user?.assigned_client_ids) ? user.assigned_client_ids.map((value) => String(value).trim()) : [],
        password: "",
        password_confirmation: "",
    };
}

function buildPolicyInitialValues(policy, clientContext = null) {
    return {
        client_id: policy?.client_id || clientContext?.id || "",
        client_name: policy?.client_name || clientContext?.name || "",
        client_code: policy?.client_code || clientContext?.code || "",
        policy_code: policy?.policy_code || "",
        name: policy?.name || "",
        status: policy?.status || "active",
        description: policy?.description || "",
        policy_category: policy?.policy_category || "",
        owner_team: policy?.owner_team || policy?.client_name || "",
        capability_profile_text: formatStructuredValue(policy?.capability_profile || ""),
        room_policy_profile_text: formatStructuredValue(policy?.room_policy_profile || ""),
        rate_limit_profile_text: formatStructuredValue(policy?.rate_limit_profile || ""),
        session_limit_profile_text: formatStructuredValue(policy?.session_limit_profile || ""),
        allow_deny_mode: policy?.allow_deny_mode || "allowlist",
        capability_profile_raw: policy?.capability_profile || {},
        room_policy_profile_raw: policy?.room_policy_profile || {},
        rate_limit_profile_raw: policy?.rate_limit_profile || {},
        session_limit_profile_raw: policy?.session_limit_profile || {},
    };
}

function buildProjectInitialValues(project, clientId = null) {
    const mediaIngest = project?.media_ingest_settings || null;
    const productQuery = project?.product_query_forwarding_settings || null;

    return {
        client_id: project?.client_id || clientId || "",
        project_code: project?.project_code || "",
        name: project?.name || "",
        status: project?.status || "active",
        description: project?.description || "",
        scope_notes: project?.scope_notes || "",
        allowed_origins_text: Array.isArray(project?.allowed_origins) ? project.allowed_origins.join("\n") : "",
        origin_policy_mode: project?.origin_policy_mode || "allowlist",
        policy_profile_code: project?.policy_profile_code || "",
        capability_profile_code: project?.capability_profile_code || "",
        room_policy_profile_code: project?.room_policy_profile_code || "",
        media_ingest_enabled: mediaIngest?.enabled ? "1" : "0",
        media_ingest_base_url: mediaIngest?.base_url || "",
        media_ingest_path: mediaIngest?.path || "/api/internal/media/chunks",
        media_ingest_auth_header: mediaIngest?.auth_header || "X-Realtime-Media-Ingest-Secret",
        media_ingest_auth_token: "",
        media_ingest_auth_token_configured: Boolean(mediaIngest?.auth_token_configured),
        media_ingest_connect_timeout_seconds: String(mediaIngest?.connect_timeout_seconds || 3),
        media_ingest_timeout_seconds: String(mediaIngest?.timeout_seconds || 10),
        media_ingest_verify_tls: mediaIngest?.verify_tls === false ? "0" : "1",
        media_ingest_binary_enabled: mediaIngest?.binary_enabled ? "1" : "0",
        media_ingest_max_binary_chunk_bytes: String(mediaIngest?.max_binary_chunk_bytes || 2097152),
        product_query_forwarding_enabled: productQuery?.enabled ? "1" : "0",
        product_query_forwarding_base_url: productQuery?.base_url || "",
        product_query_forwarding_path: productQuery?.path || "/api/internal/realtime/product-query",
        product_query_forwarding_auth_header: productQuery?.auth_header || "X-Realtime-Backend-Secret",
        product_query_forwarding_auth_token: "",
        product_query_forwarding_auth_token_configured: Boolean(productQuery?.auth_token_configured),
        product_query_forwarding_allowed_queries_text: Array.isArray(productQuery?.allowed_queries) ? productQuery.allowed_queries.join("\n") : "",
        product_query_forwarding_max_payload_bytes: String(productQuery?.max_payload_bytes || 4096),
        product_query_forwarding_rate_limit_per_minute: String(productQuery?.rate_limit_per_minute || 12),
        product_query_forwarding_connect_timeout_seconds: String(productQuery?.connect_timeout_seconds || 3),
        product_query_forwarding_timeout_seconds: String(productQuery?.timeout_seconds || 8),
        product_query_forwarding_verify_tls: productQuery?.verify_tls === false ? "0" : "1",
    };
}

function buildProjectFormRows(values, clientOptions = [], policyOptions = [], clientContext = null, isCreateMode = false) {
    const selectedPolicyOptions = [
        { label: "No policy profile", value: "" },
        ...policyOptions,
    ];
    const hasBoundClient = Boolean(clientContext?.id);

    if (values.policy_profile_code && !selectedPolicyOptions.some((option) => option.value === values.policy_profile_code)) {
        selectedPolicyOptions.unshift({
            label: `${values.policy_profile_code} (current)`,
            value: values.policy_profile_code,
        });
    }

    return [
        [
            ...(hasBoundClient
                ? [
                    { type: "hidden", name: "client_id", value: clientContext.id },
                ]
                : [
                    {
                        type: "select",
                        name: "client_id",
                        label: "Client",
                        required: true,
                        value: values.client_id,
                        options: clientOptions,
                        span: 2,
                    },
                ]),
        ],
        [
            { type: "input", input: "text", name: "name", label: "Name", required: true, value: values.name, span: 2, placeholder: "e.g. Hotline Caller" },
        ],
        [
            ...(isCreateMode
                ? [
                    { type: "hidden", name: "status", value: values.status || "active" },
                    { type: "text", content: "Project code will be generated automatically after save.", span: 2 },
                ]
                : [
                    { type: "display", label: "Project code", value: values.project_code, span: 2, emptyText: "Generated automatically" },
                ]),
        ],
        [
            ...(!isCreateMode
                ? [
                    {
                        type: "select",
                        name: "status",
                        label: "Status",
                        required: true,
                        value: values.status,
                        options: [
                            { label: "Active", value: "active" },
                            { label: "Inactive", value: "inactive" },
                            { label: "Quarantined", value: "quarantined" },
                            { label: "Pending", value: "pending" },
                        ],
                    },
                ]
                : []),
            {
                type: "select",
                name: "origin_policy_mode",
                label: "Origin policy mode",
                required: true,
                value: values.origin_policy_mode,
                options: [
                    { label: "Allowlist", value: "allowlist" },
                    { label: "Disabled", value: "disabled" },
                    { label: "No browser", value: "no_browser" },
                ],
            },
        ],
        [
            {
                type: "select",
                name: "policy_profile_code",
                label: "Policy profile",
                value: values.policy_profile_code || "",
                options: selectedPolicyOptions,
                span: 2,
            },
        ],
        [
            {
                type: "textarea",
                name: "allowed_origins_text",
                label: "Allowed origins",
                value: values.allowed_origins_text,
                span: 2,
                placeholder: values.origin_policy_mode === "no_browser" ? "Not required for no-browser scopes" : "One origin per line",
            },
        ],
        [
            {
                type: "select",
                name: "media_ingest_enabled",
                label: "Media ingest",
                value: values.media_ingest_enabled || "0",
                options: [
                    { label: "Disabled", value: "0" },
                    { label: "Enabled", value: "1" },
                ],
                span: 2,
            },
        ],
        [
            {
                type: "input",
                input: "url",
                name: "media_ingest_base_url",
                label: "Media ingest base URL",
                value: values.media_ingest_base_url || "",
                placeholder: "https://hotline.pbb.ph",
                span: 2,
                visibleWhen: { media_ingest_enabled: "1" },
            },
        ],
        [
            {
                type: "input",
                input: "text",
                name: "media_ingest_path",
                label: "Media ingest path",
                value: values.media_ingest_path || "/api/internal/media/chunks",
                placeholder: "/api/internal/media/chunks",
                visibleWhen: { media_ingest_enabled: "1" },
            },
            {
                type: "input",
                input: "text",
                name: "media_ingest_auth_header",
                label: "Media ingest auth header",
                value: values.media_ingest_auth_header || "X-Realtime-Media-Ingest-Secret",
                placeholder: "X-Realtime-Media-Ingest-Secret",
                visibleWhen: { media_ingest_enabled: "1" },
            },
        ],
        [
            {
                type: "input",
                input: "password",
                name: "media_ingest_auth_token",
                label: "Media ingest auth token",
                value: values.media_ingest_auth_token || "",
                placeholder: values.media_ingest_auth_token_configured
                    ? "Leave blank to keep the existing stored token"
                    : "Enter ingest auth token",
                span: 2,
                visibleWhen: { media_ingest_enabled: "1" },
            },
        ],
        [
            {
                type: "input",
                input: "number",
                name: "media_ingest_connect_timeout_seconds",
                label: "Connect timeout seconds",
                value: values.media_ingest_connect_timeout_seconds || "3",
                min: 1,
                max: 60,
                visibleWhen: { media_ingest_enabled: "1" },
            },
            {
                type: "input",
                input: "number",
                name: "media_ingest_timeout_seconds",
                label: "Timeout seconds",
                value: values.media_ingest_timeout_seconds || "10",
                min: 1,
                max: 120,
                visibleWhen: { media_ingest_enabled: "1" },
            },
        ],
        [
            {
                type: "select",
                name: "media_ingest_verify_tls",
                label: "Verify TLS",
                value: values.media_ingest_verify_tls || "1",
                options: [
                    { label: "Enabled", value: "1" },
                    { label: "Disabled", value: "0" },
                ],
                visibleWhen: { media_ingest_enabled: "1" },
            },
            {
                type: "select",
                name: "media_ingest_binary_enabled",
                label: "Binary transport",
                value: values.media_ingest_binary_enabled || "0",
                options: [
                    { label: "Disabled", value: "0" },
                    { label: "Enabled", value: "1" },
                ],
                visibleWhen: { media_ingest_enabled: "1" },
            },
        ],
        [
            {
                type: "input",
                input: "number",
                name: "media_ingest_max_binary_chunk_bytes",
                label: "Max binary chunk bytes",
                value: values.media_ingest_max_binary_chunk_bytes || "2097152",
                min: 1,
                max: 104857600,
                visibleWhen: { media_ingest_enabled: "1", media_ingest_binary_enabled: "1" },
            },
            {
                type: "text",
                content: "This project scope can publish base64 `media.chunk.publish`; binary prepare/frame transport is available only when enabled.",
                visibleWhen: { media_ingest_enabled: "1" },
            },
        ],
        [
            {
                type: "text",
                content: values.origin_policy_mode === "no_browser"
                    ? "No-browser scopes do not need browser origins. Keep this empty unless the scope later becomes browser-facing."
                    : "Project scopes inherit the rest of their advanced routing data from templates or the client profile.",
                span: 2,
            },
        ],
        [
            {
                type: "select",
                name: "product_query_forwarding_enabled",
                label: "Product query forwarding",
                value: values.product_query_forwarding_enabled || "0",
                options: [
                    { label: "Disabled", value: "0" },
                    { label: "Enabled", value: "1" },
                ],
                span: 2,
            },
        ],
        [
            {
                type: "input",
                input: "url",
                name: "product_query_forwarding_base_url",
                label: "Product query base URL",
                value: values.product_query_forwarding_base_url || "",
                placeholder: "https://hotline.pbb.ph",
                span: 2,
                visibleWhen: { product_query_forwarding_enabled: "1" },
            },
        ],
        [
            {
                type: "input",
                input: "text",
                name: "product_query_forwarding_path",
                label: "Product query path",
                value: values.product_query_forwarding_path || "/api/internal/realtime/product-query",
                placeholder: "/api/internal/realtime/product-query",
                visibleWhen: { product_query_forwarding_enabled: "1" },
            },
            {
                type: "input",
                input: "text",
                name: "product_query_forwarding_auth_header",
                label: "Product query auth header",
                value: values.product_query_forwarding_auth_header || "X-Realtime-Backend-Secret",
                placeholder: "X-Realtime-Backend-Secret",
                visibleWhen: { product_query_forwarding_enabled: "1" },
            },
        ],
        [
            {
                type: "input",
                input: "password",
                name: "product_query_forwarding_auth_token",
                label: "Product query auth token",
                value: values.product_query_forwarding_auth_token || "",
                placeholder: values.product_query_forwarding_auth_token_configured
                    ? "Leave blank to keep the existing stored token"
                    : "Enter product query auth token",
                span: 2,
                visibleWhen: { product_query_forwarding_enabled: "1" },
            },
        ],
        [
            {
                type: "textarea",
                name: "product_query_forwarding_allowed_queries_text",
                label: "Allowed product queries",
                value: values.product_query_forwarding_allowed_queries_text || "",
                placeholder: "hotline.incident.snapshot",
                span: 2,
                visibleWhen: { product_query_forwarding_enabled: "1" },
            },
        ],
        [
            {
                type: "input",
                input: "number",
                name: "product_query_forwarding_max_payload_bytes",
                label: "Max query payload bytes",
                value: values.product_query_forwarding_max_payload_bytes || "4096",
                min: 1,
                max: 65536,
                visibleWhen: { product_query_forwarding_enabled: "1" },
            },
            {
                type: "input",
                input: "number",
                name: "product_query_forwarding_rate_limit_per_minute",
                label: "Query rate limit per minute",
                value: values.product_query_forwarding_rate_limit_per_minute || "12",
                min: 1,
                max: 1000,
                visibleWhen: { product_query_forwarding_enabled: "1" },
            },
        ],
        [
            {
                type: "input",
                input: "number",
                name: "product_query_forwarding_connect_timeout_seconds",
                label: "Query connect timeout seconds",
                value: values.product_query_forwarding_connect_timeout_seconds || "3",
                min: 1,
                max: 60,
                visibleWhen: { product_query_forwarding_enabled: "1" },
            },
            {
                type: "input",
                input: "number",
                name: "product_query_forwarding_timeout_seconds",
                label: "Query timeout seconds",
                value: values.product_query_forwarding_timeout_seconds || "8",
                min: 1,
                max: 120,
                visibleWhen: { product_query_forwarding_enabled: "1" },
            },
        ],
        [
            {
                type: "select",
                name: "product_query_forwarding_verify_tls",
                label: "Query verify TLS",
                value: values.product_query_forwarding_verify_tls || "1",
                options: [
                    { label: "Enabled", value: "1" },
                    { label: "Disabled", value: "0" },
                ],
                visibleWhen: { product_query_forwarding_enabled: "1" },
            },
            {
                type: "text",
                content: "`product.query.request` is forwarded only for allowlisted query values; product responses still publish through backend ingress.",
                visibleWhen: { product_query_forwarding_enabled: "1" },
            },
        ],
    ];
}

function normalizeProjectSubmission(values, clientId = null) {
    const next = { ...values };
    next.client_id = clientId || next.client_id;
    next.allowed_origins = String(next.allowed_origins_text || "")
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean);
    delete next.project_code;
    delete next.allowed_origins_text;
    next.policy_profile_code = String(next.policy_profile_code || "").trim() || null;
    next.media_ingest_enabled = String(next.media_ingest_enabled || "0") === "1";
    next.media_ingest_base_url = String(next.media_ingest_base_url || "").trim();
    next.media_ingest_path = String(next.media_ingest_path || "").trim();
    next.media_ingest_auth_header = String(next.media_ingest_auth_header || "").trim();
    next.media_ingest_auth_token = String(next.media_ingest_auth_token || "").trim();
    next.media_ingest_connect_timeout_seconds = Number(next.media_ingest_connect_timeout_seconds || 3) || 3;
    next.media_ingest_timeout_seconds = Number(next.media_ingest_timeout_seconds || 10) || 10;
    next.media_ingest_verify_tls = String(next.media_ingest_verify_tls || "1") === "1";
    next.media_ingest_binary_enabled = String(next.media_ingest_binary_enabled || "0") === "1";
    next.media_ingest_max_binary_chunk_bytes = Number(next.media_ingest_max_binary_chunk_bytes || 2097152) || 2097152;
    delete next.media_ingest_auth_token_configured;
    next.product_query_forwarding_enabled = String(next.product_query_forwarding_enabled || "0") === "1";
    next.product_query_forwarding_base_url = String(next.product_query_forwarding_base_url || "").trim();
    next.product_query_forwarding_path = String(next.product_query_forwarding_path || "").trim();
    next.product_query_forwarding_auth_header = String(next.product_query_forwarding_auth_header || "").trim();
    next.product_query_forwarding_auth_token = String(next.product_query_forwarding_auth_token || "").trim();
    next.product_query_forwarding_allowed_queries_text = String(next.product_query_forwarding_allowed_queries_text || "").trim();
    next.product_query_forwarding_max_payload_bytes = Number(next.product_query_forwarding_max_payload_bytes || 4096) || 4096;
    next.product_query_forwarding_rate_limit_per_minute = Number(next.product_query_forwarding_rate_limit_per_minute || 12) || 12;
    next.product_query_forwarding_connect_timeout_seconds = Number(next.product_query_forwarding_connect_timeout_seconds || 3) || 3;
    next.product_query_forwarding_timeout_seconds = Number(next.product_query_forwarding_timeout_seconds || 8) || 8;
    next.product_query_forwarding_verify_tls = String(next.product_query_forwarding_verify_tls || "1") === "1";
    delete next.product_query_forwarding_auth_token_configured;
    return next;
}

function buildClientFormRows(values) {
    return [
        [
            values.client_code
                ? { type: "display", label: "Client code", value: values.client_code, span: 1, emptyText: "Generated automatically" }
                : { type: "text", content: "Client code will be generated automatically after save.", span: 2 },
        ],
        [
            { type: "input", input: "text", name: "name", label: "Name", required: true, value: values.name },
            {
                type: "select",
                name: "status",
                label: "Status",
                required: true,
                value: values.status,
                options: [
                    { label: "Active", value: "active" },
                    { label: "Inactive", value: "inactive" },
                    { label: "Quarantined", value: "quarantined" },
                    { label: "Pending", value: "pending" },
                ],
            },
        ],
        [
            {
                type: "input",
                input: "text",
                name: "issuer_identity",
                label: "Issuer identity",
                value: values.issuer_identity || "",
                placeholder: "e.g. hotline-auth@pbb.ph",
            },
            {
                type: "input",
                input: "text",
                name: "trusted_signing_profile",
                label: "Trusted signing profile",
                value: values.trusted_signing_profile || "",
                placeholder: "e.g. hotline-app-backend",
            },
        ],
        [
            {
                type: "select",
                name: "token_issuance_mode",
                label: "Token issuance mode",
                required: true,
                value: values.token_issuance_mode,
                options: [
                    { label: "App backend signed", value: "app_backend_signed" },
                    { label: "Realtime fallback", value: "realtime_issued_fallback" },
                    { label: "Manual review", value: "manual_review" },
                ],
            },
            { type: "hidden", name: "integration_owner", value: values.integration_owner || values.name || "" },
            { type: "hidden", name: "description", value: values.description || "" },
            { type: "hidden", name: "integration_notes", value: values.integration_notes || "" },
            { type: "hidden", name: "trust_notes", value: values.trust_notes || "" },
            { type: "hidden", name: "allowed_origins_text", value: values.allowed_origins_text || "" },
            { type: "hidden", name: "origin_policy_mode", value: values.origin_policy_mode || "allowlist" },
            { type: "hidden", name: "policy_profile_code", value: values.policy_profile_code || "" },
            { type: "hidden", name: "capability_profile_code", value: values.capability_profile_code || "" },
            { type: "hidden", name: "room_policy_profile_code", value: values.room_policy_profile_code || "" },
        ],
        [
            {
                type: "input",
                input: "password",
                name: "backend_ingress_secret",
                label: "Backend ingress secret",
                value: values.backend_ingress_secret || "",
                placeholder: values.has_backend_ingress_secret ? "Leave blank to keep current secret" : "Set initial backend secret",
            },
            {
                type: "text",
                content: values.has_backend_ingress_secret
                    ? "A backend ingress secret is already configured. Enter a new one only to rotate it."
                    : "Optional. Set this only if the client needs trusted backend event publishing into Realtime rooms.",
            },
        ],
        [
            { type: "text", content: "Project scopes, origins, and policy profiles are managed from each client detail page.", span: 2 },
        ],
    ];
}

function buildUserFormRows(values, clientOptions = [], isCreateMode = false) {
    const clientItems = clientOptions.map((client) => ({
        value: String(client?.id ?? client?.value ?? "").trim(),
        label: `${String(client?.name || client?.label || "Client").trim()} (${String(client?.client_code || "").trim()})`,
    })).filter((item) => item.value);

    return [
        [
            { type: "input", input: "text", name: "name", label: "Name", required: true, value: values.name },
            { type: "input", input: "email", name: "email", label: "Email", required: true, value: values.email },
        ],
        [
            {
                type: "select",
                name: "user_type",
                label: "User type",
                required: true,
                value: values.user_type,
                options: [
                    { label: "Admin", value: "admin" },
                    { label: "Regular", value: "regular" },
                ],
            },
            ...(isCreateMode
                ? [
                    { type: "text", content: "Regular users see only assigned clients. Admins keep global visibility.", span: 1 },
                ]
                : [
                    { type: "display", label: "User id", value: values.id, emptyText: "Generated automatically" },
                ]),
        ],
        [
            {
                type: "ui.select",
                name: "assigned_client_ids",
                label: "Assigned clients",
                value: values.assigned_client_ids,
                items: clientItems,
                multiple: true,
                searchable: true,
                clearable: true,
                closeOnSelect: false,
                span: 2,
                placeholder: "Choose one or more clients",
            },
        ],
        [
            {
                type: "text",
                content: clientItems.length
                    ? "Regular users can only see the clients assigned here. Admin users keep global access even if no assignments are selected."
                    : "No clients are registered yet.",
                span: 2,
            },
        ],
        [
            {
                type: "input",
                input: "password",
                name: "password",
                label: isCreateMode ? "Password" : "New password",
                required: isCreateMode,
                value: values.password,
                placeholder: isCreateMode ? "Initial password" : "Leave blank to keep current password",
            },
            {
                type: "input",
                input: "password",
                name: "password_confirmation",
                label: isCreateMode ? "Confirm password" : "Confirm new password",
                required: isCreateMode,
                value: values.password_confirmation,
                placeholder: isCreateMode ? "Repeat password" : "Repeat new password",
            },
        ],
    ];
}

function normalizeSelectItems(items) {
    return (Array.isArray(items) ? items : [])
        .map((item) => {
            if (item && typeof item === "object") {
                return {
                    label: String(item.label || item.value || "").trim(),
                    value: String(item.value || item.label || "").trim(),
                };
            }

            const value = String(item || "").trim();
            return value
                ? { label: value, value }
                : null;
        })
        .filter(Boolean)
        .filter((item, index, array) => array.findIndex((next) => next.value === item.value) === index);
}

function withCurrentSelectItem(items, currentValue, currentLabel) {
    const value = String(currentValue || "").trim();
    const list = [...(Array.isArray(items) ? items : [])];

    if (value && !list.some((item) => String(item.value || "") === value)) {
        list.unshift({
            label: `${currentLabel || "Current value"}: ${value}`,
            value,
        });
    }

    return list;
}

function normalizeClientSubmission(values) {
    const next = { ...values };
    delete next.client_code;
    delete next.project_code;
    delete next.has_backend_ingress_secret;

    if (!String(next.backend_ingress_secret || "").trim()) {
        delete next.backend_ingress_secret;
    } else {
        next.backend_ingress_secret = String(next.backend_ingress_secret).trim();
    }

    return next;
}

function normalizeUserSubmission(values, clientOptions = []) {
    const next = { ...values };
    const validIds = new Set(
        (Array.isArray(clientOptions) ? clientOptions : [])
            .map((item) => Number(item.id))
            .filter((value) => Number.isInteger(value) && value > 0)
    );
    const clientIds = (Array.isArray(next.assigned_client_ids) ? next.assigned_client_ids : [next.assigned_client_ids])
        .map((value) => Number(value))
        .filter((value) => validIds.has(value))
        .filter((value) => Number.isInteger(value) && value > 0);

    next.client_ids = Array.from(new Set(clientIds));
    delete next.assigned_client_ids;
    if (!String(next.password || "").trim()) {
        delete next.password;
        delete next.password_confirmation;
    }
    delete next.id;

    return next;
}

function getClientRouteKey(client) {
    return String(client?.client_code || "").trim();
}

function getClientDatabaseId(client) {
    return String(client?.id || "").trim();
}

function buildRowText(primary, secondary = "") {
    const host = document.createElement("div");
    host.className = "grid-stacked";

    const title = document.createElement("strong");
    title.textContent = String(primary || "");
    host.appendChild(title);

    const secondaryText = String(secondary || "").trim();
    if (secondaryText) {
        const subtext = document.createElement("div");
        subtext.className = "muted tiny";
        subtext.textContent = secondaryText;
        host.appendChild(subtext);
    }

    return host;
}

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

function measureTextWidth(value, options = {}) {
    const text = String(value ?? "").trim();
    const charWidth = Number(options.charWidth || 8);
    const padding = Number(options.padding || 32);
    const min = Number(options.min || 80);
    const max = Number(options.max || 420);

    if (!text) {
        return min;
    }

    return clamp(Math.ceil(text.length * charWidth) + padding, min, max);
}

function measureColumnWidth(items, options = {}) {
    const rows = Array.isArray(items) ? items : [];
    const labelWidth = measureTextWidth(options.label || "", {
        charWidth: options.headerCharWidth || 9,
        padding: options.headerPadding || 40,
        min: options.min || 80,
        max: options.max || 420,
    });

    const valueWidths = rows.map((row) => {
        if (typeof options.value === "function") {
            return measureTextWidth(options.value(row), options);
        }

        return measureTextWidth(row?.[options.key], options);
    });

    return Math.max(labelWidth, ...valueWidths, Number(options.min || 80));
}

function measureStackedColumnWidth(items, options = {}) {
    const rows = Array.isArray(items) ? items : [];
    const labelWidth = measureTextWidth(options.label || "", {
        charWidth: options.headerCharWidth || 9,
        padding: options.headerPadding || 40,
        min: options.min || 120,
        max: options.max || 420,
    });

    const valueWidths = rows.map((row) => {
        const primaryWidth = measureTextWidth(options.primary ? options.primary(row) : "", {
            charWidth: options.primaryCharWidth || 8.6,
            padding: options.primaryPadding || 40,
            min: options.min || 120,
            max: options.max || 420,
        });

        const secondaryWidth = measureTextWidth(options.secondary ? options.secondary(row) : "", {
            charWidth: options.secondaryCharWidth || 7.6,
            padding: options.secondaryPadding || 40,
            min: options.min || 120,
            max: options.max || 420,
        });

        return Math.max(primaryWidth, secondaryWidth);
    });

    return Math.max(labelWidth, ...valueWidths, Number(options.min || 120));
}

function buildPolicyFormRows(values) {
    return [
        [
            {
                type: "input",
                input: "text",
                name: "name",
                label: "Name",
                required: true,
                value: values.name,
                span: 2,
                placeholder: "e.g. Hotline Caller Policy V1",
            },
        ],
        [
            values.policy_code
                ? { type: "display", label: "Policy code", value: values.policy_code, span: 2, emptyText: "Generated automatically" }
                : { type: "text", content: "Policy code will be generated automatically after save.", span: 2 },
        ],
        [
            {
                type: "select",
                name: "status",
                label: "Status",
                required: true,
                value: values.status,
                options: [
                    { label: "Draft", value: "draft" },
                    { label: "Active", value: "active" },
                    { label: "Inactive", value: "inactive" },
                    { label: "Deprecated", value: "deprecated" },
                ],
            },
            {
                type: "select",
                name: "allow_deny_mode",
                label: "Allow/deny mode",
                required: true,
                value: values.allow_deny_mode,
                options: [
                    { label: "Allowlist", value: "allowlist" },
                    { label: "Denylist", value: "denylist" },
                    { label: "Mixed", value: "mixed" },
                ],
            },
        ],
        [
            {
                type: "text",
                content: "Policy profiles, rates, and room rules are managed as advanced data and can be expanded later from templates.",
                span: 2,
            },
        ],
        [
            { type: "hidden", name: "description", value: values.description || "" },
            { type: "hidden", name: "policy_category", value: values.policy_category || "" },
            { type: "hidden", name: "owner_team", value: values.owner_team || "" },
            { type: "hidden", name: "capability_profile_text", value: values.capability_profile_text || "" },
            { type: "hidden", name: "room_policy_profile_text", value: values.room_policy_profile_text || "" },
            { type: "hidden", name: "rate_limit_profile_text", value: values.rate_limit_profile_text || "" },
            { type: "hidden", name: "session_limit_profile_text", value: values.session_limit_profile_text || "" },
        ],
    ];
}

function buildPolicyEditorSurface(initial) {
    const root = document.createElement("div");
    root.className = "policy-editor-surface";
    const isCreateMode = !String(initial.policy_code || "").trim();

    const formError = document.createElement("div");
    formError.className = "ui-form-error policy-editor-form-error";
    formError.hidden = true;

    const basics = document.createElement("div");
    basics.className = "policy-editor-basics";
    basics.innerHTML = `
        <div class="policy-editor-grid">
            <input type="hidden" name="client_id" value="${escapeHtml(initial.client_id || "")}">
            <label class="form-stack policy-editor-field policy-editor-field-span-2">
                <span class="label">Name</span>
                <input class="field" type="text" name="name" value="${escapeHtml(initial.name || "")}" placeholder="e.g. Hotline Caller Policy V1">
            </label>
            ${isCreateMode ? `<input type="hidden" name="status" value="${escapeHtml(initial.status || "active")}">` : `
            <div class="form-stack policy-editor-field policy-editor-field-span-2">
                <span class="label">Policy code</span>
                <div class="ui-form-modal-display-value display-value-with-copy">
                    <span>${escapeHtml(initial.policy_code || "Generated automatically after save")}</span>
                    ${createInlineCopyButton(initial.policy_code, "Copy policy code")}
                </div>
            </div>
            <label class="form-stack policy-editor-field">
                <span class="label">Status</span>
                <select class="field" name="status">
                    ${renderOptionTags(initial.status, [
                        { label: "Draft", value: "draft" },
                        { label: "Active", value: "active" },
                        { label: "Inactive", value: "inactive" },
                        { label: "Deprecated", value: "deprecated" },
                    ])}
                </select>
            </label>`}
            <label class="form-stack policy-editor-field">
                <span class="label">Allow/deny mode</span>
                <select class="field" name="allow_deny_mode">
                    ${renderOptionTags(initial.allow_deny_mode, [
                        { label: "Allowlist", value: "allowlist" },
                        { label: "Denylist", value: "denylist" },
                        { label: "Mixed", value: "mixed" },
                    ])}
                </select>
            </label>
        </div>
    `;

    const propertyEditorHost = document.createElement("div");
    propertyEditorHost.className = "policy-property-editor-host";

    root.append(formError, basics, propertyEditorHost);
    bindCopyButtons(root);

    const propertyEditor = state.ui.propertyEditor(propertyEditorHost, {
        selectionLabel: initial.name || initial.policy_code || "Policy",
        sections: buildPolicyPropertyEditorSections(initial),
    }, {
        className: "policy-property-editor",
        dense: true,
        labelWidth: 210,
        showSelectionLabel: false,
    });

    return {
        root,
        formError,
        basics,
        propertyEditor,
    };
}

function renderOptionTags(selectedValue, options = []) {
    const current = String(selectedValue || "");
    return (Array.isArray(options) ? options : []).map((option) => {
        const value = String(option?.value || "");
        const selected = value === current ? ' selected="selected"' : "";
        return `<option value="${escapeHtml(value)}"${selected}>${escapeHtml(option?.label || value)}</option>`;
    }).join("");
}

function buildPolicyPropertyEditorSections(initial) {
    const capabilityProfile = initial?.capability_profile_raw || {};
    const roomPolicyProfile = initial?.room_policy_profile_raw || {};
    const rateLimitProfile = initial?.rate_limit_profile_raw || {};
    const attachmentPolicy = rateLimitProfile?.attachment_policy || {};
    const attachmentTransport = rateLimitProfile?.attachment_transport || {};
    const sessionLimitProfile = initial?.session_limit_profile_raw || {};

    return [
        {
            id: "capabilities",
            title: "Capability profile",
            description: "Allowed capability groups and operations for this policy.",
            properties: [
                {
                    id: "capability.rooms",
                    label: "Rooms",
                    kind: "ui.select",
                    value: normalizeStringArray(capabilityProfile?.rooms),
                    multiple: true,
                    searchable: false,
                    closeOnSelect: false,
                    clearable: true,
                    items: toSelectItems(["join", "leave", "publish"], capabilityProfile?.rooms),
                    help: "Room membership and server room publish actions allowed by this policy.",
                },
                {
                    id: "capability.events",
                    label: "Events",
                    kind: "ui.select",
                    value: normalizeStringArray(capabilityProfile?.events),
                    multiple: true,
                    searchable: false,
                    closeOnSelect: false,
                    clearable: true,
                    items: toSelectItems(["publish", "event.publish"], capabilityProfile?.events),
                    help: "Server-originated event publish permissions for backend ingress.",
                },
                {
                    id: "capability.capabilities",
                    label: "Global capabilities",
                    kind: "ui.select",
                    value: normalizeStringArray(capabilityProfile?.capabilities),
                    multiple: true,
                    searchable: false,
                    closeOnSelect: false,
                    clearable: true,
                    items: toSelectItems(["event.publish"], capabilityProfile?.capabilities),
                    help: "Root capability overrides used by token claims and ingress checks.",
                },
                {
                    id: "capability.presence",
                    label: "Presence",
                    kind: "ui.select",
                    value: normalizeStringArray(capabilityProfile?.presence),
                    multiple: true,
                    searchable: false,
                    closeOnSelect: false,
                    clearable: true,
                    items: toSelectItems(["publish", "subscribe"], capabilityProfile?.presence),
                },
                {
                    id: "capability.chat",
                    label: "Chat",
                    kind: "ui.select",
                    value: normalizeStringArray(capabilityProfile?.chat),
                    multiple: true,
                    searchable: false,
                    closeOnSelect: false,
                    clearable: true,
                    items: toSelectItems(["publish", "subscribe"], capabilityProfile?.chat),
                },
                {
                    id: "capability.media",
                    label: "Media",
                    kind: "ui.select",
                    value: normalizeStringArray(capabilityProfile?.media),
                    multiple: true,
                    searchable: false,
                    closeOnSelect: false,
                    clearable: true,
                    items: toSelectItems(["request", "stream", "review", "persist", "publish"], capabilityProfile?.media),
                },
                {
                    id: "capability.call",
                    label: "Call",
                    kind: "ui.select",
                    value: normalizeStringArray(capabilityProfile?.call),
                    multiple: true,
                    searchable: false,
                    closeOnSelect: false,
                    clearable: true,
                    items: toSelectItems(["signal", "reconnect", "answer", "transfer", "disconnect"], capabilityProfile?.call),
                },
                {
                    id: "capability.incidents",
                    label: "Incidents",
                    kind: "ui.select",
                    value: normalizeStringArray(capabilityProfile?.incidents),
                    multiple: true,
                    searchable: false,
                    closeOnSelect: false,
                    clearable: true,
                    items: toSelectItems(["publish", "review", "resolve", "close"], capabilityProfile?.incidents),
                },
            ],
        },
        {
            id: "rooms",
            title: "Room policy profile",
            description: "Allowed room lists and prefixes.",
            properties: [
                {
                    id: "room_policy.mode",
                    label: "Mode",
                    kind: "select",
                    value: String(roomPolicyProfile?.mode || "allowlist").trim() || "allowlist",
                    options: [
                        { label: "Allowlist", value: "allowlist" },
                        { label: "Denylist", value: "denylist" },
                        { label: "Disabled", value: "disabled" },
                    ],
                    help: "Allowlist permits only the listed rooms or prefixes. Disabled blocks room access entirely.",
                },
                {
                    id: "room_policy.prefixes",
                    label: "Room prefixes",
                    kind: "textarea",
                    value: arrayToMultiline(roomPolicyProfile?.prefixes ?? roomPolicyProfile?.allowed_prefixes),
                    placeholder: "One room prefix per line",
                    help: "Example: chat.thread.",
                },
                {
                    id: "room_policy.rooms",
                    label: "Exact rooms",
                    kind: "textarea",
                    value: arrayToMultiline(roomPolicyProfile?.rooms ?? roomPolicyProfile?.allowed_rooms),
                    placeholder: "One exact room per line",
                },
            ],
        },
        {
            id: "rate_limits",
            title: "Rate limit profile",
            description: "Per-minute limits for transport activity.",
            properties: [
                {
                    id: "rate.session_pings_per_minute",
                    label: "Session pings/min",
                    kind: "number",
                    value: normalizeNumberOrEmpty(rateLimitProfile?.session_pings_per_minute),
                    min: 0,
                    step: 1,
                },
                {
                    id: "rate.joins_per_minute",
                    label: "Room joins/min",
                    kind: "number",
                    value: normalizeNumberOrEmpty(rateLimitProfile?.joins_per_minute),
                    min: 0,
                    step: 1,
                },
                {
                    id: "rate.leaves_per_minute",
                    label: "Room leaves/min",
                    kind: "number",
                    value: normalizeNumberOrEmpty(rateLimitProfile?.leaves_per_minute),
                    min: 0,
                    step: 1,
                },
                {
                    id: "rate.presence_publishes_per_minute",
                    label: "Presence publishes/min",
                    kind: "number",
                    value: normalizeNumberOrEmpty(rateLimitProfile?.presence_publishes_per_minute),
                    min: 0,
                    step: 1,
                },
                {
                    id: "rate.presence_subscribes_per_minute",
                    label: "Presence subscribes/min",
                    kind: "number",
                    value: normalizeNumberOrEmpty(rateLimitProfile?.presence_subscribes_per_minute),
                    min: 0,
                    step: 1,
                },
                {
                    id: "rate.chat_messages_per_minute",
                    label: "Chat publishes/min",
                    kind: "number",
                    value: normalizeNumberOrEmpty(rateLimitProfile?.chat_messages_per_minute),
                    min: 0,
                    step: 1,
                },
                {
                    id: "rate.call_signals_per_minute",
                    label: "Call signals/min",
                    kind: "number",
                    value: normalizeNumberOrEmpty(rateLimitProfile?.call_signals_per_minute),
                    min: 0,
                    step: 1,
                },
                {
                    id: "rate.media_events_per_minute",
                    label: "Media events/min",
                    kind: "number",
                    value: normalizeNumberOrEmpty(rateLimitProfile?.media_events_per_minute),
                    min: 0,
                    step: 1,
                },
                {
                    id: "rate.event_publish_per_minute",
                    label: "Server events/min",
                    kind: "number",
                    value: normalizeNumberOrEmpty(rateLimitProfile?.event_publish_per_minute ?? rateLimitProfile?.server_event_publish_per_minute),
                    min: 0,
                    step: 1,
                },
                {
                    id: "rate.attachment_transport.chunk_events_per_minute",
                    label: "Chunk events/min",
                    kind: "number",
                    value: normalizeNumberOrEmpty(attachmentTransport?.chunk_events_per_minute),
                    min: 0,
                    step: 1,
                },
                {
                    id: "rate.attachment_transport.chunk_bytes_per_minute",
                    label: "Chunk bytes/min",
                    kind: "number",
                    value: normalizeNumberOrEmpty(attachmentTransport?.chunk_bytes_per_minute),
                    min: 0,
                    step: 1024,
                },
            ],
        },
        {
            id: "session_limits",
            title: "Session limit profile",
            description: "Session-related caps for this policy.",
            properties: [
                {
                    id: "session.concurrent_sessions",
                    label: "Concurrent sessions",
                    kind: "number",
                    value: normalizeNumberOrEmpty(sessionLimitProfile?.concurrent_sessions),
                    min: 0,
                    step: 1,
                },
                {
                    id: "session.max_rooms_per_session",
                    label: "Max rooms/session",
                    kind: "number",
                    value: normalizeNumberOrEmpty(sessionLimitProfile?.max_rooms_per_session),
                    min: 0,
                    step: 1,
                },
                {
                    id: "session.idle_timeout_minutes",
                    label: "Idle timeout/min",
                    kind: "number",
                    value: normalizeNumberOrEmpty(sessionLimitProfile?.idle_timeout_minutes),
                    min: 0,
                    step: 1,
                },
            ],
        },
        {
            id: "attachments",
            title: "Attachment transport policy",
            description: "Attachment-specific constraints enforced before and during transport.",
            properties: [
                {
                    id: "attachment.max_attachment_count",
                    label: "Attachment count",
                    kind: "number",
                    value: normalizeNumberOrEmpty(attachmentPolicy?.max_attachment_count),
                    min: 0,
                    step: 1,
                },
                {
                    id: "attachment.max_attachment_bytes",
                    label: "Max bytes/file",
                    kind: "number",
                    value: normalizeNumberOrEmpty(attachmentPolicy?.max_attachment_bytes ?? attachmentPolicy?.max_bytes_per_attachment),
                    min: 0,
                    step: 1024,
                },
                {
                    id: "attachment.max_total_bytes_per_message",
                    label: "Max bytes/message",
                    kind: "number",
                    value: normalizeNumberOrEmpty(attachmentPolicy?.max_total_bytes_per_message),
                    min: 0,
                    step: 1024,
                },
                {
                    id: "attachment.allowed_mime_types",
                    label: "Allowed mime types",
                    kind: "textarea",
                    value: arrayToMultiline(attachmentPolicy?.allowed_mime_types),
                    placeholder: "One mime type per line",
                },
            ],
        },
    ];
}

function toSelectItems(values = [], currentValues = []) {
    return uniqueStringValues(values, currentValues).map((value) => ({
        value: String(value),
        label: formatLabel(value),
    }));
}

function uniqueStringValues(...valueSets) {
    return [...new Set(valueSets.flatMap((value) => normalizeStringArray(value)))];
}

function normalizeStringArray(value) {
    if (!Array.isArray(value)) {
        return [];
    }
    return value.map((entry) => String(entry || "").trim()).filter(Boolean);
}

function arrayToMultiline(value) {
    return normalizeStringArray(value).join("\n");
}

function normalizeNumberOrEmpty(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : "";
}

function collectPolicyEditorSubmission(surface, initial) {
    const basics = surface.basics;
    const stateSnapshot = surface.propertyEditor.getState();
    const sectionMap = Object.fromEntries((Array.isArray(stateSnapshot?.sections) ? stateSnapshot.sections : [])
        .map((section) => [section.id, section]));

    const capabilityProfile = mergePolicyProfileObject(
        initial.capability_profile_raw,
        {
            rooms: getEditorPropertyValue(sectionMap, "capabilities", "capability.rooms", []),
            events: getEditorPropertyValue(sectionMap, "capabilities", "capability.events", []),
            capabilities: getEditorPropertyValue(sectionMap, "capabilities", "capability.capabilities", []),
            presence: getEditorPropertyValue(sectionMap, "capabilities", "capability.presence", []),
            chat: getEditorPropertyValue(sectionMap, "capabilities", "capability.chat", []),
            media: getEditorPropertyValue(sectionMap, "capabilities", "capability.media", []),
            call: getEditorPropertyValue(sectionMap, "capabilities", "capability.call", []),
            incidents: getEditorPropertyValue(sectionMap, "capabilities", "capability.incidents", []),
        },
        { arrayKeys: ["rooms", "events", "capabilities", "presence", "chat", "media", "call", "incidents"] }
    );

    const roomPrefixes = multilineToArray(getEditorPropertyValue(sectionMap, "rooms", "room_policy.prefixes", ""));
    const roomList = multilineToArray(getEditorPropertyValue(sectionMap, "rooms", "room_policy.rooms", ""));
    const roomPolicyProfile = mergePolicyProfileObject(
        initial.room_policy_profile_raw,
        {
            mode: String(getEditorPropertyValue(sectionMap, "rooms", "room_policy.mode", "allowlist")).trim() || "allowlist",
            prefixes: roomPrefixes,
            allowed_prefixes: roomPrefixes,
            rooms: roomList,
            allowed_rooms: roomList,
        },
        { arrayKeys: ["prefixes", "allowed_prefixes", "rooms", "allowed_rooms"] }
    );

    const eventPublishPerMinute = normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "rate_limits", "rate.event_publish_per_minute", ""));
    const rateLimitProfile = mergePolicyProfileObject(
        initial.rate_limit_profile_raw,
        {
            session_pings_per_minute: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "rate_limits", "rate.session_pings_per_minute", "")),
            joins_per_minute: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "rate_limits", "rate.joins_per_minute", "")),
            leaves_per_minute: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "rate_limits", "rate.leaves_per_minute", "")),
            presence_publishes_per_minute: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "rate_limits", "rate.presence_publishes_per_minute", "")),
            presence_subscribes_per_minute: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "rate_limits", "rate.presence_subscribes_per_minute", "")),
            chat_messages_per_minute: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "rate_limits", "rate.chat_messages_per_minute", "")),
            call_signals_per_minute: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "rate_limits", "rate.call_signals_per_minute", "")),
            media_events_per_minute: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "rate_limits", "rate.media_events_per_minute", "")),
            event_publish_per_minute: eventPublishPerMinute,
            server_event_publish_per_minute: eventPublishPerMinute,
            attachment_transport: stripEmptyObject({
                chunk_events_per_minute: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "rate_limits", "rate.attachment_transport.chunk_events_per_minute", "")),
                chunk_bytes_per_minute: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "rate_limits", "rate.attachment_transport.chunk_bytes_per_minute", "")),
            }),
            attachment_policy: stripEmptyObject({
                max_attachment_count: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "attachments", "attachment.max_attachment_count", "")),
                max_attachment_bytes: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "attachments", "attachment.max_attachment_bytes", "")),
                max_bytes_per_attachment: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "attachments", "attachment.max_attachment_bytes", "")),
                max_total_bytes_per_message: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "attachments", "attachment.max_total_bytes_per_message", "")),
                allowed_mime_types: multilineToArray(getEditorPropertyValue(sectionMap, "attachments", "attachment.allowed_mime_types", "")),
            }),
        },
        { objectKeys: ["attachment_transport", "attachment_policy"] }
    );

    const sessionLimitProfile = mergePolicyProfileObject(
        initial.session_limit_profile_raw,
        {
            concurrent_sessions: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "session_limits", "session.concurrent_sessions", "")),
            max_rooms_per_session: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "session_limits", "session.max_rooms_per_session", "")),
            idle_timeout_minutes: normalizeOptionalNumber(getEditorPropertyValue(sectionMap, "session_limits", "session.idle_timeout_minutes", "")),
        }
    );

    return {
        client_id: String(basics.querySelector('[name="client_id"]')?.value || initial.client_id || "").trim(),
        policy_code: initial.policy_code || "",
        name: String(basics.querySelector('[name="name"]')?.value || "").trim(),
        status: String(basics.querySelector('[name="status"]')?.value || "draft").trim(),
        allow_deny_mode: String(basics.querySelector('[name="allow_deny_mode"]')?.value || "allowlist").trim(),
        description: initial.description || "",
        policy_category: initial.policy_category || "",
        owner_team: initial.owner_team || "",
        capability_profile_text: JSON.stringify(capabilityProfile, null, 2),
        room_policy_profile_text: JSON.stringify(roomPolicyProfile, null, 2),
        rate_limit_profile_text: JSON.stringify(rateLimitProfile, null, 2),
        session_limit_profile_text: JSON.stringify(sessionLimitProfile, null, 2),
    };
}

function getEditorPropertyValue(sectionMap, sectionId, propertyId, fallback) {
    const section = sectionMap?.[sectionId];
    const property = Array.isArray(section?.properties)
        ? section.properties.find((item) => item?.id === propertyId)
        : null;

    return property && Object.prototype.hasOwnProperty.call(property, "value")
        ? property.value
        : fallback;
}

function multilineToArray(value) {
    return String(value || "")
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean);
}

function normalizeOptionalNumber(value) {
    const trimmed = String(value ?? "").trim();
    if (trimmed === "") {
        return null;
    }
    const number = Number(trimmed);
    return Number.isFinite(number) ? number : null;
}

function stripEmptyObject(value) {
    const next = Object.entries(value || {}).reduce((carry, [key, entry]) => {
        if (Array.isArray(entry)) {
            if (entry.length) {
                carry[key] = entry;
            }
            return carry;
        }
        if (entry && typeof entry === "object") {
            if (Object.keys(entry).length) {
                carry[key] = entry;
            }
            return carry;
        }
        if (entry !== null && entry !== "" && typeof entry !== "undefined") {
            carry[key] = entry;
        }
        return carry;
    }, {});

    return Object.keys(next).length ? next : null;
}

function mergePolicyProfileObject(original, updates, options = {}) {
    const base = original && typeof original === "object" && !Array.isArray(original)
        ? { ...original }
        : {};
    const next = { ...base };

    Object.entries(updates || {}).forEach(([key, value]) => {
        if (Array.isArray(value)) {
            if (value.length) {
                next[key] = value;
            } else {
                delete next[key];
            }
            return;
        }

        if (value && typeof value === "object") {
            if (Object.keys(value).length) {
                next[key] = value;
            } else {
                delete next[key];
            }
            return;
        }

        if (value === null || value === "") {
            delete next[key];
            return;
        }

        next[key] = value;
    });

    return next;
}

function clearPolicyEditorErrors(surface) {
    if (surface.formError) {
        surface.formError.hidden = true;
        surface.formError.textContent = "";
    }
    surface.propertyEditor?.clearErrors?.();
}

function applyPolicyEditorErrors(surface, errors, message) {
    const propertyErrors = {};
    if (errors?.client_id) {
        propertyErrors["client_id"] = Array.isArray(errors.client_id) ? errors.client_id[0] : String(errors.client_id);
    }
    if (errors?.name) {
        propertyErrors["name"] = Array.isArray(errors.name) ? errors.name[0] : String(errors.name);
    }
    if (Object.keys(propertyErrors).length) {
        surface.propertyEditor?.setErrors?.(propertyErrors);
    }
    if (surface.formError) {
        surface.formError.hidden = false;
        surface.formError.textContent = message || "Policy save failed.";
    }
}

function normalizePolicySubmission(values) {
    const next = { ...values };
    delete next.policy_code;
    return next;
}

async function deactivateClient(client) {
    const confirmed = await confirmAction(
        `Disable client ${client?.client_code || client?.name || ""}?`,
        "Disable client"
    );
    if (!confirmed) {
        return;
    }

    const { response, data } = await requestJson(`${API.clients}/${encodeURIComponent(getClientRouteKey(client))}`, {
        method: "DELETE",
    });

    if (!response.ok) {
        showToast(data?.message || "Unable to disable client.", { title: "Error", type: "error" });
        return;
    }

    await renderCurrentPage();
}

async function deactivateProject(project) {
    const confirmed = await confirmAction(
        `Deactivate project scope ${project?.project_code || project?.name || ""}?`,
        "Deactivate project"
    );
    if (!confirmed) {
        return;
    }

    const { response, data } = await requestJson(`${API.projects}/${encodeURIComponent(String(project?.project_code || "").trim())}`, {
        method: "DELETE",
    });

    if (!response.ok) {
        showToast(data?.message || "Unable to deactivate project.", { title: "Error", type: "error" });
        return;
    }

    await renderCurrentPage();
}

async function reactivateProject(project) {
    const confirmed = await confirmAction(
        `Reactivate project scope ${project?.project_code || project?.name || ""}?`,
        "Reactivate project"
    );
    if (!confirmed) {
        return;
    }

    const record = await fetchRecordJson(`${API.projects}/${encodeURIComponent(String(project?.project_code || "").trim())}`, "project");
    const nextProject = record?.project || project;
    const payload = {
        client_id: nextProject?.client_id || project?.client_id || "",
        name: nextProject?.name || "",
        status: "active",
        description: nextProject?.description || "",
        scope_notes: nextProject?.scope_notes || "",
        allowed_origins_text: Array.isArray(nextProject?.allowed_origins) ? nextProject.allowed_origins.join("\n") : "",
        origin_policy_mode: nextProject?.origin_policy_mode || "allowlist",
        policy_profile_code: nextProject?.policy_profile_code || "",
        capability_profile_code: nextProject?.capability_profile_code || "",
        room_policy_profile_code: nextProject?.room_policy_profile_code || "",
    };

    const { response, data } = await requestJson(`${API.projects}/${encodeURIComponent(String(project?.project_code || "").trim())}`, {
        method: "PATCH",
        headers: {
            "Content-Type": "application/json",
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        showToast(data?.message || "Unable to reactivate project.", { title: "Error", type: "error" });
        return;
    }

    await renderCurrentPage();
}

async function deprecatePolicy(policy) {
    const confirmed = await confirmAction(
        `Deprecate policy ${policy?.policy_code || policy?.name || ""}?`,
        "Deprecate policy"
    );
    if (!confirmed) {
        return;
    }

    const { response, data } = await requestJson(`${API.policies}/${encodeURIComponent(String(policy?.policy_code || "").trim())}`, {
        method: "DELETE",
    });

    if (!response.ok) {
        showToast(data?.message || "Unable to deprecate policy.", { title: "Error", type: "error" });
        return;
    }

    await renderCurrentPage();
}

async function duplicatePolicy(policy, clientContext = null) {
    const record = await fetchRecordJson(`${API.policies}/${encodeURIComponent(String(policy?.policy_code || "").trim())}`, "policy");
    const sourcePolicy = record?.policy || policy;
    const duplicateSeed = {
        ...sourcePolicy,
        policy_code: "",
        name: `${String(sourcePolicy?.name || "Policy").trim()} Copy`,
        status: "draft",
    };

    await openPolicyModal("create", null, clientContext, duplicateSeed);
}

async function logout() {
    if (state.logoutInFlight) {
        return;
    }

    if (typeof state.ui.confirmDialog !== "function") {
        const confirmed = window.confirm("Your current admin session will be closed on this browser.");
        if (!confirmed) {
            return;
        }
        state.logoutInFlight = true;
        try {
            const { response, data } = await requestJson(API.logout, {
                method: "POST",
                handleSessionExpiry: false,
            });
            if (!response.ok) {
                throw new Error(data?.message || "Unable to log out.");
            }
            if (data?.data?.csrf_token) {
                setCsrfToken(data.data.csrf_token);
            }
            await switchToStatusShell();
        } finally {
            state.logoutInFlight = false;
        }
        return;
    }

    await state.ui.confirmDialog("Your current admin session will be closed on this browser.", {
        title: "Logout",
        variant: "warning",
        confirmText: "Logout",
        confirmVariant: "danger",
        busyMessage: "Logging out...",
        errorText: "Unable to log out.",
        onConfirm: async () => {
            if (state.logoutInFlight) {
                return false;
            }

            state.logoutInFlight = true;

            try {
                const { response, data } = await requestJson(API.logout, {
                    method: "POST",
                    handleSessionExpiry: false,
                });

                if (!response.ok) {
                    throw new Error(data?.message || "Unable to log out.");
                }

                if (data?.data?.csrf_token) {
                    setCsrfToken(data.data.csrf_token);
                }

                await switchToStatusShell();
                return true;
            } finally {
                state.logoutInFlight = false;
            }
        },
    });
}

async function refreshCsrfToken() {
    const { response, data } = await requestJson(API.csrf, { handleSessionExpiry: false });
    if (response.ok && data?.data?.csrfToken) {
        setCsrfToken(data.data.csrfToken);
        return true;
    }

    return false;
}

async function handleSessionExpiry() {
    if (state.sessionPromptOpen) {
        return;
    }

    await openReauthModal();
}

async function activateAuthenticatedShell(targetPath, account = null) {
    const nextPath = normalizePath(targetPath || WEB.dashboard);
    state.authenticated = true;
    state.account = account ? normalizeAccount(account) : state.account;
    state.route = resolveRouteState(nextPath);
    state.lastServerTouchAt = Date.now();
    state.lastUserActivityAt = Date.now();
    state.lastKeepaliveAt = 0;
    state.sessionPromptOpen = false;
    state.loginModalOpen = false;

    renderShell();
    renderNavbar();
    await renderCurrentPage();
    startKeepaliveWatcher();
}

async function switchToStatusShell() {
    if (state.keepaliveTimer) {
        clearInterval(state.keepaliveTimer);
        state.keepaliveTimer = null;
    }

    state.authenticated = false;
    state.account = null;
    state.route = resolveRouteState(WEB.status);
    state.sessionPromptOpen = false;
    state.loginModalOpen = false;

    if (appEl) {
        appEl.dataset.page = WEB.status;
    }

    renderShell();
    renderNavbar();
    renderStatusPage();
}

async function confirmAction(message, title) {
    if (typeof state.ui.confirmDialog !== "function") {
        return window.confirm(message);
    }

    return state.ui.confirmDialog(message, {
        title,
        variant: "warning",
        confirmText: title || "Confirm",
    });
}

function startKeepaliveWatcher() {
    if (state.keepaliveTimer) {
        clearInterval(state.keepaliveTimer);
    }

    state.keepaliveTimer = window.setInterval(() => {
        void maybeKeepAlive();
    }, 5000);

    const bumpActivity = () => {
        state.lastUserActivityAt = Date.now();
    };

    ["pointerdown", "pointermove", "keydown", "scroll", "touchstart"].forEach((eventName) => {
        window.addEventListener(eventName, bumpActivity, { passive: true });
    });
    window.addEventListener("focus", bumpActivity);
    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) {
            bumpActivity();
            void maybeKeepAlive();
        }
    });
}

async function maybeKeepAlive() {
    if (!state.authenticated || !state.account || state.sessionPromptOpen) {
        return;
    }

    const now = Date.now();
    const lifetimeMs = Math.max(1, state.sessionLifetimeMinutes) * 60 * 1000;
    const remainingMs = lifetimeMs - (now - state.lastServerTouchAt);

    if (remainingMs > 0) {
        if (shouldAttemptSessionKeepalive(remainingMs) && wasRecentlyActive()) {
            await pingSessionKeepalive();
        }
        return;
    }

    if (wasRecentlyActive()) {
        const refreshed = await pingSessionKeepalive();
        if (refreshed) {
            return;
        }
    }

    await handleSessionExpiry();
}

function wasRecentlyActive() {
    const lastActivityAge = Date.now() - state.lastUserActivityAt;
    return lastActivityAge <= 30 * 1000;
}

function shouldAttemptSessionKeepalive(remainingMs) {
    if (!state.account || state.keepaliveInFlight) {
        return false;
    }

    if (document.visibilityState !== "visible" || !document.hasFocus()) {
        return false;
    }

    if (remainingMs > getKeepaliveWindowMs()) {
        return false;
    }

    const lastKeepaliveAt = Number(state.lastKeepaliveAt || 0);
    return !lastKeepaliveAt || ((Date.now() - lastKeepaliveAt) >= 15 * 1000);
}

function getKeepaliveWindowMs() {
    const thresholdMs = Math.max(15, Number(state.keepaliveThresholdSeconds) || 15) * 1000;
    return Math.max(15 * 1000, Math.min(120 * 1000, thresholdMs));
}

async function pingSessionKeepalive() {
    if (state.keepaliveInFlight) {
        return false;
    }

    state.keepaliveInFlight = true;
    state.lastKeepaliveAt = Date.now();

    try {
        const { response, data } = await requestJson(API.sessionPing, { handleSessionExpiry: false });
        if (!response.ok) {
            return false;
        }

        if (data?.data?.csrfToken) {
            setCsrfToken(data.data.csrfToken);
        }

        applySessionTimingPayload(data?.data || {});

        state.lastServerTouchAt = Date.now();
        return true;
    } finally {
        state.keepaliveInFlight = false;
    }
}
