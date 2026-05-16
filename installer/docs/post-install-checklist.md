# PBB Realtime Installer Post-Install Checklist

- confirm admin login works
- confirm `/api/health` responds
- confirm `/api/ready` responds
- confirm the Ratchet runtime is registered and startable
- confirm the generated service artifact was applied through the host service manager
- confirm `/realtime` websocket path is exposed correctly
- confirm `/admin/sandbox` can:
  - issue admission
  - connect websocket
  - join room
  - publish presence
  - publish chat
- lock or remove the installer after validation

