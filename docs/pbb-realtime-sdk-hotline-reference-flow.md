# PBB Realtime SDK Hotline Reference Flow

## Goal

Reference integration for a Hotline-style citizen/operator surface where chat and call behavior run together.

## Actors

- citizen surface
- operator surface
- product backend
- Realtime service

## Flow

1. Product backend decides the room and call context.
2. Product backend issues session admission for each participant.
3. Each surface connects through `RealtimeSocketClient`.
4. Each surface joins:
   - chat room
   - optional call room
5. Presence is published for roster visibility.
6. Chat behavior runs continuously in the chat room.
7. Call signaling runs in the call room:
   - ring
   - offer
   - answer
   - ICE candidate
   - hangup
8. Local media stays device-local until WebRTC peer connections are formed.
9. Remote media tiles are rendered from per-remote stream maps.

## Hotline UI Split

Recommended UI split for product teams:

- left rail: business context and participant info
- main: chat thread and compose
- side rail: media display and participant roster

## What the SDK Should Cover

- connect/reconnect/disconnect
- room join helpers
- presence roster reducers
- chat normalization
- attachment chunking and reassembly helpers
- call signaling payloads
- mesh peer-connection helpers

## What Hotline App Should Still Cover

- citizen lookup
- operator candidate selection
- ringing policy
- timeout policy
- escalation rules
- incident/case lifecycle
- recording rules if any
