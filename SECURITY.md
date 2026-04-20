# Security Policy

## Supported versions

Prismarr follows a rolling-release model on the `main` branch, with tagged
stable releases. Security fixes are applied to:

| Version       | Supported          |
| ------------- | ------------------ |
| `1.x` (latest)| :white_check_mark: |
| `< 1.0`       | :x:                |

Always run the latest minor version of the major you're on. Docker users can
follow `prismarr/prismarr:latest` or pin to a specific tag.

## Reporting a vulnerability

**Please do not open public GitHub issues for security vulnerabilities.**

Instead, email **shoshuo3@gmail.com** with:

- A description of the vulnerability and its impact
- Steps to reproduce (proof-of-concept appreciated, not required)
- The Prismarr version affected (`docker inspect prismarr | grep Image`)
- Any suggestions for a fix (optional)

## What to expect

- **Acknowledgement within 72 hours** of your report being received.
- **Initial assessment within 7 days** — we'll confirm whether it's a valid
  issue and share our triage.
- **Fix target**: within 14 days for critical issues, 30 days for medium.
- **Coordinated disclosure**: we'll work with you on a disclosure date once
  a fix is ready; typically 7 days after the fix lands on `main`.
- **Credit**: if you'd like public credit, we'll list you in the release
  notes. Anonymous reports also welcome.

## Scope

In scope:

- The Prismarr application itself (`symfony/` + `docker/`)
- Default configuration shipped in `.env` and `docker-compose.example.yml`
- The setup wizard, authentication, and permission model
- Any endpoint served by FrankenPHP on the default port

Out of scope:

- Third-party services Prismarr connects to (Radarr, Sonarr, qBittorrent, etc.)
  — report those upstream
- Misconfigurations by the user (e.g. exposing Prismarr to the public
  internet without a reverse-proxy + TLS)
- `APP_ENV=dev` accidentally left on in production (the built-in
  ProfilerGuard blocks remote access to `/_profiler` but this does not
  replace a proper prod configuration)
- Physical or social-engineering attacks

## Known considerations

Prismarr is designed for self-hosted use behind a trusted network or a
properly configured reverse-proxy. Default settings prioritize ease-of-setup
for a home user over hardening for an untrusted public-internet deployment.
If you expose Prismarr directly to the public internet, please:

- Put it behind TLS (Traefik, nginx, Caddy, Cloudflare Tunnel)
- Configure `TRUSTED_PROXIES` in `.env.local`
- Keep `APP_ENV=prod` — never `dev`
- Monitor the access logs
