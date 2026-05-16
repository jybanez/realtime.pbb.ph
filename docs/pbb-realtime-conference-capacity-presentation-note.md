# PBB Realtime Conference Capacity Presentation Note

## Purpose

Provide a defensible presentation reference for discussing conference-call capacity on the current small server profile:

- Intel Core i5-6500 @ 3.20 GHz
- 16 GB RAM

This note separates:

- server-side signaling capacity
- practical end-to-end conference confidence

Those are not the same thing.

## Key Clarification

For the current `PBB Realtime` architecture:

- Realtime handles signaling, room coordination, presence, and transport control.
- Audio/video media is peer-to-peer WebRTC.
- That means the current model is effectively `mesh`.

So:

- `pure signaling only` = `mesh`
- `with SFU` = server-assisted media forwarding

## Main Message

On the current small server:

- signaling-only conferencing is not expected to be the first bottleneck
- endpoint devices, browser load, and local network quality become the first bottlenecks in mesh

If an SFU is added on the same machine:

- the server becomes part of the media path
- conference capacity drops sharply

## What Must Not Be Claimed

Do not present signaling-server estimates as whole-system conference capacity.

Example of what not to say:

- “This machine can handle 100 simultaneous 5-party conferences end to end.”

That statement would be too broad for mesh, because:

- media is not on the server
- endpoint upload and decode load will degrade earlier than signaling on the server

## Safe Position To Present

Use this framing:

> On the current `i5-6500 / 16 GB` machine, `PBB Realtime` can likely coordinate many simultaneous conferences when it is used as a signaling-only system. However, for mesh-based audio/video conferencing, the first real bottleneck is not the signaling server. It is the participant endpoints and the local network. If we later place an SFU on the same machine, then server capacity becomes the bottleneck and the number of simultaneous conferences drops substantially.

## Architecture Comparison

### Current model: signaling-only / mesh

Server responsibilities:

- websocket signaling
- room join/leave
- presence
- call signaling
- telemetry / audit

Server does not carry:

- audio packets
- video packets

Implication:

- server-side capacity is relatively high
- endpoint/device/network confidence is the real constraint

### Future model: same server with SFU

Server responsibilities:

- everything above
- media relay/forwarding for all participants

Implication:

- server-side media throughput and CPU become the real constraint
- simultaneous conference count drops sharply

## Practical Recommendation

For this machine:

- current mesh model is acceptable for small-group conferencing pilots
- do not promise high production scale for 5-party video without direct testing
- if expected usage grows beyond small-group calls, plan for SFU on a stronger box or separate media infrastructure

## Suggested Executive Summary Slide

### Capacity Position

- Current Realtime design is `signaling-only` for calls.
- In that design, the server is not the first bottleneck.
- Mesh call quality degrades first at the endpoint/network layer.
- If an SFU is added on the same machine, server capacity drops sharply.

### Recommendation

- Use current design for small-group LAN/Wi‑Fi conferencing.
- Treat 5-party mesh calls as pilot-grade until field-tested.
- Plan SFU only if larger or more predictable conferencing scale is required.
