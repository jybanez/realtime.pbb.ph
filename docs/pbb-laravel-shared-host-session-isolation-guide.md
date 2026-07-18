# PBB Laravel Shared-Host Session Isolation Guide

## Problem

Several PBB Laravel apps run on the same WAMP/Apache/PHP host and use common Laravel environment keys such as `SESSION_COOKIE`, `SESSION_DOMAIN`, `SESSION_LIFETIME`, and `APP_NAME`.

When multiple apps share the same PHP/Apache runtime, those generic keys can become unsafe if any app or vhost injects session settings that another app can see. The visible failure is usually a login or re-auth problem, even when the account and password are correct.

The Realtime incident that exposed this pattern was:

- `admin@pbb.ph` existed and the password was valid.
- The user was an authorized operator.
- `GET https://realtime.pbb.ph/api/admin/bootstrap` returned a Realtime CSRF token and `pbb_realtime_session`.
- `POST https://realtime.pbb.ph/api/admin/login` failed with `419 CSRF token mismatch`.
- The failed login response set `pbb_maestro_session` with `domain=maestro.pbb.ph`.

That means the browser had a Realtime CSRF/session pair, but the login request was evaluated with Maestro session cookie/domain settings. The credentials were not the root cause.

## Symptoms

Look for any of these signs:

- Login fails with `419 CSRF token mismatch`.
- Login appears to reload or return unauthenticated even with correct credentials.
- The response from one PBB app sets another app's session cookie.
- `Set-Cookie` includes the wrong cookie name, for example `pbb_maestro_session` on `realtime.pbb.ph`.
- `Set-Cookie` includes the wrong domain, for example `domain=maestro.pbb.ph` on a different host.
- Session lifetime in an API bootstrap response does not match that app's `.env`.

## Quick Diagnosis

Run a bootstrap request and inspect the cookies:

```powershell
curl.exe -k -i https://realtime.pbb.ph/api/admin/bootstrap -H "Accept: application/json"
```

Then run the login request with the CSRF token from the bootstrap response and inspect `Set-Cookie`:

```powershell
$tmp = New-TemporaryFile
$base = "https://realtime.pbb.ph"
try {
    $body = curl.exe -k -s -c $tmp.FullName -b $tmp.FullName "$base/api/admin/bootstrap"
    $token = ($body | ConvertFrom-Json).security.csrfToken

    curl.exe -k -i -c $tmp.FullName -b $tmp.FullName `
        -H "Accept: application/json" `
        -H "Content-Type: application/json" `
        -H "X-Requested-With: XMLHttpRequest" `
        -H "X-CSRF-TOKEN: $token" `
        -X POST "$base/api/admin/login" `
        --data '{"email":"admin@pbb.ph","password":"password"}'
} finally {
    Remove-Item $tmp.FullName -ErrorAction SilentlyContinue
}
```

If the login response sets another app's session cookie or domain, fix session isolation before debugging credentials.

## Recommended Fix

Each PBB Laravel app should use app-specific session env keys and should not derive cookie name/domain from shared generic keys.

Example for Realtime in `config/session.php`:

```php
'driver' => env('REALTIME_SESSION_DRIVER', env('SESSION_DRIVER', 'file')),

'lifetime' => env('REALTIME_SESSION_LIFETIME', env('SESSION_LIFETIME', 120)),

'cookie' => env('REALTIME_SESSION_COOKIE', 'pbb_realtime_session'),

'domain' => env('REALTIME_SESSION_DOMAIN'),

'secure' => env('REALTIME_SESSION_SECURE_COOKIE', env('SESSION_SECURE_COOKIE')),
```

Example `.env`:

```dotenv
SESSION_DRIVER=file
SESSION_LIFETIME=15

REALTIME_SESSION_DRIVER=file
REALTIME_SESSION_LIFETIME=15
REALTIME_SESSION_COOKIE=pbb_realtime_session
REALTIME_SESSION_DOMAIN=
```

For other PBB apps, use that app's own prefix and cookie name:

```dotenv
MAESTRO_SESSION_DRIVER=file
MAESTRO_SESSION_LIFETIME=15
MAESTRO_SESSION_COOKIE=pbb_maestro_session
MAESTRO_SESSION_DOMAIN=

HOTLINE_SESSION_DRIVER=file
HOTLINE_SESSION_LIFETIME=15
HOTLINE_SESSION_COOKIE=pbb_hotline_session
HOTLINE_SESSION_DOMAIN=
```

For local CLI and standalone development, the matching `config/session.php` may still read prefixed keys as fallbacks.

## Apache Vhost Guidance

When Kit Setup owns the Apache vhost, use the standard Laravel `SetEnv` pins inside that app's `<VirtualHost>` block. The generic names are safe in this shape because Kit renders them per vhost, not as shared global process values:

```apache
SetEnv SESSION_DRIVER "file"
SetEnv SESSION_LIFETIME "15"
SetEnv SESSION_COOKIE "pbb_realtime_session"
SetEnv SESSION_DOMAIN ""
```

Avoid setting generic `SESSION_COOKIE` or `SESSION_DOMAIN` globally in a shared Apache/PHP environment. They should appear only in the app-specific vhost block. Realtime's runtime config now reads Kit-standard vhost pins first and keeps `REALTIME_SESSION_*` keys as local/CLI fallbacks.

## After Applying

Clear Laravel config cache:

```powershell
C:/wamp64/bin/php/php8.2.29/php.exe artisan config:clear
```

Then re-run the login curl check. A healthy app should:

- Return `200 OK` for valid login.
- Set only that app's session cookie.
- Not set another PBB app's cookie or domain.

For Realtime, the verified healthy result was `pbb_realtime_session` with no `maestro.pbb.ph` domain leak.

## Why This Should Be Standardized

PBB apps share the same host and operational pattern, so app-local namespacing should be the default for session config. It prevents one Laravel app's login/session behavior from depending on another app's environment, vhost, or cached runtime state.
