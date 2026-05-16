# PBB Realtime Installer Clean Windows Host Checklist

## Host Preparation

- [ ] Windows host is reachable
- [ ] PHP runtime is installed and callable
- [ ] required PHP extensions are enabled
- [ ] MySQL or MariaDB is reachable
- [ ] target webroot exists and is writable

## Networking

- [ ] public host name is assigned
- [ ] TLS plan is ready
- [ ] `/realtime` websocket path plan is ready
- [ ] firewall rules are understood

## Secrets And Identity

- [ ] Realtime token signing secret is prepared
- [ ] trusted issuers are prepared
- [ ] initial admin credentials are prepared

## Installer

- [ ] installer ZIP extracted
- [ ] `/installer/` opens in browser
- [ ] preflight passes
- [ ] install completes
- [ ] validation completes

## Go-Live

- [ ] admin login succeeds
- [ ] `/api/health` works
- [ ] `/api/ready` works
- [ ] `/admin/sandbox` can connect

