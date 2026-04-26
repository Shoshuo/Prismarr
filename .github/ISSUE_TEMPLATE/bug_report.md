---
name: Bug report
about: Something is broken or misbehaving in Prismarr
title: "[Bug] "
labels: bug
assignees: ''
---

<!--
⚠️  Before opening a bug, please:
  1. Check the existing issues for a duplicate.
  2. Confirm you are running the latest `:latest` tag - a fix might already
     be published.
  3. Reproduce with `APP_ENV=prod`. A bug specific to `APP_ENV=dev` is
     likely user misconfiguration.

⚠️  SECURITY ISSUES - do NOT report here. Email shoshuo3@gmail.com.
     See SECURITY.md for the full policy.
-->

## What happened

<!-- A clear description of the bug. -->

## What you expected to happen

<!-- What should have happened instead. -->

## Steps to reproduce

1.
2.
3.

## Environment

- **Prismarr version** (tag or commit SHA):
- **Docker image digest** (`docker inspect prismarr | grep Image`):
- **Host OS & arch** (`uname -a`):
- **Docker version** (`docker version`):
- **Reverse proxy** (Traefik / nginx / Caddy / none):

## Configured services

<!-- Tick the ones you have configured via the setup wizard. -->

- [ ] TMDb
- [ ] Radarr
- [ ] Sonarr
- [ ] Prowlarr
- [ ] Jellyseerr
- [ ] qBittorrent
- [ ] Gluetun

## Logs

<!--
Redact any API keys, passwords, URLs containing tokens, or personal IPs.
Run: docker logs prismarr --tail 200
-->

```
<paste last ~200 lines>
```

## Screenshots (optional)

<!-- Drag-and-drop images here if the bug is visual. -->
