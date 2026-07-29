<p align="center">
  <img src="symfony/public/img/prismarr/prismarr-logo-horizontal.png" alt="Prismarr" width="420">
</p>

<p align="center">
  <strong>One dashboard for your self-hosted media stack.</strong>
</p>

<p align="center">
  This is <a href="https://github.com/ndandan/Prismarr"><strong>ndandan's fork</strong></a> of
  <a href="https://github.com/Shoshuo/Prismarr"><strong>Shoshuo/Prismarr</strong></a> —
  it tracks upstream closely and adds extra integrations and dashboard features on top.
</p>

<p align="center">
  <a href="https://github.com/ndandan/Prismarr/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/ndandan/Prismarr/ci.yml?branch=main&label=CI" alt="CI"></a>
  <a href="https://github.com/ndandan/Prismarr/pkgs/container/prismarr"><img src="https://img.shields.io/badge/GHCR-ndandan%2Fprismarr-2496ED?logo=docker&logoColor=white" alt="GHCR image"></a>
  <a href="https://github.com/ndandan/Prismarr/commits/main"><img src="https://img.shields.io/github/last-commit/ndandan/Prismarr?color=6366f1" alt="Last commit"></a>
  <a href="https://github.com/Shoshuo/Prismarr"><img src="https://img.shields.io/badge/upstream-Shoshuo%2FPrismarr-f59e0b?logo=github" alt="Upstream"></a>
</p>

<p align="center">
  <a href="https://github.com/ndandan/Prismarr/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-AGPL--3.0-blue" alt="AGPL-3.0"></a>
  <img src="https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white" alt="PHP 8.4">
  <img src="https://img.shields.io/badge/Symfony-8-000000?logo=symfony&logoColor=white" alt="Symfony 8">
  <img src="https://img.shields.io/badge/FrankenPHP-1.3-orange" alt="FrankenPHP 1.3">
  <img src="https://img.shields.io/badge/SQLite-zero--config-003B57?logo=sqlite&logoColor=white" alt="SQLite">
</p>

<p align="center">
  <a href="#about-this-fork">About this fork</a> ·
  <a href="#features">Features</a> ·
  <a href="#quick-start">Quick start</a> ·
  <a href="#configuration">Configuration</a> ·
  <a href="#upgrade">Upgrade</a> ·
  <a href="#contributing">Contributing</a> ·
  <a href="#license">License</a>
</p>

---

<p align="center">
  <img src="docs/screenshots/showcase.gif" alt="Prismarr showcase" width="100%">
</p>

<details>
<summary><strong>Static screenshots</strong> (click to expand)</summary>
<br>

<p align="center">
  <img src="docs/screenshots/dashboard.png" width="49%" alt="Dashboard">
  <img src="docs/screenshots/discover.png" width="49%" alt="Discover">
</p>
<p align="center">
  <img src="docs/screenshots/calendar.png" width="49%" alt="Calendar">
  <img src="docs/screenshots/radarr.png" width="49%" alt="Movies (Radarr)">
</p>
<p align="center">
  <img src="docs/screenshots/sonarr.png" width="49%" alt="Series (Sonarr)">
  <img src="docs/screenshots/requests.png" width="49%" alt="Requests (Seerr)">
</p>
<p align="center">
  <img src="docs/screenshots/deluge.png" width="49%" alt="Downloads (Deluge)">
  <img src="docs/screenshots/sabnzbd.png" width="49%" alt="Downloads (SABnzbd)">
</p>
<p align="center">
  <img src="docs/screenshots/plex-activity.png" width="49%" alt="Plex activity (Tautulli)">
  <img src="docs/screenshots/prowlarr.png" width="49%" alt="Indexers (Prowlarr)">
</p>
<p align="center">
  <img src="docs/screenshots/settings.png" width="49%" alt="Settings (custom theme)">
</p>

</details>

---

## About

**Prismarr** brings qBittorrent, Radarr, Sonarr, Prowlarr, Seerr and
TMDb together in a single modern Symfony interface. No more juggling six
tabs to manage your library.

It's not a replacement for Radarr or Sonarr - those run side by side and
keep doing what they do best. Prismarr is the unified control surface:
one search bar that hits the local library and TMDb, one calendar that
merges movie releases and episode airs, one dashboard that surfaces what
matters today (a recent download, a pending request, a trending pick),
and one settings page where every API key lives - never on disk in plain
text and never in environment variables.

The whole thing ships as a single Docker container with SQLite inside.
First boot opens a 7-step wizard: create the admin, plug your services in,
done. No external database, no Redis, no per-service `.env` files. Pull
the image, mount one volume, you're up.

Prismarr is created and maintained by [Shoshuo](https://github.com/Shoshuo).
This repository is a personal fork of it — see below for what's different.

---

## About this fork

This fork stays close to upstream (it merges upstream regularly and keeps
its code mergeable back), and everything general-purpose gets offered back
as an upstream PR. What lives here:

**Contributed back and merged upstream** — these started here and are now
part of the original project:

- Library-page performance rework (short-TTL library cache + batched `curl_multi` *arr calls) — [#56](https://github.com/Shoshuo/Prismarr/pull/56)
- CI/workflow modernisation with fork-friendly guards — [#59](https://github.com/Shoshuo/Prismarr/pull/59)
- Plex activity via Tautulli (dashboard widget + full Plex Activity page) and latency-aware health chips — [#60](https://github.com/Shoshuo/Prismarr/pull/60)
- In-place quick-look modal on the dashboard — [#61](https://github.com/Shoshuo/Prismarr/pull/61)
- Plex Activity statistics, graphs and per-user filter — [#62](https://github.com/Shoshuo/Prismarr/pull/62)

**Proposed upstream, pending** — already shipped in this fork:

- Dashboard theming: 17 glance-style HSL presets with an admin Theme picker — [#66](https://github.com/Shoshuo/Prismarr/pull/66)
- Dashboard layout customization: reorder + hide/show sections — [#68](https://github.com/Shoshuo/Prismarr/pull/68)
- One unified rich detail modal everywhere (top-bar search, dashboard, Explorer, Plex activity) — [#69](https://github.com/Shoshuo/Prismarr/pull/69)
- Search fix: keep the global search icon from overlapping text in compact density — [#70](https://github.com/Shoshuo/Prismarr/pull/70)
- Performance slice: cross-request service-health caching, browser cache headers on static assets, prod cache pre-warmed at image build — [#74](https://github.com/Shoshuo/Prismarr/pull/74)
- Plex items open the app-global quick-look modal — [#75](https://github.com/Shoshuo/Prismarr/pull/75)
- Deluge tab: full torrent management via deluge-web JSON-RPC — [#76](https://github.com/Shoshuo/Prismarr/pull/76)

**Fork-only** — only available here:

- **Transmission tab:** a third download client alongside qBittorrent and
  Deluge — list/table/compact views, filtering, search, sort and pagination,
  bulk actions (pause/resume/delete/recheck plus pause-all/resume-all), a
  per-torrent detail modal (general/files/trackers/peers), add-by-URL and
  add-by-file, and a read-only filter over Transmission's own labels. Talks the
  Transmission RPC API and handles its session handshake transparently (the
  expected `409` that carries the session token is treated as "reachable"; a
  real `401` is reported as an auth failure). Reworked from a community fork
  PR — a candidate to offer upstream rather than deliberately fork-bound.
- **FrankenPHP/Symfony worker mode (opt-in):** an env-gated flag
  (`PRISMARR_WORKER`) that boots the kernel once and keeps it resident instead
  of rebuilding it per request. Off by default. On a real homelab it cut a
  health-poll response from ~46 ms to ~3 ms (~15×) and a large Films-page render
  from ~3.0 s to ~0.3 s (~10×); TMDb-bound pages like Discover are unchanged,
  as expected. See the [Configuration](#environment-variables-optional) table.
- **Unraid server widget:** an admin-only dashboard section for the Unraid 7
  GraphQL API — array and disk health, parity status with live check progress
  and ETA, CPU/RAM/uptime, per-container Docker status chips, and UPS state.
- **Houndarr widget:** a read-only dashboard stat tile for
  [Houndarr](https://github.com/av1155/houndarr) backlog searching — tracked /
  eligible / cooldown / unreleased counts and 7-day searches, plus a health chip.
- **UniFi Network widget:** an admin-only dashboard section for the UniFi OS
  Network API — live WAN up/download bandwidth, client counts
  (wired/wireless/guest), a 24-hour usage graph, and per-device infrastructure
  status (gateway/switches/APs) with gateway CPU/RAM.
- **UniFi Network tab (`/unifi`):** a full admin-only network-operations page
  over the classic UniFi Network API — WAN/gateway/client tiles, 7-day traffic
  and 30-day speedtest trends as server-rendered SVG (no charting library on the
  wire), device inventory, RF environment (per-AP radios plus a neighbouring-AP
  scan), VLAN inventory, wireless clients, live talkers, top clients and
  DHCP-reservation mismatches. Three readers own their own endpoint group and
  cadence (live 10 s / infrastructure 60 s / history 300 s) and a coalescing
  poller batches every due region into one request per tick, so each endpoint is
  hit once per cycle however many panels use it. Read-only, and every panel
  degrades to an empty state instead of letting a sick console break the page.
  Reuses the widget's UniFi settings — no extra configuration.

The full details live in [docs/FORK-CHANGES.md](docs/FORK-CHANGES.md) and the
[CHANGELOG](CHANGELOG.md). The fork is run daily on a real homelab (Unraid),
and every change lands through the same quality gate as upstream: PHP lint,
Twig lint and the full PHPUnit suite must be green in CI before an image is
published.

---

## Features

Everything upstream Prismarr does, plus the fork additions (marked **fork**):

- **Movies & Series:** Radarr and Sonarr libraries with five view modes, **multiple instances side by side** (1080p / 4K / Anime, each first-class in the UI), global `Ctrl+K` search and a quick-add modal with a per-instance target picker.
- **Unified calendar:** movie and episode releases merged across instances, deduped by `tmdbId` / `tvdbId`, with month / week / day views and iCal export.
- **Dashboard:** hero spotlight, upcoming releases, pending Seerr requests, live service health, watchlist, trending and latest additions. Paints instantly, each widget hydrates on its own.
- **Dashboard customization (fork):** an admin can reorder and hide/show every dashboard section, and pick one of 17 theme presets.
- **Quick-look everywhere (fork):** clicking any media tile — dashboard, top-bar search result, Explorer, Plex activity — opens one rich in-place detail modal (poster, synopsis, ratings, release/air dates, watchlist, Add/Manage deep-links) instead of navigating away.
- **Downloads:** full qBittorrent dashboard (server-side pagination, sorting, filters, drag-and-drop upload), a full Deluge tab (live table, labels, seeding columns, detail panel, add/limits — via deluge-web JSON-RPC), a full Transmission tab (same list/table/compact views, bulk actions, detail modal and add-by-URL/file, with a read-only filter over Transmission's own labels — via the Transmission RPC API), plus dedicated SABnzbd and NZBGet pages. Optional Gluetun integration.
- **Discovery:** TMDb landing page with recommendations and trending, watchlist, an explorer with filters, and deep-links into your library.
- **Plex activity (Tautulli):** optional read-only page (now playing, watch stats, graphs with a per-user filter, history) plus a "Current Plex activity" dashboard widget. The API key stays server-side and responses are sanitised.
- **Unraid server monitoring (fork):** optional admin-only dashboard section — array/parity health with live check progress, disks, CPU/RAM, Docker containers and UPS, via the Unraid 7 GraphQL API.
- **Houndarr (fork):** optional dashboard stat tile with backlog-search totals and a health chip.
- **UniFi network monitoring (fork):** optional admin-only dashboard section — live WAN up/download, client counts (wired/wireless/guest), a 24-hour usage graph and gateway/switch/AP status, via the UniFi OS Network API. Plus a full **UniFi Network tab** (`/unifi`, admin-only): 7-day traffic and 30-day speedtest trends, device inventory, RF environment (AP radios and neighbouring APs), VLANs, wireless clients, live talkers, top clients and DHCP-reservation mismatches — read-only, and every panel falls back to an empty state rather than breaking the page.
- **Preferences:** theme, UI density, timezone, date format, English / French UI, settings export / import (credentials always stripped).
- **Security:** Symfony auth with login rate-limiter, non-root container, dynamic CSP, SSRF protection on user-provided URLs, CSRF on every mutation.

---

## Quick start

### Requirements

- Docker and Docker Compose
- At least one of: qBittorrent, Deluge, Transmission, Radarr, Sonarr, Prowlarr, Seerr
- Optional: Gluetun if qBittorrent runs behind a VPN
- Optional: a Deluge instance (deluge-web URL + password) for the Deluge tab
- Optional: a Transmission instance (RPC URL; user/password only if the daemon requires them) for the Transmission tab
- Optional: a TMDb API key (free) to enable the Discovery page
- Optional: a Tautulli instance (URL + API key) for the Plex activity page and widget
- Optional: an Unraid 7 server (GraphQL API key) for the server monitoring widget
- Optional: a Houndarr instance (URL + API key) for the backlog-search widget

### Which image?

| Image | What you get |
|---|---|
| `ghcr.io/ndandan/prismarr:latest` | **This fork** — upstream Prismarr plus everything listed above |
| `shoshuo/prismarr:latest` | The original upstream project (Docker Hub) |

The instructions below use the fork image. Both use the same volume layout,
so switching between them is just changing the `image:` line (back up first —
the fork may carry schema migrations upstream doesn't have yet).

### Install

**Step 1.** Create a file named `docker-compose.yml`:

```yaml
services:
  prismarr:
    image: ghcr.io/ndandan/prismarr:latest
    container_name: prismarr
    restart: unless-stopped
    stop_grace_period: 30s
    ports:
      - "7070:7070"
    volumes:
      - prismarr_data:/var/www/html/var/data

volumes:
  prismarr_data:
```

#### Bind-mount variant (Servarr-style layout)

If you prefer host folders next to your other Servarr containers (Radarr, Sonarr, etc.) for easy browsing, replace the named volume with a bind-mount. The container target must stay at `/var/www/html/var/data`:

```yaml
    volumes:
      - ./prismarr-config:/var/www/html/var/data
```

Drop the top-level `volumes:` block, and create the host folder before first start: `mkdir -p ./prismarr-config`.

> [!warning]
> If you write your own compose, the container target for the data volume must be `/var/www/html/var/data`. Prismarr does not use the Servarr `/config` or `/app/config` convention. A bind-mount on the wrong path silently creates an anonymous volume that resets on every redeploy, with no error in the logs.

> [!warning]
> **Unraid:** map the data volume to a dedicated subfolder on your **cache pool** (`/mnt/cache/appdata/prismarr`), not to `/mnt/user/...`. The `/mnt/user` share goes through FUSE (shfs), where session and database I/O can saturate the share. Prismarr releases the session lock early on read-only requests, which removes the worst of it, but a dedicated cache-pool subfolder (never the `appdata` root, so the startup `chown` stays scoped) remains the right mapping.

---

**Step 2.** Start the container:

```bash
docker compose up -d
```

**Step 3.** Open `http://localhost:7070` in your browser. The setup wizard
will guide you through:

- admin account creation
- TMDb API key (optional)
- Radarr / Sonarr / Prowlarr / Seerr URLs and keys
- qBittorrent + Gluetun, Deluge, Transmission (optional)

Tautulli, Unraid and Houndarr are configured later from
**Settings → Services** (each with its own enable toggle and Test-connection
button).

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

External service credentials (TMDb / Radarr / Sonarr / Prowlarr / Seerr /
Tautulli / Unraid / Houndarr API keys, qBittorrent, Deluge and Transmission
passwords, service URLs),
display preferences and language are stored in the SQLite database
(`setting` table). They never appear in environment variables or in any
committable file.

Two framework-level secrets - `APP_SECRET` and `MERCURE_JWT_SECRET` - are
auto-generated on first boot and persisted inside the volume at
`var/data/.env.local`. They never leave the volume; you don't have to set,
rotate or back them up manually.

### Environment variables (optional)

| Variable | Default | Purpose |
|---|---|---|
| `APP_ENV` | `prod` | Switch to `dev` for local development only |
| `PRISMARR_PORT` | `7070` | Internal listening port |
| `TRUSTED_PROXIES` | `127.0.0.1,REMOTE_ADDR` | Adjust if running behind Traefik / nginx / Caddy / Cloudflare Tunnel |
| `TZ` | `UTC` | Container time zone (e.g. `Europe/Paris`, `Pacific/Honolulu`). Drives both the OS clock and the PHP date helpers — see upstream issue [#12](https://github.com/Shoshuo/Prismarr/issues/12) |
| `PHP_MEMORY_LIMIT` | `1024M` | PHP memory ceiling per request. Bump (e.g. `2048M`, `-1` for unlimited) if you have a very large Radarr / Sonarr library — see upstream issue [#13](https://github.com/Shoshuo/Prismarr/issues/13) |
| `PHP_MAX_EXECUTION_TIME` | `120` | PHP wall-time ceiling per request, in seconds. Bump alongside `PHP_MEMORY_LIMIT` if the films / series page times out |
| `PRISMARR_WORKER` | _(unset)_ | Enables FrankenPHP/Symfony **worker mode** — the kernel is booted once and kept resident, skipping the per-request bootstrap for faster responses. Set to `1` (or `true`) for the default worker count, or to a number (e.g. `4`) to run that many workers. Off by default / `0` / `false` = classic per-request mode; no image rebuild needed to toggle |
| `PRISMARR_WORKER_NUM` | _(2× CPU)_ | Optional explicit worker-thread count; overrides the count when `PRISMARR_WORKER` is enabled. Leave unset to use FrankenPHP's default (2× the available CPUs) |

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

### Image tags

The fork publishes two tags to GHCR, built by GitHub Actions:

- **`ghcr.io/ndandan/prismarr:latest`** — rebuilt from `main`. This is the
  stable fork build; nothing reaches `main` without green CI (lint + full
  PHPUnit suite) and a live test.
- **`ghcr.io/ndandan/prismarr:beta`** — pre-release builds of in-progress
  feature branches, used to test on a real deployment before merging.

> [!warning]
> **`:beta` can be broken, regress features, or lose data.** Do not run it on
> an instance you care about, and back up the `prismarr_data` volume before
> switching — a pre-release migration may not be reversible.

The fork does not cut versioned releases; for pinned semver tags, use the
upstream image (`shoshuo/prismarr:1.x.x`) — minus the fork-only features.

---

## Tech stack

- **Backend**: PHP 8.4 / Symfony 8 / Doctrine ORM
- **Server**: FrankenPHP (Caddy + PHP embed; optional resident worker mode) supervised by s6-overlay
- **Frontend**: Tabler UI + Alpine.js + Turbo (Hotwire) via Symfony AssetMapper
- **Database**: SQLite (zero-config, automatic Doctrine migrations)
- **Cache + sessions**: filesystem (no Redis required)
- **Queue**: Symfony Messenger (Doctrine transport)
- **Real-time**: Mercure SSE built into Caddy

A single Docker container ships everything. The fork's GHCR image is built
for `linux/amd64` (its target deployment is Unraid); the upstream Docker Hub
image also ships `arm64` if you need ARM.

---

## FAQ

**Why a fork?**
To add integrations the upstream project considers out of scope (Unraid
server monitoring, Houndarr) and to iterate on features before proposing
them upstream. Everything general-purpose is offered back as a PR — five
have been merged so far, and several more are open.

**Will this fork drift away from upstream?**
No — staying mergeable is an explicit goal. Upstream changes are merged in
regularly, upstream-origin code is left untouched even when fork changes
obsolete it, and fork features are built to sit on top rather than rewrite.

**Does Prismarr need internet access?**
Only for TMDb (cover art, metadata, discovery) and the services you point
it at. The app itself works fully on a LAN; if you don't configure TMDb,
the Discovery page is the only feature that goes dark.

**Can I run it behind a reverse proxy?**
Yes. Set `TRUSTED_PROXIES` to your proxy network (see Configuration).
HSTS and Permissions-Policy headers are emitted by the embedded Caddy.

**Where are my API keys stored? Is it safe?**
In the SQLite database (table `setting`). The database lives in the
`prismarr_data` Docker volume, never in environment variables, never
in any file under version control. The export feature strips every
key matching `api_key`, `password` or `secret` so accidentally
sharing your config is safe.

**How do I back up my install?**
Snapshot the `prismarr_data` Docker volume (one-liner in the
Configuration section). It contains the SQLite DB, the auto-generated
secrets, sessions, cache and avatars - everything needed to restore.

**Can I switch between the fork image and the upstream image?**
Yes, they share the same volume layout — but back up first. The fork can
carry database migrations upstream doesn't have yet, and a downgrade
across a migration may require restoring the backup.

---

## Contributing

- **Improvements to core Prismarr** are best opened against
  [upstream](https://github.com/Shoshuo/Prismarr) so every user benefits —
  that's where this fork sends its own general-purpose work.
- **Issues and PRs about fork-only features** (Unraid widget, Houndarr
  widget, anything in [FORK-CHANGES.md](docs/FORK-CHANGES.md)) are welcome
  here.

The upstream contributor docs apply to this fork too:
[CONTRIBUTING.md](CONTRIBUTING.md), [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md),
[SECURITY.md](SECURITY.md) (no public issues for vulnerabilities).

Before any commit: `make check` (PHP lint + Twig lint + full PHPUnit suite).

---

## License

[AGPL-3.0](LICENSE) - you may use, modify and redistribute Prismarr freely,
including in self-hosted production. Derivatives must remain open source
under the same license. This fork inherits and keeps the upstream license.

---

## Acknowledgements

- **[Shoshuo](https://github.com/Shoshuo)** — Prismarr's author and
  maintainer. The overwhelming majority of this codebase is their work; the
  original project lives at
  [Shoshuo/Prismarr](https://github.com/Shoshuo/Prismarr), with a
  [Discord](https://discord.gg/wd4hwU3jTF) and a
  [Buy Me a Coffee](https://buymeacoffee.com/shoshuo) page if you'd like to
  support it.
- [Overseerr / Seerr](https://github.com/Fallenbagel/jellyseerr) and the
  [Servarr](https://wiki.servarr.com/) family (Radarr, Sonarr, Prowlarr,
  Bazarr…) for the inspiration.
- [Tabler](https://tabler.io/) for the UI kit.
- [Tautulli](https://tautulli.com/), [Houndarr](https://github.com/av1155/houndarr)
  and the [Unraid API](https://docs.unraid.net/API/) for the services the
  fork integrates.

---

> ## Note on AI usage
>
> The upstream project documents its AI usage in the
> [original README](https://github.com/Shoshuo/Prismarr#note-on-ai-usage);
> that note covers the bulk of this codebase and stands as written.
>
> The fork's additions (Tautulli, quick-look, themes, layout customization,
> Unraid and Houndarr widgets, the performance work) were built with heavy use
> of [Claude Code](https://claude.com/claude-code) (Anthropic), with the fork
> maintainer directing the design, reviewing the changes, and verifying each
> feature live on a real deployment before it ships. Nothing lands without
> green CI: PHP lint, Twig lint and the full PHPUnit suite.
