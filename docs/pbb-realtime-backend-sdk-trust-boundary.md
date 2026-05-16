# PBB Realtime Backend SDK Trust Boundary

## Rule

The backend SDK is the trust and admission layer.

The frontend SDK is the transport and runtime layer.

Realtime validates and enforces the signed claims at runtime.

## Separation Of Responsibilities

### Product Frontend

Should:

- call its own product backend for Realtime admission
- receive `token` and `websocket_url`
- pass those into the frontend SDK

Should not:

- mint Realtime tokens
- hold the signing secret
- decide project scope trust on its own

### Product Backend

Should:

- authenticate the user
- resolve the correct Realtime client code
- resolve the correct Realtime project scope code
- decide which room and capabilities are allowed
- use the backend SDK to build and sign the admission payload

Should not:

- expose signing secrets to the browser
- let clients self-assign project scopes

### Realtime

Should:

- validate token signature
- validate issuer
- validate audience
- validate expiry
- enforce capabilities, room access, and attachment policy from claims

## Practical Flow

1. frontend authenticates against its own product system
2. frontend calls product backend admission endpoint
3. backend uses backend SDK to build admission payload
4. frontend creates `RealtimeSocketClient` using returned token
5. Realtime validates and enforces the token

## Why This Matters

If every product team hand-builds claims differently, trust becomes inconsistent.

The backend SDK exists to make:

- claim shape consistent
- signing consistent
- admission payloads predictable
- frontend integrations simpler
