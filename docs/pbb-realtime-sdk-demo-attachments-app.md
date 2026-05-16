# PBB Realtime SDK Attachment Demo App

Reference demo for:

- plain PHP backend SDK admission
- browser Realtime SDK transport
- Helper chat thread, composer, and upload queue

## Location

- `public/sdk-demo-attachments/index.php`
- `public/sdk-demo-attachments/admission.php`
- `public/sdk-demo-attachments/app.js`
- `public/sdk-demo-attachments/app.css`

## Runtime URL

- `/sdk-demo-attachments/`

## Purpose

Use this demo when a product team needs a minimal, readable example of:

1. issuing attachment-capable admission
2. validating draft files against attachment policy
3. chunking and publishing attachments through the demo transport event
4. reassembling attachments back into chat messages

## What It Shows

- room join and presence
- chat publish and subscribe
- Helper upload queue behavior
- `validateDraftAttachments(...)`
- `transferAttachmentInChunks(...)`
- `reduceAttachmentChunkStore(...)`
- `resolveAttachmentUrlFromStore(...)`

## Important Boundary

This demo uses `sandbox.attachment.chunk.publish` as the attachment transport event.

That is intentional for demo readability. It is not a statement that every production
project should use the same transport label or browser-owned chunk policy.
