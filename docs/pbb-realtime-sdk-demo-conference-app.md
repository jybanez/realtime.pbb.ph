# PBB Realtime SDK Conference Demo App

Reference demo for:

- plain PHP backend SDK admission
- browser Realtime SDK signaling
- Helper empty states and toast UI

## Location

- `public/sdk-demo-conference/index.php`
- `public/sdk-demo-conference/admission.php`
- `public/sdk-demo-conference/app.js`
- `public/sdk-demo-conference/app.css`

## Runtime URL

- `/sdk-demo-conference/`

## Purpose

Use this demo when a product team needs a focused example of:

1. conference-capable admission
2. room presence as the participant source
3. targeted ring, offer, answer, and ICE signaling
4. small-group mesh media using one peer connection per remote participant

## What It Shows

- chat room join plus call room join
- presence roster
- targeted `call.signal.publish`
- mesh offer ownership
- local media and remote media stack rendering
- warning at 4+ participants via `getMeshConferenceWarning(...)`

## Important Boundary

This is still a browser-to-browser mesh conference demo.

It is appropriate as a reference surface for the current SDK, but it is not an SFU
or relay-based production media topology.
