<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PBB Realtime SDK Attachment Demo</title>
    <link rel="stylesheet" href="/sdk-demo-attachments/app.css">
</head>
<body>
    <main class="demo-shell">
        <section class="demo-header">
            <div>
                <p class="demo-eyebrow">SDK Demo</p>
                <h1 class="demo-title">PHP + JS attachment chat terminal</h1>
                <p class="demo-lede">
                    Reference app showing admission, room join, presence, chat publishing, chunked attachment transport,
                    and attachment reassembly using the Realtime SDK and Helper chat components.
                </p>
            </div>
            <div class="demo-header-actions">
                <a class="ui-button ui-button-ghost" href="/sdk-demo/">Chat demo</a>
                <a class="ui-button ui-button-ghost" href="/sdk-demo-conference/">Conference demo</a>
                <a class="ui-button ui-button-ghost" href="/admin/sdk">Back to SDK</a>
            </div>
        </section>

        <section class="demo-grid">
            <aside class="demo-panel demo-settings-panel">
                <div class="demo-panel-head">
                    <p class="demo-eyebrow">Settings</p>
                    <h2 class="demo-section-title">Attachment admission</h2>
                </div>

                <form id="demoSettingsForm" class="demo-form">
                    <label class="demo-field">
                        <span>Client code</span>
                        <input id="clientCodeInput" name="client_code" type="text" placeholder="clt_..." required>
                    </label>

                    <label class="demo-field">
                        <span>Project code</span>
                        <input id="projectCodeInput" name="project_code" type="text" placeholder="prj_..." required>
                    </label>

                    <label class="demo-field">
                        <span>Display name</span>
                        <input id="displayNameInput" name="display_name" type="text" value="Attachment Demo User" required>
                    </label>

                    <label class="demo-field">
                        <span>User identity</span>
                        <input id="userIdInput" name="user_id" type="text" value="">
                    </label>

                    <label class="demo-field">
                        <span>Room</span>
                        <input id="roomInput" name="room" type="text" value="sdk-demo-attachments-room" required>
                    </label>

                    <div class="demo-actions">
                        <button id="connectButton" class="ui-button" type="submit">Connect</button>
                        <button id="disconnectButton" class="ui-button ui-button-ghost" type="button" disabled>Disconnect</button>
                    </div>
                </form>

                <div class="demo-status-list">
                    <div class="demo-status-row">
                        <span>Connection</span>
                        <strong id="connectionStatus">Idle</strong>
                    </div>
                    <div class="demo-status-row">
                        <span>Room</span>
                        <strong id="effectiveRoom">chat.thread.sdk-demo-attachments-room</strong>
                    </div>
                    <div class="demo-status-row">
                        <span>Presence</span>
                        <strong id="presenceStatus">Offline</strong>
                    </div>
                </div>

                <div class="demo-policy-card">
                    <div class="demo-policy-row"><span>Attachment count</span><strong id="attachmentCountLimit">-</strong></div>
                    <div class="demo-policy-row"><span>Per-file bytes</span><strong id="attachmentBytesLimit">-</strong></div>
                    <div class="demo-policy-row"><span>Total bytes</span><strong id="attachmentTotalBytesLimit">-</strong></div>
                </div>
            </aside>

            <section class="demo-panel demo-chat-panel">
                <div class="demo-panel-head">
                    <p class="demo-eyebrow">Chat</p>
                    <h2 class="demo-section-title">Attachment thread</h2>
                </div>
                <div id="chatThreadHost" class="demo-thread-host"></div>
                <div id="uploadQueueHost" class="demo-upload-queue-host"></div>
                <div id="chatComposerHost" class="demo-composer-host"></div>
            </section>

            <aside class="demo-panel demo-side-panel">
                <section class="demo-subpanel">
                    <div class="demo-panel-head">
                        <p class="demo-eyebrow">Presence</p>
                        <h2 class="demo-section-title">Room roster</h2>
                    </div>
                    <div id="presenceHost" class="demo-presence-host"></div>
                </section>

                <section class="demo-subpanel">
                    <div class="demo-panel-head">
                        <p class="demo-eyebrow">Transport</p>
                        <h2 class="demo-section-title">Event log</h2>
                    </div>
                    <div id="eventLogHost" class="demo-log-host"></div>
                </section>
            </aside>
        </section>
    </main>

    <script type="module" src="/sdk-demo-attachments/app.js"></script>
</body>
</html>
