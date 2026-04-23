# Changelog

All notable changes to Prismarr are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Work toward the first public release of Prismarr. Entries here will be rolled
into `[1.0.0]` at publication time.

### Added
- **Profile page** at `/profil` — edit display name and password, upload an
  avatar (JPG / PNG / WebP / GIF, 2 MB max). Avatars live in the
  `var/data/avatars/` volume so they survive container recreations. The
  page also shows a small personal stats block (watchlist count, member
  since, role) and the four most recent watchlist additions.
- **Services health badge** in the topbar — a coloured dot (green = all
  up, orange = partial, red = none) with a dropdown listing the live
  state of the six services. Refreshes every 60 s. Backed by a new
  `GET /api/health/services` endpoint (ROLE_USER — the service list
  leaks part of the configuration, so it is no longer public).
- **Calendar week and day views** — toggle between Month, Week and Day
  at the top of `/calendrier`. State is persisted in `localStorage` and
  also reflected in the URL (`?view=…&date=YYYY-MM-DD`) so widgets on
  the dashboard can deep-link into a specific day. Past days are
  dimmed; events on past days are greyscaled and struck through.
- **iCal export** at `/calendrier.ics` — downloads an RFC 5545
  calendar with stable UIDs (movie and TV episode releases, each typed
  cinema / digital / physical / series). Existing calendar clients
  update events in place rather than duplicating them.
- **Backup and import in `/admin/settings`** — export non-sensitive
  settings as JSON, reimport them with a CSRF-protected form (version
  check, 64 KB max, scalar-only values). Keys matching `api_key`,
  `password` or `secret` are never exported and are always filtered
  out on import, even if a malicious file tries to smuggle them in.
- **About section in `/admin/settings`** — runtime information
  (Prismarr, Symfony, PHP, SAPI, environment, database path and size,
  server timezone), three counters (users / movies / series — tolerant
  of Radarr / Sonarr being offline), and links to the project sources
  and issue tracker.
- **Reset display preferences** button in `/admin/settings` — clears
  every `display_*` key so reading them falls back to the defaults.
- **Twig filters** `|prismarr_date`, `|prismarr_time` and
  `|prismarr_datetime` — apply the admin's chosen timezone, date
  format (FR / US / ISO) and time format (24 h / 12 h) to any
  `DateTimeInterface`, ISO 8601 string or timestamp.
- **Global search improvements** — ARIA combobox, arrow-key
  navigation with visible highlight, an inline clear button, a
  recent-searches list stored in `localStorage` (shown when focusing
  an empty input), and Everything / Movies / Series filter pills.
  Results are now grouped (online discovery first, local library
  second).
- **Main dashboard** at `/tableau-de-bord` — the new default landing page
  for logged-in users. Aggregates seven widgets with graceful degradation
  when a service is offline: hero spotlight (random library pick with
  fanart, genres, rating, quality and a CTA), upcoming releases
  (seven-day mini-calendar), pending Jellyseerr requests enriched with
  TMDb metadata, live health of the six services, personal watchlist,
  weekly TMDb trending, and most-recent library additions merged
  across movies and series.
- **Display preferences** in `/admin/settings` — nine typed options
  (home page, toasts, timezone, date/time format, theme colour,
  default Radarr/Sonarr view, qBit auto-refresh, UI density) stored
  as `display_*` keys. The admin page now uses tab navigation
  (Services / Display) with URL-hash + `sessionStorage` persistence so
  the admin stays on the same section across POST/Redirect/GET.
  Effective behaviour wiring for these preferences lands in a follow-up.
- **Collapsible sidebar** with a toggle button at the bottom: 4 rem
  icons-only width when collapsed, CSS-only tooltips on hover, state
  persisted in `localStorage` with FOUC-prevention in `<head>`.
- **Admin settings page** at `/admin/settings` — edit service URLs and API keys
  without replaying the setup wizard. Per-service "test connection" button,
  live status pill, show/hide toggle for each service in the sidebar, and
  show/hide toggle for internal features (Calendar). Two-column layout with
  sticky section nav, designed to host future preference sections.
- **Branded error pages** for 403/404/500/503 rendered with the Prismarr
  chrome (sidebar, theme) instead of the default Symfony exception page.
  Upstream exception message is never exposed — only the status code, a
  friendly French title, and a CTA back to home.
- Password show/hide toggle in the setup wizard (admin step + qBittorrent
  download step) for users typing long API keys on small screens.
- `/api/health` now returns `{status, db, timestamp}` (ISO 8601) so
  external monitoring dashboards can track liveness over time.
- OCI image labels on the production Docker image (title, description,
  licenses, source, url, documentation, vendor) — surfaced on Docker Hub
  and via `docker inspect`.
- Smoke test coverage on every controller (`ControllersSmokeTest` with
  DataProvider over 9 media routes + login + health).
- Initial Prismarr application forked from IH-Argos (April 2026).
- FrankenPHP 1.3.6 single-container deployment with s6-overlay supervising
  the web server and the Symfony Messenger worker.
- Zero-config SQLite database, automatic secret generation on first boot.
- 7-step setup wizard: welcome → admin → TMDb → managers (Radarr + Sonarr) →
  indexers (Prowlarr + Jellyseerr) → downloads (qBittorrent + Gluetun) → finish.
- Media integrations:
  - Radarr (~169 client methods, 143 routes, 37 templates)
  - Sonarr (~160 client methods, 142 routes, 30 templates)
  - Prowlarr (~70 methods, 15 templates)
  - Jellyseerr (~60 methods, 13 templates)
  - qBittorrent (~45 methods, VPN card, session card, magnet + torrent file upload)
  - TMDb discovery page (hero, recommendations, 7 scrollable sections, watchlist)
  - Integrated calendar with month grid, tooltips, per-type colours
- Hotkey global search (Ctrl+K) with debounced local + online (TMDb / TheTVDB) results.
- Quick-add modal (movies via Radarr, series via Sonarr) accessible from every page.
- Dynamic CSP header built from configured service URLs.
- Login rate-limiter (5 attempts per IP + username / 15 minutes, 25 per IP globally).
- Trusted proxies support for deployments behind Traefik / nginx / Caddy / Cloudflare Tunnel.
- `/api/health` endpoint (JSON status + DB ping) for Docker healthcheck.
- Profiler access guard that returns 403 for non-RFC1918 clients when `APP_ENV=dev`.
- Admin recovery command: `php bin/console app:user:reset-password <email>`.
- Dynamic welcome homepage: auto-redirect to the first configured service.
- Doctrine migrations baseline (replaces `doctrine:schema:create`).
- PHPUnit test suite (~100 tests, services + subscribers + controllers + entities + Twig extensions).
- `make check` target: PHP lint + Twig lint + full PHPUnit suite.

### Security
- `always_use_default_target_path: true` on the main firewall — Symfony
  no longer redirects to whatever URL was in the session at login time
  (typically an expired AJAX endpoint such as `/api/health/services`).
  Users always land on the home route and honour their `display_home_page`
  preference.
- `^/api/health/services` is gated behind `ROLE_USER` (the exact
  `^/api/health$` Docker healthcheck remains public). Previously the
  whole `^/api/health` prefix was public, which meant an unauthenticated
  client could enumerate which external services an instance had
  configured.
- CSRF tokens are now required on every new admin action
  (`/admin/settings/import`, `/admin/settings/reset-display`) and every
  profile mutation (`/profil` save, `/profil/avatar` upload and delete).
- Avatar uploads validate MIME type against an allow-list, cap size at
  2 MB, and the serving route uses a strict filename regex
  (`\d+\.(jpg|png|webp|gif)`) to prevent path traversal.
- Settings export and import strip any key containing `api_key`,
  `password` or `secret`, so a shared config file cannot accidentally
  leak credentials and a hostile import file cannot inject them either.
- Container runs as non-root (`www-data` via `s6-setuidgid`); s6-overlay keeps
  PID 1 as root only as required.
- SSRF protection on user-provided URLs: protocol whitelist, cloud-metadata
  blocklist, `CURLOPT_REDIR_PROTOCOLS`.
- XSS dead-code removal (`extra_fields|raw` removed from schema modal).
- CSRF tokens on every sensitive form.
- `#[IsGranted('ROLE_ADMIN')]` on the six controllers that manage external
  services (Radarr, Sonarr, Prowlarr, Jellyseerr, qBittorrent, Media).
- Login throttling via `symfony/rate-limiter`.
- Dev-only `_profiler` / `_wdt` routes return 403 for remote clients.
- `Strict-Transport-Security` and `Permissions-Policy` response headers
  emitted by Caddy (HSTS no-op on plain HTTP but picked up by an HTTPS
  reverse proxy that forwards response headers).
- Session cookie marked `httponly` explicitly (in addition to
  `secure: auto` + `samesite: lax`).
- `QBittorrentClient` now implements `ResetInterface`, preventing the
  qBittorrent session cookie from leaking across users when the
  FrankenPHP worker is re-used.

### Changed
- Display preferences are now effective — theme colour drives a dynamic
  `--tblr-primary` / `--tblr-primary-rgb` CSS variable (declared after
  the Tabler stylesheet so Tabler's default `:root` no longer wins the
  cascade), UI density toggles `body.density-compact` /
  `body.density-comfortable`, toasts toggles `body.toasts-off`, qBit
  auto-refresh reads its interval from the preference (setting it to
  0 disables polling entirely), and the `last` home option reads a
  rotating `prismarr_last_route` HttpOnly cookie to resume where the
  user left off.
- `display_default_view` (default Radarr / Sonarr view) has been
  dropped from the preferences — wiring it to the client-side view
  switcher was too invasive for v1.0 and the feature is deferred to a
  later release. The key is no longer written; any stale value already
  stored in a user's DB is simply ignored.
- 27 media templates had their browser tab title cleaned up: the
  trailing `— IH-ARGOS` is gone, the tab now just reads `Prismarr`.
- Sidebar wording: `Films` → `Radarr`, `Séries` → `Sonarr` (matches the
  underlying service and improves the collapsed sidebar tooltips).
  Calendar moved up in the sidebar order (right after Discovery).
- The topbar has been rebuilt into a three-column layout (title /
  large centred search / actions). The user dropdown now links to the
  new profile page and, for admins, to the settings page.
- Flash messages no longer auto-hide, so a long save confirmation or
  error is not missed when it happens during a Turbo navigation.
- Trending / spotlight / Jellyseerr links on the dashboard now open
  the in-page discovery modal (`/decouverte?detail=type/id`) instead
  of hitting the JSON resolver endpoint.
- Home route (`/`) now resolves to the admin's `display_home_page`
  preference (dashboard by default), instead of always falling through
  to the first configured service. The legacy fallback chain
  (tmdb → radarr → sonarr → qbit → welcome) still kicks in when the
  preferred target isn't configured.
- Gluetun HTTP client timeout raised from 4 s to 8 s (connect 2 s → 3 s) —
  the previous values were too aggressive on slow VPN handshakes.
- Migrated from a multi-container stack (PHP-FPM + nginx + Redis) to a single
  FrankenPHP container with filesystem cache and sessions.
- Retired `api-platform/core` and `lexik/jwt-authentication-bundle` — unused.
- Multi-stage-like Dockerfile: `.build-deps` purged after PHP extensions compile.
  `git` and `zip` also moved into `.build-deps` and purged after `composer install`.
- Composer version pinned (`composer:2` → `composer:2.8`) to avoid drift
  across rebuilds.
- Final image trimmed from 577 MB to 282 MB, then another ~10 MB after
  purging the Composer build-time deps.
- Settings live in the `setting` DB table, not in `.env.local` — managed by
  the wizard, persistent across container recreations.
- Home page chooses the first configured service (TMDb → Radarr → Sonarr → qBit
  → welcome fallback) instead of hardcoding `/decouverte`.
- Sidebar "Paramètres" link moved to the footer area next to logout (admin-only).
- "Modifier la configuration" banner button points to `/admin/settings` now
  rather than replaying the setup wizard.
- Session files moved from `var/sessions/` to `var/data/sessions/` so they
  persist inside the one Docker volume mounted in production and survive
  `docker compose up -d --force-recreate`.
- Gluetun HTTP timeout bumped from 4 s to 8 s (connect 2 s → 3 s) — the
  previous values were too aggressive on slow VPN handshakes.

### Fixed
- Dashboard mini-calendar no longer drops the upcoming events of the
  last displayed days. The earlier 8-item cap was applied globally and
  silently truncated the week; events are now limited per day with a
  clickable "+N more" link that deep-links into the calendar day view.
  Today's morning episodes also stop being misclassified as "past".
- Dashboard and calendar hovers no longer get stuck in a highlighted
  state after a tap on touch devices — hover rules are now wrapped in
  `@media (hover: hover) and (pointer: fine)`.
- The trending / recent tiles on the dashboard now open the discovery
  modal correctly (the previous link pointed at the JSON resolver
  endpoint, which did nothing visible for the user).
- qBittorrent client cURL calls now set `CURLOPT_NOSIGNAL=1` and an
  explicit 3 s connect timeout on the four entry points
  (login / getRaw / request / post). Without `NOSIGNAL`, libcurl falls
  back to `SIGALRM` for DNS resolution — a signal PHP masks — leaving
  DNS lookups stuck for 30+ seconds whenever qBittorrent is unreachable
  and producing a `FatalError` on Alpine PHP. Calls are now capped at
  ~11 s total regardless of the backing service's state.
- The browser-side qBittorrent summary poll now uses an exponential
  backoff (15 s → 30 s → 60 s → 120 s cap) on failure, resetting to the
  base interval on success. Previously a 1 s retry loop hammered the
  endpoint whenever the NAS or qBit was down.
- Dashboard "Upcoming releases" widget now shows only each movie's next
  future release date (rather than surfacing items whose digital or
  physical release was weeks in the past).
- Pending Jellyseerr requests on the dashboard now display the real
  title/year by enriching each request with a cached TMDb lookup,
  instead of showing raw "TMDb #&lt;id&gt;" placeholders.
- Media clients (Radarr, Sonarr, Prowlarr, Jellyseerr, qBittorrent, TMDb,
  Gluetun) implement `ResetInterface` so FrankenPHP worker instances
  reload the API key/URL between requests. Previously, an admin updating
  a service via `/admin/settings` had to wait for the worker to recycle
  (10–30 min) before the new value was picked up.
- `AdminSettings::save()` also clears `cache.app` so stale TMDb responses
  aren't served after a key change.
- `SetupController::guardAdminExists()` now returns `?RedirectResponse`
  and every call site uses the return value — previously the redirect
  was issued but the method kept running, potentially double-rendering
  the wizard step.
- `GluetunClient::reset()` now also zeroes the three cache timestamps
  (`publicIpCacheAt`, `statusCacheAt`, `portCacheAt`); previously reset
  would keep stale entries alive for the rest of the TTL.

### Contributor

- `CONTRIBUTING.md` adds a six-category "Definition of Done" checklist and
  five non-negotiable golden rules. `make check` must be green before every commit.
- New `tests/AbstractWebTestCase` base class boots a real kernel with an
  isolated SQLite file, seeds an admin + the `setup_completed` flag, and
  logs in the admin — foundation for functional tests that need a live
  request/response cycle.
- `make test` now passes `-e APP_ENV=test` to `docker exec`; previously
  the container's `APP_ENV=dev` was overriding the `APP_ENV` directive
  declared in `phpunit.dist.xml`.

## Template for future versions

<!-- Copy this block above [Unreleased] when cutting a release. -->

<!--
## [X.Y.Z] — YYYY-MM-DD

### Added
### Changed
### Deprecated
### Removed
### Fixed
### Security
### Contributor

[X.Y.Z]: https://github.com/joshuabv2005/prismarr/compare/vPREV...vX.Y.Z
-->
