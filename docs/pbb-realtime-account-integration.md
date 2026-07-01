# PBB Realtime Account Integration

Realtime supports PBB Account SSO and Account app-admin role/status management.

## Confirmed Values

- Account client id: `pbb-realtime`
- Realtime base URL: `https://realtime.pbb.ph`
- Account callback URL: `https://realtime.pbb.ph/auth/account/callback`
- Account post-logout URL: `https://realtime.pbb.ph`
- Account scopes: `openid profile`

## Local User Model

Realtime links Account identities through `users.pbb_user_id`, stored as a nullable unique string.

Account-managed Realtime roles:

- `admin`
- `regular`

Realtime status vocabulary:

- `active`
- `disabled`

`is_operator` remains a Realtime-local admin-surface access capability. It is not an Account role. Account provisioning and role updates keep `is_operator=true` so the provisioned user can reach the Realtime admin surface, while `user_type` controls admin versus regular operator permissions.

## Account SSO

Browser SSO routes:

- `GET /auth/account/redirect`
- `GET /auth/account/callback`
- `GET /auth/logout`

SSO runtime config is read from encrypted app-local DB settings in `realtime_runtime_settings`, not from generic `.env` keys during request handling:

- `account_sso_enabled`
- `account_sso_base_url`
- `account_sso_client_id`
- `account_sso_client_secret`
- `account_sso_redirect_uri`
- `account_sso_post_logout_redirect_uri`
- `account_sso_scopes`
- `account_sso_timeout_seconds`
- `account_sso_ca_bundle`

Operators can view/update these values from the admin navbar Realtime settings modal. `account_sso_client_secret` is write-only in the UI: the modal only reports whether it is configured, and leaving the field blank preserves the stored secret.

The public admin bootstrap response exposes a sanitized `settings.accountSso` block so the browser shell can choose the correct login UX. When `account_sso_enabled=true`, the status-page and navbar Sign In/Login actions redirect to `/auth/account/redirect` and preserve the current `/admin...` return path. When SSO is disabled, Realtime keeps the local operator login modal as the fallback.

Packaged default is disabled. Kit or the operator must configure Account and Realtime together.

## App-Admin API

Service-only endpoints:

- `GET /api/account-admin/meta`
- `GET /api/account-admin/users/{pbb_user_id}`
- `PUT /api/account-admin/users/{pbb_user_id}`
- `DELETE /api/account-admin/users/{pbb_user_id}`
- `PATCH /api/account-admin/users/{pbb_user_id}/role`
- `PATCH /api/account-admin/users/{pbb_user_id}/status`

The remove-access endpoint is idempotent. Realtime preserves the local user and audit/history records, unlinks `users.pbb_user_id`, sets `status=disabled`, clears `is_operator`, rotates `remember_token`, and writes an `account_admin_access_removed` audit event. `/api/account-admin/meta` advertises this with `capabilities.removeUser=true`.

Runtime app-admin auth uses encrypted DB-backed settings only. It must not fall back to generic `.env` keys during request handling.

Settings are stored in `realtime_runtime_settings`:

- `account_admin_api_enabled`
- `account_admin_api_token`
- `account_admin_api_client`

Operators can view/update these values from the admin navbar Realtime settings modal. `account_admin_api_token` is write-only in the UI: the modal only reports whether it is configured, and entering a new value rotates the stored service token.

Packaged/default state should be:

- `account_admin_api_enabled=false`
- `account_admin_api_token` empty
- `account_admin_api_client=pbb-account`

Kit/Data Prep may seed these DB settings with a dedicated app-admin token, and Account must store the same token for trusted client `pbb-realtime`.
