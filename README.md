<p align="center">
  <img src="symfony/public/img/prismarr/prismarr-logo-horizontal.png" alt="Prismarr" width="420">
</p>

<p align="center">
  <strong>One dashboard for your self-hosted media stack.</strong>
</p>

<p align="center">
  <a href="https://github.com/Shoshuo/Prismarr/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-AGPL--3.0-blue" alt="AGPL-3.0"></a>
  <img src="https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white" alt="PHP 8.4">
  <img src="https://img.shields.io/badge/Symfony-8-000000?logo=symfony&logoColor=white" alt="Symfony 8">
  <img src="https://img.shields.io/badge/FrankenPHP-1.3-orange" alt="FrankenPHP 1.3">
  <img src="https://img.shields.io/badge/SQLite-zero--config-003B57?logo=sqlite&logoColor=white" alt="SQLite">
</p>

<p align="center">
  <a href="#features">Features</a> ·
  <a href="#quick-start">Quick start</a> ·
  <a href="#configuration">Configuration</a> ·
  <a href="#upgrade">Upgrade</a> ·
  <a href="#troubleshooting">Troubleshooting</a> ·
  <a href="#roadmap">Roadmap</a> ·
  <a href="#license">License</a>
</p>

---

## About

**Prismarr** brings **qBittorrent**, **Radarr**, **Sonarr**, **Prowlarr**,
**Jellyseerr** and **TMDb** together in a single modern Symfony interface.
No more juggling six tabs to manage your library.

Designed for homelabs: one Docker container, no database to set up, every
piece of configuration done from the UI.

---

## Features

### Unified media management
- Movies (Radarr) and Series (Sonarr) with five view modes
- Global `Ctrl+K` search across the local library and TMDb / TheTVDB
- Quick-add modal reachable from any page
- Unified calendar (movie + episode releases) with month / week / day views
  and an iCal export

### Dashboard
- Hero spotlight with a random pick from your library
- Upcoming releases (seven-day mini-calendar)
- Pending Jellyseerr requests enriched with TMDb metadata
- Live health of all six services
- Personal watchlist, weekly TMDb trending, latest library additions

### Downloads
- Full qBittorrent dashboard: server-side pagination, sorting and filters
- Drag-and-drop `.torrent` upload (multi-file)
- Pipeline badges: clicking a torrent jumps to its movie / series
- Cross-tab toasts when a download finishes
- Optional Gluetun integration: public IP, country, port forwarding sync

### Discovery
- TMDb landing page with hero, personalised recommendations, trending
- Personal watchlist, Explorer with filters (genre / decade / cast)
- Countdown for upcoming releases
- Deep-links into your existing library

### Profile and preferences
- `/profil` page: edit display name, password and avatar (JPG / PNG / WebP / GIF, 2 MB max)
- Display preferences: theme colour, UI density, toasts, timezone,
  date / time format, qBit auto-refresh, default home page
- English / French UI (EN-first, FR fully translated, ICU plural support)
- Settings export / import (credentials are always stripped)

### Security
- Symfony authentication with login rate-limiter (5 attempts per IP+username / 15 min)
- Container runs as non-root, dynamic Content-Security-Policy
- SSRF protection on user-provided URLs (protocol allowlist, cloud-metadata blocklist)
- CSRF tokens on every mutation, branded error pages that never leak exception data
- Profiler routes return 403 for non-RFC1918 clients in dev

---

## Quick start

### Requirements

- Docker and Docker Compose
- At least one of: qBittorrent, Radarr, Sonarr, Prowlarr, Jellyseerr
- Optional: Gluetun if qBittorrent runs behind a VPN
- Optional: a TMDb API key (free) to enable the Discovery page

### Install

```bash
# 1. Download the example compose file
curl -O https://raw.githubusercontent.com/Shoshuo/Prismarr/main/docker-compose.example.yml
mv docker-compose.example.yml docker-compose.yml

# 2. Start the container
docker compose up -d

# 3. Open http://localhost:7070
#    The setup wizard guides you through:
#      - admin account creation
#      - TMDb API key
#      - Radarr / Sonarr / Prowlarr / Jellyseerr URLs and keys
#      - qBittorrent + Gluetun (optional)
```

`APP_SECRET` and `MERCURE_JWT_SECRET` are auto-generated on first boot and
persisted in the `prismarr_data` volume. No `.env` editing required.

### Default port

Prismarr listens on `7070`. To use a different port, change the left side of
the mapping in `docker-compose.yml`:

```yaml
ports:
  - "8080:7070"  # access on http://localhost:8080
```

---

## Configuration

Everything is configured from the UI:

- **First boot**: the 7-step setup wizard at `/setup`
- **Later**: the Settings page at `/admin/settings` (admin only)

API keys, service URLs, display preferences and language are stored in the
SQLite database (`setting` table). Nothing sensitive lives in environment
variables.

### Environment variables (optional)

| Variable | Default | Purpose |
|---|---|---|
| `APP_ENV` | `prod` | Switch to `dev` for local development only |
| `PRISMARR_PORT` | `7070` | Internal listening port |
| `TRUSTED_PROXIES` | `127.0.0.1,REMOTE_ADDR` | Adjust if running behind Traefik / nginx / Caddy / Cloudflare Tunnel |

### Persistent data

Everything lives in the `prismarr_data` Docker volume:

- `prismarr.db` (SQLite database)
- `.env.local` (auto-generated secrets)
- `sessions/` (login sessions)
- `cache/` (TMDb / cover thumbnails)
- `avatars/` (uploaded user avatars)

A standard backup is `docker run --rm -v prismarr_data:/data -v $(pwd):/backup alpine tar czf /backup/prismarr-data.tgz -C /data .`.

### Reverse proxy

Prismarr handles HSTS and Permissions-Policy headers itself. When sitting
behind a reverse proxy that terminates TLS (Traefik, nginx, Caddy,
Cloudflare Tunnel), set `TRUSTED_PROXIES` to your proxy network so that
Symfony reads the right `X-Forwarded-*` headers.

---

## Upgrade

```bash
docker compose pull
docker compose up -d
```

SQLite migrations run automatically on container start. The `prismarr_data`
volume is preserved.

To pin a specific version instead of `latest`:

```yaml
services:
  prismarr:
    image: prismarr/prismarr:1.0.0
```

---

## Troubleshooting

### Forgot the admin password

```bash
docker exec -it prismarr php bin/console app:user:reset-password <email>
```

### qBittorrent IP banned after empty-password loop

If you ever ran a Prismarr build from before v1.0, an admin save action
(e.g. changing the theme colour) could silently overwrite every API key
with an empty string — including the qBittorrent password — and qBit would
ban Prismarr's IP. Fixed in 1.0.

To recover: open the qBittorrent web UI, go to Tools → Options → Web UI →
"Bypass authentication for clients in whitelisted IP subnets" or
temporarily clear the IP ban list, then re-enter the credentials in
Prismarr's `/admin/settings`.

### Setup wizard loops forever

The wizard finishes when the `setup_completed` flag is set. To force it
back to step 1:

```bash
docker exec -it prismarr php bin/console doctrine:query:sql \
  "DELETE FROM setting WHERE key = 'setup_completed'"
```

### Health check returns 503

`GET /api/health` returns 503 when SQLite is unreachable. Inspect the
container logs:

```bash
docker logs prismarr --tail 200
```

The most common cause is a corrupted volume after a host-level disk full
event. Restoring the latest backup is the fastest path.

### Container won't start

```bash
docker logs prismarr
```

If the error mentions `permission denied` on the volume, your host
filesystem is preventing the container's `www-data` user (UID 33 by
default) from writing. Make sure the volume is a Docker-managed volume
and not a bind mount onto a directory owned by root.

---

## Roadmap

### v1.0 — Public release
- [x] 7-step setup wizard
- [x] Authentication with login rate-limiter
- [x] Doctrine migrations (clean upgrades)
- [x] PHPUnit suite (179 tests / 376 assertions)
- [x] Multi-architecture Docker image (amd64 + arm64)
- [x] English / French UI (EN source of truth)
- [x] Admin settings page (services, display, languages, backup)
- [x] Dashboard, Calendar (month / week / day + iCal export), Profile page
- [x] Published on Docker Hub

### v1.x — Improvements
- [ ] Multi-user roles (read-only viewer vs admin)
- [ ] Jellyfin widget (live sessions + stats)
- [ ] Discord / Ntfy / Telegram notifications
- [ ] Historical bandwidth graphs
- [ ] Public REST API for third-party integrations

### v2.0 — Automation
- [ ] Auto-import of an existing library
- [ ] Custom processing rules
- [ ] Optional MariaDB / PostgreSQL backend

---

## Tech stack

- **Backend**: PHP 8.4 / Symfony 8 / Doctrine ORM
- **Server**: FrankenPHP (Caddy + PHP embed, worker mode) supervised by s6-overlay
- **Frontend**: Tabler UI + Alpine.js + Turbo (Hotwire) via Symfony AssetMapper
- **Database**: SQLite (zero-config, automatic Doctrine migrations)
- **Cache + sessions**: filesystem (no Redis required)
- **Queue**: Symfony Messenger (Doctrine transport)
- **Real-time**: Mercure SSE built into Caddy

A single Docker container ships everything. The image is `~282 MB` and runs
on `amd64` and `arm64`.

---

## Contributing

Contributions are welcome — please open an issue first to discuss the scope
before submitting a PR.

- **Contributor guide**: [CONTRIBUTING.md](CONTRIBUTING.md) (Definition of Done + golden rules)
- **Code of conduct**: [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) (Contributor Covenant 2.1)
- **Security vulnerability**: [SECURITY.md](SECURITY.md) — please **do not** open a public issue, contact by email
- **Changelog**: [CHANGELOG.md](CHANGELOG.md)

Before any commit: `make check` (PHP lint + Twig lint + full PHPUnit suite).

---

## License

[AGPL-3.0](LICENSE) — you may use, modify and redistribute Prismarr freely,
including in self-hosted production. Derivatives must remain open source
under the same license.

---

## Acknowledgements

Inspired by the remarkable work of:

- [Overseerr / Jellyseerr](https://github.com/Fallenbagel/jellyseerr)
- The [Servarr](https://wiki.servarr.com/) family (Radarr, Sonarr, Prowlarr, Bazarr…)
- [Tabler](https://tabler.io/) for the UI kit

And thanks to the whole r/selfhosted community.
