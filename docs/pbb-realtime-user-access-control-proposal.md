# PBB Realtime User Access Control Proposal

## Goal

Introduce user roles and client-scoped access control so Realtime operators only see and manage records for the clients they are assigned to, while admins retain global visibility and management authority.

## Current Problem

The current admin surface is too flat:

- any authenticated operator can see all clients
- all policies are visible
- all project scopes are visible
- all sandbox and presence context options are visible
- there is no user-management surface

That is acceptable for internal bootstrap work, but it is not acceptable once multiple teams or business units are using Realtime as shared infrastructure.

## Recommended Model

### User Types

Replace the current binary `is_operator` concept with an explicit user type:

- `admin`
- `regular`

This should become the primary authorization axis for the admin surface.

### Client Assignment

Do not model access as only "records owned by a user."

Model it as:

- users are assigned to one or more clients

That is the right boundary because:

- clients are already the top-level ownership object
- policies belong to clients
- project scopes belong to clients
- future teams may need multiple regular users managing the same client

Recommended table:

- `realtime_client_user`

Suggested columns:

- `id`
- `client_id`
- `user_id`
- `assignment_role` (optional future-proofing)
- `created_at`
- `updated_at`

## Access Rules

### Admin Users

Admins can:

- view all clients
- view all policies
- view all project scopes
- view all sessions
- view all audit records
- view all telemetry
- manage users
- assign users to clients

### Regular Users

Regular users can:

- view only assigned clients
- view only policies under assigned clients
- view only project scopes under assigned clients
- manage only those client-owned records
- use sandbox and presence inspector only within assigned client scopes

Regular users should not:

- see records from unassigned clients
- manage users
- see global cross-client administration views

## Inheritance Rule

Everything should inherit from client scope.

That means:

- if a user can access a client
- they can access that client's policies
- and that client's project scopes

This is simpler and safer than attaching separate access rules to every record type.

## UI Implications

### Admin UI

Admins should keep the full top-level navigation:

- Dashboard
- Clients
- SDK
- Sandbox
- Presence
- Sessions
- Audit
- Operations
- Telemetry
- Users

### Regular User UI

Regular users should see only what they can actually use.

Likely:

- Dashboard
- Clients
- SDK
- Sandbox
- Presence

Maybe:

- Sessions
- Audit

only if those pages are filtered to assigned clients and still useful operationally

Regular users should not see:

- global telemetry
- global operations
- user management

## Data Access Enforcement

The most important rule:

- enforce access in backend queries first

Do not rely only on hiding navigation.

The following surfaces need server-side filtering:

- `BrowserDataController`
- client CRUD
- policy CRUD
- project CRUD
- sandbox context
- presence inspector context
- sessions list
- audit list
- operations and telemetry endpoints

## Authentication Migration

Current state:

- `users.is_operator`

Recommended next state:

- `users.user_type`
  - `admin`
  - `regular`

Migration approach:

1. add `user_type`
2. backfill existing `is_operator = true` users as `admin` initially
3. keep `is_operator` temporarily for compatibility
4. migrate middleware and UI to `user_type`
5. remove `is_operator` later

## User Management Surface

Add a new admin-only `Users` page.

Admins should be able to:

- list users
- create users
- edit users
- set user type
- assign clients
- remove client assignments
- disable users if needed

That page should not be visible to regular users.

## Recommended Query Model

Add a helper or scope for:

- "visible clients for current user"

Everything else should derive from that.

Examples:

- visible policies = policies where `client_id` is in visible clients
- visible projects = projects where `client_id` is in visible clients

This keeps access logic centralized instead of scattering ad hoc conditions across controllers.

## Why This Is The Right Direction

It gives you:

- client-scoped isolation
- less accidental data leakage
- clearer accountability
- a clean admin vs regular distinction
- a scalable model for multiple operators per client

Most importantly, it aligns the access model with the existing client-owned architecture you already moved toward.
