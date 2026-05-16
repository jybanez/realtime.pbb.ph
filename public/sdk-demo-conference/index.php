<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PBB Realtime SDK Conference Demo</title>
    <link rel="stylesheet" href="/sdk-demo-conference/app.css">
</head>
<body>
    <main class="demo-shell demo-shell-conference">
        <section class="demo-header">
            <div>
                <p class="demo-eyebrow">SDK Demo</p>
                <h1 class="demo-title">PHP + JS conference terminal</h1>
                <p class="demo-lede">
                    Reference app showing admission, presence, targeted call signaling, local media, and small-group mesh
                    conference behavior using the Realtime SDK and Helper UI components.
                </p>
            </div>
            <div class="demo-header-actions">
                <a class="ui-button ui-button-ghost" href="/sdk-demo/">Chat demo</a>
                <a class="ui-button ui-button-ghost" href="/sdk-demo-attachments/">Attachment demo</a>
                <a class="ui-button ui-button-ghost" href="/admin/sdk">Back to SDK</a>
            </div>
        </section>

        <section class="conference-grid">
            <aside class="demo-panel demo-settings-panel">
                <div class="demo-panel-head">
                    <p class="demo-eyebrow">Settings</p>
                    <h2 class="demo-section-title">Conference admission</h2>
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
                        <input id="displayNameInput" name="display_name" type="text" value="Conference Demo User" required>
                    </label>

                    <label class="demo-field">
                        <span>User identity</span>
                        <input id="userIdInput" name="user_id" type="text" value="">
                    </label>

                    <label class="demo-field">
                        <span>Room</span>
                        <input id="roomInput" name="room" type="text" value="sdk-demo-conference-room" required>
                    </label>

                    <div class="demo-actions">
                        <button id="connectButton" class="ui-button" type="submit">Connect</button>
                        <button id="disconnectButton" class="ui-button ui-button-ghost" type="button" disabled>Disconnect</button>
                    </div>
                </form>

                <div class="demo-actions demo-call-actions">
                    <button id="audioCallButton" class="ui-button ui-button-ghost" type="button">Audio call</button>
                    <button id="videoCallButton" class="ui-button ui-button-ghost" type="button">Video call</button>
                    <button id="answerCallButton" class="ui-button ui-button-ghost" type="button">Answer</button>
                    <button id="endCallButton" class="ui-button ui-button-ghost" type="button">End</button>
                    <button id="toggleMicButton" class="ui-button ui-button-ghost" type="button">Mute mic</button>
                    <button id="toggleCameraButton" class="ui-button ui-button-ghost" type="button">Camera on</button>
                </div>

                <div class="demo-status-list">
                    <div class="demo-status-row">
                        <span>Connection</span>
                        <strong id="connectionStatus">Idle</strong>
                    </div>
                    <div class="demo-status-row">
                        <span>Presence</span>
                        <strong id="presenceStatus">Offline</strong>
                    </div>
                    <div class="demo-status-row">
                        <span>Call</span>
                        <strong id="callStatus">Idle</strong>
                    </div>
                    <div class="demo-status-row">
                        <span>Chat room</span>
                        <strong id="effectiveRoom">chat.thread.sdk-demo-conference-room</strong>
                    </div>
                    <div class="demo-status-row">
                        <span>Call room</span>
                        <strong id="callRoom">call.session.sdk-demo-conference-room</strong>
                    </div>
                </div>

                <div id="conferenceWarningHost" class="demo-warning-host"></div>
            </aside>

            <section class="demo-panel demo-media-panel">
                <div class="demo-panel-head">
                    <p class="demo-eyebrow">Media</p>
                    <h2 class="demo-section-title">Conference surfaces</h2>
                </div>
                <section class="demo-subpanel">
                    <div class="demo-panel-head">
                        <p class="demo-eyebrow">Local</p>
                        <h2 class="demo-section-title">Local media</h2>
                    </div>
                    <div id="localMediaHost" class="conference-media-host"></div>
                </section>
                <section class="demo-subpanel">
                    <div class="demo-panel-head">
                        <p class="demo-eyebrow">Remote</p>
                        <h2 class="demo-section-title">Remote media stack</h2>
                    </div>
                    <div id="remoteMediaHost" class="conference-media-stack"></div>
                </section>
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

    <script type="module" src="/sdk-demo-conference/app.js"></script>
</body>
</html>
