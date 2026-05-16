# PBB Realtime SDK Compatibility And Versioning Strategy

## Versioning Goal

Make SDK adoption safe for product teams while Realtime evolves transport behavior.

## Contract Rules

- breaking API changes require a major SDK version
- additive helpers or new optional fields can ship in a minor version
- bug fixes and behavior corrections ship in patch versions

## Compatibility Principles

- request envelope namespace must remain stable unless a protocol break is intentional
- event payloads should be additive where possible
- feature helpers should tolerate unknown fields from the server
- product teams should not depend on sandbox-only DOM structures

## Recommended Version Surface

- SDK version
- protocol namespace version
- minimum server compatibility note

Example:

- SDK: `0.1.x`
- protocol: `pbb.realtime.v1`
- server compatibility: `Realtime >= 2026.04`

## Deprecation Rule

- mark helper APIs as deprecated before removal
- keep one migration window between deprecation and removal
- document replacements in the changelog and integration guide

## Adoption Guidance

- sandbox remains the first integration reference
- product teams should pin SDK versions per release train
- protocol-breaking changes should be avoided unless the server boundary truly changes
