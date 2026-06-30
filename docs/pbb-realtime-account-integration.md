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

SSO config is read from app config/env:

- `PBB_ACCOUNT_SSO_ENABLED`
- `PBB_ACCOUNT_BASE_URL`
- `PBB_ACCOUNT_CLIENT_ID`
- `PBB_ACCOUNT_CLIENT_SECRET`
- `PBB_ACCOUNT_REDIRECT_URI`
- `PBB_ACCOUNT_POST_LOGOUT_REDIRECT_URI`
- `PBB_ACCOUNT_SCOPES`
- `PBB_ACCOUNT_CA_BUNDLE`

Packaged default is disabled. Kit or the operator must configure Account and Realtime together.

## App-Admin API

Service-only endpoints:

- `GET /api/account-admin/meta`
- `GET /api/account-admin/users/{pbb_user_id}`
- `PUT /api/account-admin/users/{pbb_user_id}`
- `PATCH /api/account-admin/users/{pbb_user_id}/role`
- `PATCH /api/account-admin/users/{pbb_user_id}/status`

Runtime app-admin auth uses encrypted DB-backed settings only. It must not fall back to generic `.env` keys during request handling.

Settings are stored in `realtime_runtime_settings`:

- `account_admin_api_enabled`
- `account_admin_api_token`
- `account_admin_api_client`

Packaged/default state should be:

- `account_admin_api_enabled=false`
- `account_admin_api_token` empty
- `account_admin_api_client=pbb-account`

Kit/Data Prep may seed these DB settings with a dedicated app-admin token, and Account must store the same token for trusted client `pbb-realtime`.
