# PBB Realtime Client And Project Scope Implementation Checklist

## Phase 1 - Schema And Model Split

- [x] Add `realtime_projects` table
- [x] Add `RealtimeProject` model
- [x] Add `RealtimeClient::projects()` relation
- [x] Make client project linkage explicit and auditable
- [x] Seed at least one sample project scope for the HQ client

## Phase 2 - API And Browser Data

- [x] Expose project scopes in admin browser-data payloads
- [x] Add admin API endpoints for project scope view/create/update/deactivate
- [x] Add project counts to dashboard and client summaries
- [x] Keep client trust metadata available without requiring a project scope

## Phase 3 - Admin Shell UI

- [x] Show project scopes on the client detail page
- [x] Add a project-scope modal for create/edit
- [x] Add a project-scope detail page
- [x] Remove project-specific clutter from the client profile view
- [x] Minimize the onboarding forms so generated codes and advanced routing fields are not operator-entered

## Phase 4 - Transition Cleanup

- [x] Keep the existing client workflow compatible during the rollout
- [x] Gradually move project-specific fields out of the client workflow
- [x] Update tests and fixtures for the split model
- [x] Update the admin docs to reflect the new trust-anchor / project-scope boundary
