# PBB Realtime SDK Demo App

Small reference app that uses:

- the plain PHP backend SDK for admission
- the browser Realtime SDK for transport
- the Helper UI library for chat, empty states, and toasts

## Location

- `public/sdk-demo/index.php`
- `public/sdk-demo/admission.php`
- `public/sdk-demo/app.js`
- `public/sdk-demo/app.css`

## Purpose

This demo is intentionally smaller than the Sandbox.

Use it when another team needs a minimal integration example that shows:

1. how a PHP backend issues admission
2. how a browser client connects with `RealtimeSocketClient`
3. how to join a room
4. how to publish presence
5. how to publish and receive chat messages

## What It Does

- accepts client code, project code, display name, user identity, and room
- calls a local PHP admission endpoint
- opens a Realtime websocket
- joins a `chat.thread.*` room
- subscribes to room presence
- publishes `online` presence
- sends and receives chat messages

## What It Does Not Do

- no policy discovery
- no attachment transport
- no call/conference controls
- no project-specific business logic
- no production auth layer

## Runtime URL

When served from this repo:

- `/sdk-demo/`

## Important Boundary

The demo admission endpoint is a reference shape only.

Real product integrations should not trust browser-submitted client/project values blindly. A real product backend should already know:

- which Realtime client code applies
- which project scope applies
- which user identity is authenticated
- which room the user is allowed to join

The demo keeps those inputs manual so the transport flow remains visible.
