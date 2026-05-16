# PBB Realtime User Access Control Implementation Checklist

## Phase 1 - Data Model

- [ ] Add `user_type` to `users`
- [ ] Backfill existing operator users to `admin`
- [ ] Create `realtime_client_user` assignment table
- [ ] Add model relationships:
  - user -> clients
  - client -> users

## Phase 2 - Auth And Middleware

- [ ] Replace `is_operator`-only middleware assumptions with user-type-aware checks
- [ ] Keep admin surface restricted to authenticated Realtime users
- [ ] Add admin-only authorization guard for user management
- [ ] Add helper for "is admin" checks

## Phase 3 - Query Scoping

- [ ] Add a reusable helper/scope for visible clients
- [ ] Filter client index/detail by assignment for regular users
- [ ] Filter policy index/detail by client assignment
- [ ] Filter project index/detail by client assignment
- [ ] Filter sandbox context by client assignment
- [ ] Filter presence inspector context by client assignment
- [ ] Filter sessions/audit views appropriately

## Phase 4 - Navigation And UI

- [ ] Add `Users` page to navigation for admins only
- [ ] Remove admin-only pages from regular-user navigation
- [ ] Ensure client selectors only show assigned clients for regular users
- [ ] Ensure policy/project actions are hidden when access is not allowed

## Phase 5 - User Management

- [ ] Add users index page
- [ ] Add user create/edit modal
- [ ] Add client assignment UI
- [ ] Add user-type editing
- [ ] Restrict all user-management actions to admins

## Phase 6 - API And Controllers

- [ ] Add browser data endpoints for users and assignments
- [ ] Enforce authorization in:
  - BrowserDataController
  - ClientController
  - PolicyController
  - ProjectController
  - SandboxController
  - CurrentUserController where needed

## Phase 7 - Seeders And Tests

- [ ] Update seeders with:
  - at least one admin
  - at least one regular user
  - client assignments
- [ ] Add tests for admin global visibility
- [ ] Add tests for regular-user restricted visibility
- [ ] Add tests for regular-user create/update restrictions across unassigned clients
- [ ] Add tests for sandbox context filtering

## Phase 8 - Cleanup

- [ ] Deprecate `is_operator` after user-type migration is complete
- [ ] Remove compatibility branches using `is_operator`
- [ ] Update docs to describe admin vs regular roles
