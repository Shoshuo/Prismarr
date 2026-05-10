# Changelog

All notable changes to Prismarr are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Multi-instance Radarr and Sonarr** (issue [#21](https://github.com/Shoshuo/Prismarr/issues/21)). The biggest change of this release. Until now Prismarr supported one Radarr and one Sonarr; you can now configure as many instances as you need (e.g. Radarr 1080p + Radarr 4K + Radarr Anime, same idea for Sonarr) and each is reachable as a first-class citizen of the UI. Driven by a new `service_instance` table seeded automatically from the legacy `radarr_url/api_key` / `sonarr_url/api_key` settings on first boot, so a `docker compose pull` is all you need to upgrade. Each instance carries its own URL, API key, name, slug, position and enabled flag.
- **`/admin/settings` admin instance manager.** New CRUD UI to add, rename, reorder, enable/disable, set default and delete Radarr/Sonarr instances without leaving Prismarr. Each row gets an inline "Test connection" button surfacing the same diagnostic categories as the setup wizard. CSRF tokens scoped per instance and per action; ROLE_ADMIN required.
- **Dynamic sidebar.** The sidebar adapts to the number of enabled instances per service: 1 instance keeps a flat link, 2-3 instances render a horizontal pill group, 4+ collapse into a dropdown. The active instance is highlighted across navigation so it's always clear which one you're driving.
- **Slug-aware routing.** Every Radarr/Sonarr admin route, every media route (films, series, calendar) and the bulk actions are now rooted under `/medias/{slug}/...`. A new `MultiInstanceBinderSubscriber` reads the slug at request time and binds the right instance onto the autowired client, so the ~280 controller methods didn't have to be rewired by hand. Unknown slugs surface a clean 404 instead of silently hitting the default instance.
- **Per-instance health circuit breaker.** `ServiceHealthCache` is now keyed by `(service, instanceSlug)` so a Radarr 4K outage doesn't silence Radarr 1080p for the TTL window. Each instance has its own outage cache entry.
- **Cross-instance aggregation across the whole UI** (Phase D). The dashboard widgets (hero spotlight, recent additions, mini-calendar, Jellyseerr requests, watchlist), the calendar (`/calendrier` + `.ics` export), the Ctrl+K global search, the qBittorrent badge resolver and the TMDb discovery enrichment now read from every enabled Radarr/Sonarr instance instead of just the default. Items present on multiple instances are deduped by `tmdbId` (movies) or `tvdbId` then `tmdbId` (series) so a film mirrored across Radarr 1080p and Radarr 4K appears once. iCal `UID` keys are rooted on `tmdbId` / `tvdbId` so the same calendar entry remains stable across re-exports even when the underlying Radarr/Sonarr internal id differs between instances.
- **Quick-Add target picker** (Phase E). Adding a film or a series via Ctrl+K → "Add" or via the discovery hero now shows a per-instance picker when more than one Radarr/Sonarr is configured, with each enabled instance listed alongside an indicator when the title is already owned. The default instance is preselected and the form is hidden only when *every* enabled instance already owns the title — users with two Radarrs can still push a film to the second one even if the first already has it. The new `/decouverte/resolve/{type}/{id}` payload exposes `instances` (every owner, with `slug` / `name` / `id` / `status`) and `candidates` (every enabled instance, with `is_default`) for the picker to consume.
- **Settings export and import are multi-instance aware** (export format `v2`). The JSON dump now includes a top-level `instances` array (per-type, with `slug` / `name` / `url` / `enabled` / `is_default` / `position`, no API keys) so a backup taken on a 2× Radarr setup can be re-imported without dropping the second instance. Import detects the version, restores the instance topology then layers the flat `setting` rows on top. v1 backups are still accepted — they're treated as a single-instance config and routed through the default instance.
- **Expandable shelves in the Radarr and Sonarr shelf views**, allowing shelves to wrap into multi-row grids while keeping the collapsed behavior. (Community contribution, [PR #29](https://github.com/Shoshuo/Prismarr/pull/29).)

### Changed
- **Languages section in `/admin/settings` redesigned for multi-instance.** The Languages card now renders one dense per-service block (Radarr / Sonarr / Prowlarr / Seerr) with a coloured dot header and a per-instance row (UI language + Movie info language selectors). Each Radarr/Sonarr instance is fetched and saved independently, so an unreachable instance only flags itself as `unreachable` instead of greying the whole card. The save endpoint takes the new array form `radarr_ui[<slug>]` / `radarr_info[<slug>]` / `sonarr_ui[<slug>]` and partial failures are reported by instance name. Prowlarr and Seerr remain mono-instance.
- **Quick-Add modal (Ctrl+K) follows the page's instance type.** Adding a movie from a Sonarr-4K page still routes to the default Radarr (and vice-versa) — Prismarr exposes new `CURRENT_RADARR_SLUG` / `CURRENT_SONARR_SLUG` JS globals that resolve to the current page's slug only when the page is type-specific, falling back to the default instance otherwise.
- **Bulk-edit, library import and add-from-collection follow the current instance.** Pre-loading quality-profiles / root-folders for a bulk edit on Sonarr 4K now hits Sonarr 4K, not Sonarr default. Same for the per-instance Sonarr library import flow and the add-button on a Radarr collection page.
- **Sonarr manual import is now reliable.** The "Import N files" button under a Sonarr download queue used to silently do nothing on most blocked items: the frontend forged a `ManualImport` payload by hand and Sonarr v4 needs `episodeIds` + `releaseGroup` + enriched quality which can't be reconstructed from the queue alone. The new flow calls `GET /api/v3/manualimport?downloadId=<hash>` so Sonarr resolves files in the original grab context (matching files to episodes even when filenames lack a `SxxEyy` marker), dedupes queue items sharing a downloadId (a season torrent is one item per episode but one shared downloadId — used to cause N×N duplicate rejections), then re-posts the enriched payload. The banner now reports `Imported X / N, Y skipped — 5× Invalid season or episode` with grouped reasons instead of a single error string.
- **Calendar uses Sonarr local broadcast `airDate`** instead of the UTC instant `airDateUtc` (issue [#26](https://github.com/Shoshuo/Prismarr/issues/26)). A Saturday 21:00 GMT premiere lands on Saturday for every viewer regardless of TZ, matching what TVDB/Trakt/Plex display. Same fix on the `series_missing` and `series_cutoff` columns, which also drop the hardcoded `Europe/Paris` filter.
- **`TorrentResolverService` matches across all known titles.** The resolver behind the qBit "Open in Radarr/Sonarr" badges used to score torrents only against `title`, missing French installs where Radarr stores `Aventures croisées` while the torrent is named `Swapped`. Scoring now considers `title` + `originalTitle` + every `alternateTitles[].title`, deduped. Accent folding switched from `iconv ASCII//TRANSLIT//IGNORE` to `intl Transliterator 'Any-Latin; Latin-ASCII'` so `La traversée` collapses to `la traversee` cleanly (the Alpine/musl build of iconv inserted a stray space, e.g. `La travers ee`, breaking matches).
- **Topbar health badge surfaces every instance.** The `/api/health/services` endpoint and the badge dropdown now expose one row per enabled instance instead of a single Radarr/Sonarr aggregate, so an outage on a single instance is immediately visible.
- **Queue card on the series page is collapsible.** Click the header to fold the table, click again to expand. Mirrors the calendar card pattern that was already collapsible.

### Fixed
- **10 sub-pages of `/admin/settings/{radarr,sonarr}/...` had buttons silently doing nothing in multi-instance.** Per-row delete on indexers, download clients, root folders, exclusions, import lists and notifications + test on notifications/indexers used hardcoded URLs like `/radarr/indexeurs/<id>/supprimer` that no longer exist since slug-aware routing landed (every route is now `/medias/{slug}/...`). All 10 sub-pages are fixed and migrated to Turbo-safe JS (no `const/let/async/await` that would redeclare on Turbo navigation cycles).
- **Sonarr indexer and notification test/delete buttons unbroken.** Four `fetch()` URLs leaked the Twig concatenation operator `~` into the JS string literal, producing a `SyntaxError` that killed the whole IIFE on script load (the Radarr equivalents were caught earlier; the Sonarr ones slipped through the same sweep).
- **Films detail modal "Rename file" preview** was calling the legacy `/radarr/renommer/{id}/propositions` endpoint and never received a payload. Now hits the slug-aware route that the rest of the modal already uses.
- **TMDb "My recommendations" surfaced biased results in multi-instance setups.** The seeds (8 most recently added movies + 8 most recently added series) were computed from the autowired client only — i.e. the default Radarr/Sonarr — so a user running Radarr 1080p alongside Radarr 4K saw recommendations driven by the default instance only. Seeds now iterate over every enabled instance per type, dedup by `tmdbId`, then keep the 8 most recently added across the union.
- **`/admin/settings` About widget library counts now aggregate across instances.** `Films: 1234` and `Séries: 567` used to count the default instance only. They now sum every enabled Radarr/Sonarr, deduped by `tmdbId` (movies) or `tvdbId` then `tmdbId` (series), so the same item mirrored across instances doesn't double-count. If every instance fails the count stays `—`.
- **Quick-Add modal slug context.** Adding a movie via Ctrl+K from a Sonarr page used to post to the Sonarr slug (or vice-versa) and trip a `MultiInstanceBinderSubscriber` 404. The modal now reads `CURRENT_RADARR_SLUG` / `CURRENT_SONARR_SLUG` per type so the action always lands on the right type's instance.
- **Home redirect 500 on `display_home_page = films` (or series, or any cached "last visited" pointing at a slug-aware route).** `redirectToRoute('app_media_films')` was being called without parameters; the route's `{slug}` requirement raised `MissingMandatoryParametersException` on every hit to `/`. The redirect helper now hydrates the slug from the default instance for `films` / `series` / last-visited cookies.
- **Sonarr `request()` blew up on bare-string responses** (`Return value of request() must be of type ?array, string returned`). Sonarr v4 occasionally answers notification test/delete with a JSON string `"OK"`; `json_decode` returned a string, the `?? []` coalesce did not catch it, and the typed return contract failed. Non-array decode results are now coerced to `[]`. Same pattern applied to RadarrClient.
- **`window.prismarrBytes` was undefined when `renderQueue()` ran on first paint** of the films page (`TypeError: window.prismarrBytes is not a function`). The function was defined in a deferred conditional `<script>` that loaded after the child template's `javascripts` block, so the first call from `setInterval(refreshQueue, 2000)` raced with the definition. Definition hoisted to the `<head>` script that already exposes the slug constants.
- **Queue count badge was illegible** on the films and series queue cards (grey `7` on indigo). The parent `.card-title` color cascade overrode Tabler's bg-primary text. Forced `text-white` on the badge.
- **Calendar entries one day off in TZ < UTC** (issue [#26](https://github.com/Shoshuo/Prismarr/issues/26)). See Changed → calendar uses Sonarr local broadcast `airDate`.
- **`TorrentResolverService` URLs lacked the slug prefix** (clicking a Radarr/Sonarr badge in qBit always 404'd in v1.1.0). Fixed alongside the multi-title and transliteration improvements above.

### Security
- **Strip secrets from upstream bodies before logging.** `RadarrClient::request()` and `SonarrClient::request()` now run `sanitizeLogBody()` on the response payload before adding it to the warning log: magnet links collapse to `[magnet]`, `apikey=...` query strings and `"apiKey": "..."` JSON fields are redacted, and the body is truncated to 200 characters. The full payload still feeds `extractApiErrorMessage` for the user-facing toast — only the log sink is sanitised.
- **Sanitised error responses on the films bulk endpoints.** `filmsBulkRefresh`, `filmsBulkSearch` and `filmQueueImport` used to bubble `$e->getMessage()` raw into the JSON 500 — leaking server paths or stack hints into the browser. They now route through `jsonClientError` which surfaces the upstream Radarr error message (already sanitised by `extractApiErrorMessage`) or falls back to a generic `media.api.network_error` label for parse-time exceptions. Full exception still hits the warning log for admin diagnosis.
- **`showPageBanner` XSS hardening.** Split into `showPageBanner` (text-safe via `textContent`, default for the 46 call sites that surface upstream-controlled strings like Radarr movie titles or Sonarr error reasons) and `showPageBannerHtml` (explicit `innerHTML` for the 11 call sites that intentionally inject a spinner SVG alongside an i18n template). Untrusted strings now render as plain text instead of being parsed as markup.
- **Escape instance names in three `innerHTML` sites.** The Quick-Add picker's "Already in" list (`renderAlreadyList`), the picker's `<select>` of candidates (`renderInstancePicker`) and the topbar health dropdown (`rowFor`) used to splice the user-controlled `ServiceInstance::name` directly into an `innerHTML` template. Self-XSS only today — `ROLE_ADMIN` is the sole writer of instance names — but a future split between `ROLE_USER` and `ROLE_ADMIN` would have promoted it to a cross-user XSS. A new `window.escHtml()` helper escapes `& < > " '` for every dynamic value spliced into those three templates.

### Tests
- 32 new unit tests covering the v1.1.0 multi-instance plumbing: `ServiceInstanceProvider` (22 cases), `MultiInstanceBinderSubscriber` (7), `ServiceHealthCache` instance-keyed entries (3). Also ~17 cases on `TorrentResolverService` (multi-title, transliteration, robustness against unavailable services) and on `SonarrClient::manualImportFromQueueItems` (downloadId preferred, dedup of items sharing a hash, skip rejected files, partial / full / nothing-imported branches).
- 4 new `TmdbControllerTest` cases pinning the Phase D+E contract: `/decouverte/resolve` returns one entry per owner (`instances`) plus the full candidate list (`candidates`) including a `is_default` flag, missing-from-library renders empty `instances` while still listing every candidate, series resolve matches by `tvdbId` first then falls back to `tmdbId`, and `/decouverte/mes-recommandations` dedups seeds across multiple Radarr instances. Functional smoke tests now seed a default `radarr-1` / `sonarr-1` instance in `AbstractWebTestCase` so the slug-aware media routes resolve.
- Suite is now 331 tests / 757 assertions, up from 273 / 565 at the end of v1.0.6.

### Migrations
- `migrations/Version20260503000000.php` (Big Bang) creates the new `service_instance` table, seeds the legacy `radarr_url` / `radarr_api_key` / `sonarr_url` / `sonarr_api_key` settings into a single default instance per service (`slug = radarr-1` / `slug = sonarr-1`, `is_default = true`, `enabled = true`), then drops the four settings rows. Transparent at upgrade time; user-visible behaviour is unchanged for single-instance setups. The migration is reversible (the down path restores the four settings from the default instance, but only one instance per service can be reified back into v1.0 settings).

## [1.0.6] - 2026-05-03

### Added
- **"Test connection" buttons in the setup wizard.** Each service step (TMDb, Radarr, Sonarr, Prowlarr, Jellyseerr, qBittorrent) now has an inline `Test connection` button next to its inputs. Result is shown as a small status badge (green "Connected", red category like "Wrong API key" / "Cannot reach service" / "Endpoint not found"). Non-blocking — users can still continue without testing. Categories are surfaced from `HealthService::diagnose()` so the same labels appear in `/admin/settings`.

### Security
- **SSRF guard on `HealthService::httpProbe()`.** The probe now hard-rejects any URL that isn't `http://` or `https://`, blocks the `169.254.0.0/16` link-local range used by AWS / GCP / Azure cloud-metadata endpoints, and pins cURL to `CURLPROTO_HTTP | CURLPROTO_HTTPS` for both the initial request and any redirect. RFC1918 LAN ranges (`10/8`, `172.16-31`, `192.168/16`) are intentionally still allowed because Prismarr legitimately needs to reach Radarr/Sonarr/Prowlarr/Jellyseerr/qBittorrent on private addresses. This closes a dormant blind-SSRF that wasn't exploitable in v1.0.5 (the only call site was admin-only `/admin/settings`) but would have become exploitable as soon as the new public `/setup/test/<service>` endpoint shipped.
- **Rate limiter on `/setup/test/<service>`.** 30 attempts per minute per (client IP × service), `sliding_window` policy. Neuters scripted port-scan attempts during the brief window where the wizard is publicly reachable (post-image-pull, pre-`setup_completed=1`).
- **Strict response envelope** on `/setup/test/<service>` — `{ok, category}` only, no echo of the URL probed, no echo of the API key submitted, no upstream response body. Headers force `Cache-Control: no-store, no-cache, private` and `X-Content-Type-Options: nosniff` so the response cannot be cached by an intermediate proxy.
- **Auth gate (`ROLE_USER`) on `/setup/test/<service>`.** The wizard step that hosts the test buttons (TMDb / Managers / Indexers / Downloads) only renders *after* the admin has been created at step 2 and auto-logged-in via `$security->login()`, so requiring `ROLE_USER` never blocks a legitimate flow. It does close the small window between image start and `setup_completed=1` where `/setup/*` is otherwise `PUBLIC_ACCESS`, eliminating the unauthenticated reachability of the probe endpoint entirely. Defense-in-depth on top of the existing CSRF token, rate limiter, service whitelist, and SSRF guard.
- 7 new PHPUnit tests covering the new endpoint (guard / CSRF / rate limit / strict payload / field whitelist) and the SSRF guard (file:// / gopher:// / dict:// / link-local IPs blocked, RFC1918 + public HTTPS allowed).

### Fixed
- **"Test connection" button is no longer rendered for Gluetun on `/admin/settings`.** `HealthService::probeFor()` has no Gluetun handler so the probe always came back as `unconfigured`, which made the button look broken even when Gluetun was correctly set up. The button is hidden until/unless we add a real Gluetun probe.
- **Locale-aware byte units** (issue [#4](https://github.com/Shoshuo/Prismarr/issues/4)). All filesize and transfer-rate displays now follow the active UI locale: English renders `GB / MB / KB / B` and `MB/s`, French keeps `Go / Mo / Ko / o` and `Mo/s`. Previously the FR abbreviations were hardcoded everywhere, including in the EN UI. Implemented as two new Twig filters (`prismarr_bytes`, `prismarr_speed`) and a global JS helper (`window.prismarrBytes`) so server- and client-rendered sizes stay consistent. Covered: root folders (Radarr / Sonarr), backups, Jellyseerr cache stats, qBittorrent dashboard totals, film/series detail cards, the post-download toast notification, and the qBittorrent torrent upload size-limit error message.
- **Stop logging spurious `tabler.min.css.map` 404s.** The bundled Tabler CSS files referenced an upstream sourcemap (`/*# sourceMappingURL=tabler.min.css.map */`) that wasn't shipped with the image, so any browser opening DevTools triggered a 404 caught and logged as an exception by Symfony. The reference is now stripped from both `tabler.min.css` and `tabler-themes.min.css`. Cosmetic only — no impact on the rendered UI, just a quieter `docker logs prismarr` for users triaging real bugs.
- **Stop pinging unconfigured services** (issue [#9](https://github.com/Shoshuo/Prismarr/issues/9)). `HealthService::isHealthy()` now returns `null` (was: a stale `false`) when a service has no URL or API key in the DB, and skips the ping entirely — so users who only enabled a subset of the stack don't see "Jellyseerr ping failed" / "Gluetun ping failed" warnings every minute in their logs. The dashboard, topbar dropdown, and `/api/health/services` endpoint already handled `null` as "not configured", so the new state propagates without any UI breakage. The `ServiceNotConfiguredException` thrown by Radarr/Sonarr clients on missing config is also caught silently inside the dashboard's `safeFetch()` for the same reason. New `HealthService::isConfigured()` helper exposes the check to the dashboard so it can hide widgets bound to disabled services entirely (mini-calendar, Jellyseerr requests, TMDb trending, recent additions) instead of rendering empty cards.
- **qBittorrent behind a reverse proxy** (issue [#10](https://github.com/Shoshuo/Prismarr/issues/10)). Empty username/password are now a legitimate configuration: when qBittorrent sits behind a proxy that injects authentication itself (qui, traefik forward auth, …) Prismarr treats the credentials as optional. `HealthService::isConfigured()` only requires the URL; `HealthService::probeFor()` falls back to a lightweight `GET /api/v2/app/version` (instead of `POST /auth/login` with an empty body, which qBit answers `Fails.`); `QBittorrentClient::login()` returns a sentinel SID that `getRaw()` / `postAction()` recognize and skip the `Cookie: SID=…` header for. The wizard step Downloads now displays an inline hint explaining the reverse-proxy setup, and `/admin/settings` exposes a "Clear" button next to the qBit user/password fields so a deliberate wipe bypasses the empty-value guard that protects the other credentials from a Firefox/Chrome silent strip. 5 new PHPUnit tests covering `isConfigured()` URL-only mode and `login()` sentinel behavior.

### Changed
- **Removed the "Coming soon" section in `/admin/settings` sidebar.** The disabled "Email notifications" and "Security · sessions" rows haven't been wired up to a real feature yet, and showing them as "v1.1" pollution every time the user opens settings adds noise without value. They will reappear when the corresponding features actually land.
- **Public roadmap link.** Added a "Roadmap" entry in the `/admin/settings` About page (next to Source / Bug / Docs) and on the Updates page (next to GitHub / Docker Hub), plus an explicit mention in the README's Project status section. Points to the public GitHub project at https://github.com/users/Shoshuo/projects/3 so users can see what's queued and follow progress without needing to dig through the issue list.
- **"Monitored only" filter and persistent state on films / series pages** (issue [#14](https://github.com/Shoshuo/Prismarr/issues/14)). Adds a "Monitored" pill to the existing status filter bar (next to All / Downloaded / Missing / Unmonitored) so users with a mix of monitored and unmonitored items can quickly narrow down to the ones actually being tracked. The active status filter is persisted in two layers: the URL (`/medias/films?filter=monitored`, `/medias/series?filter=continuing`, …) so refreshes keep the view and shared links land on the same filter, and localStorage so sidebar navigation back to `/medias/films` (without the URL parameter) restores the user's last choice. The URL takes precedence when present so shared links always override the local preference. Quality / genre / language / sort / search will follow alongside the v1.1.0 server-side pagination refactor.
- **Renamed "Jellyseerr" to "Seerr" in the UI** (issue [#2](https://github.com/Shoshuo/Prismarr/issues/2)). Overseerr and Jellyseerr were both archived in February 2026 and replaced by [Seerr](https://docs.seerr.dev/), a unified API-compatible fork. The wizard, sidebar, admin settings, dashboard and README now refer to "Seerr" instead. Internal identifiers (class names, route names, settings keys like `jellyseerr_url` / `jellyseerr_api_key`) are unchanged so existing installs aren't disrupted, and the API endpoints Prismarr calls all exist verbatim in Seerr's spec — pointing your config at a fresh Seerr container instead of the archived Jellyseerr one keeps everything working without any setting edit.
- **Single source of truth for the running version** (issue [#11](https://github.com/Shoshuo/Prismarr/issues/11)). The `/admin/settings` About card was reading a `PRISMARR_VERSION` env var that was never injected at build, so it always displayed the literal `1.0.0-dev` fallback while the Updates card on the same page showed the real version from `App\Service\AppVersion::VERSION`. The About card now reads from the same constant. Bumped at every release tag along with `CHANGELOG.md`. The boot banner in `init.sh` still respects the `PRISMARR_VERSION` env var, and the release workflow now passes `--build-arg PRISMARR_VERSION=$TAG_WITHOUT_V` so the banner displays the correct version on official images instead of `1.0.0-dev`.
- **Headroom for large libraries** (issue [#13](https://github.com/Shoshuo/Prismarr/issues/13) + duplicate). The `films` and `series` pages used to crash with `ERR_EMPTY_RESPONSE` (or a 500 / blank page) on libraries bigger than ~3,000–5,000 items, because the entire library is loaded in one shot then rendered in one Twig pass — easily blowing past the default 256 MB / 60 s ceiling. Four layers of fix: `php.ini` is bumped to **1024 MB / 120 s** as a sane default for medium-large homelabs; the `films` and `series` controllers also call `set_time_limit(120)` defensively; users with even bigger libraries can override both at runtime via the new `PHP_MEMORY_LIMIT` and `PHP_MAX_EXECUTION_TIME` env vars in `docker-compose.yml` (the init script writes them to `/usr/local/etc/php/conf.d/zz-runtime.ini` at boot, no image rebuild needed); and a new `FatalErrorHandlerSubscriber`, registered very early in `public/index.php` so it runs before Symfony's own error handler, catches `E_ERROR` (out-of-memory or max-execution-time) at PHP shutdown and emits a self-contained 503 HTML page that explains what happened and how to bump the limits, instead of letting the connection die mid-render. **The proper fix — server-side pagination so the page never loads more than ~100 items at once — is deferred to v1.1.0**: it's a substantial refactor of the films / series templates (1900+ lines each) plus the JS view-switching, and shipping it in 1.0.6 alongside the other fixes would risk regressions. The bumped limits cover libraries up to ~50,000 items in practice; users with bigger collections set `PHP_MEMORY_LIMIT=2048M` (or higher) in their compose.
- **Honor the `TZ` env var** (issue [#12](https://github.com/Shoshuo/Prismarr/issues/12)). The image used to ship `tzdata`-less and the `php.ini` had `date.timezone = Europe/Paris` hardcoded, so `TZ=Pacific/Honolulu` (or any other zone) in your `docker-compose.yml` was ignored at every layer: `date` inside the container, PHP's date helpers, the `/admin/settings` server time line. Now `tzdata` is bundled in the image, `php.ini` defaults to `UTC`, and the init script reads `$TZ` at boot to (a) symlink `/etc/localtime` to the right zone file, (b) write `/etc/timezone`, and (c) drop a `/usr/local/etc/php/conf.d/zz-tz.ini` that overrides PHP's default. Invalid or missing `$TZ` falls back to UTC instead of pretending everyone is in Paris. The boot banner shows the resolved zone so users can confirm at a glance.

## [1.0.5] - 2026-04-26

### Security

- **CRITICAL — credential leak via /setup/* after the wizard is completed.** The setup wizard pages (`/setup/tmdb`, `/setup/managers`, `/setup/indexers`, `/setup/downloads`) were marked `PUBLIC_ACCESS` to allow first-time install without login, and stayed reachable even after `setup_completed=1`. They pre-rendered the values of every saved API key / password in plain `<input type="text" value="...">` for the "Back" button UX, so any unauthenticated client able to reach the Prismarr port could `curl /setup/tmdb` and harvest the stored TMDb / Radarr / Sonarr / Prowlarr / Jellyseerr / Gluetun API keys plus the qBittorrent password. Fixed with two layers of defense:
  1. `SetupController::guardSetupNotCompleted()` — every wizard step (`tmdb` / `managers` / `indexers` / `downloads` / `finish`) now redirects to the home page when `setup_completed=1`. Re-configuration is only available via the auth-protected `/admin/settings` (ROLE_ADMIN).
  2. `SetupController::prefill()` — values whose key ends with `_api_key`, `_password`, `_secret` or `_token` are NEVER copied from the DB into the wizard render. Even if the redirect ever gets bypassed, the HTML emitted by the wizard cannot contain the secret. Trade-off: navigating "Back" through the wizard during the initial install no longer pre-fills these fields, the user has to re-paste them. This is acceptable on a one-time install flow.
- 6 new PHPUnit tests covering both layers (216 tests / 448 assertions total).
- **Action required for users running v1.0.0 - v1.0.4:** rotate every API key configured in Prismarr (TMDb, Radarr, Sonarr, Prowlarr, Jellyseerr, Gluetun) and the qBittorrent password, then upgrade to 1.0.5 immediately. Even if your Prismarr instance is on a private LAN, anyone with network access (housemates, guests, smart-home devices, exposed reverse proxy) could have read these values.

## [1.0.4] - 2026-04-26

### Added
- **Language picker on the setup wizard's first screen.** Non-anglophone users no longer have to read the wizard in English just to find the language setting six steps later. The picker writes to a session key (`_locale`) read by `LocaleSubscriber` with priority just below the URL `?_locale=` override and above the DB-backed `display_language` preference - which is how it works during setup, where the DB has no setting yet. Once setup is complete, the admin's `display_language` takes over and the session value is no longer consulted.
- **Updates / changelog page in `/admin/settings`** (similar to Sonarr / Radarr's "System -> Updates"). Shows the running version, the latest GitHub release, and the last 15 release notes inline with their published date. A small orange badge appears in the settings nav when a newer version is available. Release notes are fetched from `api.github.com/repos/Shoshuo/Prismarr/releases` with a 1-hour cache and a hard 8 s connect / 4 s total timeout - if GitHub is unreachable the page degrades gracefully and just shows the current version. Powered by the new `AppVersion` service (implements `ResetInterface` for FrankenPHP worker safety).

### Changed
- `AppVersion::VERSION = '1.0.4'` is now the source of truth for the running build.

## [1.0.3] - 2026-04-26

### Added
- API client error context - every Radarr / Sonarr / Prowlarr / Jellyseerr / qBittorrent client now exposes a `getLastError()` method that returns the HTTP code, method, path and **the actual error message extracted from the upstream response body** (Radarr's `errorMessage`, Symfony validation errors, etc.). Reset between worker requests via `ResetInterface`.
- **Circuit breaker on every API client** - once a service times out or refuses connection during a request, the same client short-circuits all subsequent calls in the same request and returns `null` instantly instead of letting them stack up. Reset between worker requests via `ResetInterface`. Prevents `max_execution_time exceeded` fatals when an upstream service goes down.
- **Cross-request "service down" cache** - when an API client hits a network timeout (curl error, no HTTP response at all), it persists "service X is down" in the filesystem cache pool with a 30 s TTL. Subsequent page loads check this cache first and short-circuit instantly (0 ms) instead of paying another 4 s timeout. After 30 s the cache expires and the next page load tries once. On any successful response the cache is cleared. Without this, every navigation paid the 4 s connect timeout because FrankenPHP workers reset the in-process circuit breaker between requests - making any LAN service outage feel like an app-wide freeze.

### Changed
- LAN-only API clients (Radarr / Sonarr / Prowlarr / Jellyseerr / qBittorrent) now use a tighter timeout: 2 s connect / 4 s total (was 10 s) and `CURLOPT_NOSIGNAL=1`. Combined with the circuit breaker, a downed service caps page load at ~4 s instead of timing out PHP after 30 s. Internet-facing clients (TMDb, Gluetun) keep their longer timeouts (8 s / 15 s).

### Fixed
- Mutating actions in the Radarr / Sonarr / Prowlarr / Jellyseerr / qBittorrent UI now surface the upstream error in a flash message instead of redirecting silently. Users see exactly which API call failed, the HTTP code returned and the original error message - no more "I deleted a quality profile and nothing happened, did it work?" mystery. JSON endpoints also return a structured `{error, http_code, service}` payload on failure.

## [1.0.2] - 2026-04-26

### Fixed
- Production Docker image now runs `php bin/console asset-map:compile` at
  build time so that the hashed CSS/JS files under `public/assets/` are
  actually present. Previous v1.0.0 / v1.0.1 images shipped without
  compiled assets: under `APP_ENV=prod` (the default for the published
  image) every request to `/assets/styles/app-XXXX.css`,
  `/assets/app-XXXX.js`, etc. fell through to the framework error page,
  which Firefox / Chrome rejected with `NS_ERROR_CORRUPTED_CONTENT` and
  "blocked due to MIME type (text/html)" because of `X-Content-Type-Options:
  nosniff`. The whole UI rendered unstyled. The dev compose
  (`APP_ENV=dev`) served assets dynamically via AssetMapper, which is why
  the bug was invisible during local development and only surfaced once
  someone ran the published image in production.

## [1.0.1] - 2026-04-26

### Fixed
- Container init script (`docker/frankenphp/init.sh`) now performs a
  recursive `chown www-data:www-data` on `var/` after the Doctrine
  migrations step. Migrations run in root context (PID 1 / s6 init), and
  Symfony pre-creates the Doctrine parser cache pools under
  `var/cache/prod/pools/system/...` as root while parsing the migration
  query. Once frankenphp and messenger-worker drop to `www-data`, they
  could not write back into those pools and every HTTP request spammed
  the logs with `Permission denied` warnings on
  `Doctrine\ORM\Query\ParserResult`. The catch-all chown fixes that;
  no functional impact for existing v1.0.0 installs but `:latest`,
  `:1`, `:1.0` will now point at this clean image.

## [1.0.0] - 2026-04-26

First public release. Prismarr is a single-container, self-hosted dashboard
that brings qBittorrent, Radarr, Sonarr, Prowlarr, Jellyseerr and TMDb
together behind one Symfony 8 / FrankenPHP UI. Everything below was built
between April 18 and April 26, 2026, on top of the IH-Argos fork.

### Added
- **Animated README showcase** - looped GIF carousel on top of the README cycling
  through seven UI screenshots (Dashboard, Discovery, Calendar, Movies, Series
  detail, Downloads, Settings) at 3 s per slide, with the same screenshots also
  available as static images inside a collapsible `<details>` block.
- **Status badges in the README** - latest release, CI status, Docker Hub pulls,
  image size, GitHub stars and last-commit date, alongside the existing stack
  badges (license, PHP, Symfony, FrankenPHP, SQLite).
- **New README sections** for the v1.0 public release: "Project status" (solo-dev
  disclaimer plus an explicit call for feature requests, bug reports, code
  reviews, UI critiques, design ideas and translations), "Why Prismarr?" (a
  short comparison against Organizr, Heimdall, Homer, Homepage, Homarr,
  Jellyseerr and the raw Servarr UIs), "FAQ" (six entries: PHP / Symfony, ARM
  / Raspberry Pi, internet requirements, reverse proxy, API-key storage,
  backups, third-party translations), and "Star history".
- **"Note on AI usage" disclosure** at the bottom of the README - rendered as a
  blockquote (greyed-out) so it stays discreet, listing primary uses (i18n
  translation, log / JS debugging, API endpoint cataloguing, code audits, SVG
  icons & illustrations) and secondary uses (PHPUnit test debugging, mobile
  responsive design, security review, doc translation, local commit messages,
  single-container Docker design) of Claude Code as a support tool, with a
  reminder that every line was reviewed and signed off by a human and that
  `make check` had to be green before any commit.
- **Categorised connection test** in `/admin/settings` - the "Test connection"
  button returns a structured diagnosis (`ok / unconfigured / network /
  auth / forbidden / not_found / server_error / unknown`) with the HTTP status
  code included. The result is shown with an i18n message matching the category,
  so admins know whether the problem is a wrong URL, a bad API key, or a
  firewall blocking the request.
- **Live form override for connection test** - the test button sends the
  current form values (URL, API key / password) as overrides instead of always
  reading from the database. The admin can type new credentials and test them
  before saving. A server-side allowlist validates which keys may be overridden
  per service.
- **Unified sidebar-visibility section** in `/admin/settings → Display` -
  service toggles and internal-feature toggles (Calendar, Dashboard) are
  grouped under a single "Sidebar visibility" sub-section with an auto-fill
  two-column grid, freeing the service cards to focus solely on connection
  status.
- **Profile page** at `/profil` - edit display name and password, upload an
  avatar (JPG / PNG / WebP / GIF, 2 MB max). Avatars live in the
  `var/data/avatars/` volume so they survive container recreations. The
  page also shows a small personal stats block (watchlist count, member
  since, role) and the four most recent watchlist additions.
- **Services health badge** in the topbar - a coloured dot (green = all
  up, orange = partial, red = none) with a dropdown listing the live
  state of the six services. Refreshes every 60 s. Backed by a new
  `GET /api/health/services` endpoint (ROLE_USER - the service list
  leaks part of the configuration, so it is no longer public).
- **Calendar week and day views** - toggle between Month, Week and Day
  at the top of `/calendrier`. State is persisted in `localStorage` and
  also reflected in the URL (`?view=…&date=YYYY-MM-DD`) so widgets on
  the dashboard can deep-link into a specific day. Past days are
  dimmed; events on past days are greyscaled and struck through.
- **iCal export** at `/calendrier.ics` - downloads an RFC 5545
  calendar with stable UIDs (movie and TV episode releases, each typed
  cinema / digital / physical / series). Existing calendar clients
  update events in place rather than duplicating them.
- **Backup and import in `/admin/settings`** - export non-sensitive
  settings as JSON, reimport them with a CSRF-protected form (version
  check, 64 KB max, scalar-only values). Keys matching `api_key`,
  `password` or `secret` are never exported and are always filtered
  out on import, even if a malicious file tries to smuggle them in.
- **About section in `/admin/settings`** - runtime information
  (Prismarr, Symfony, PHP, SAPI, environment, database path and size,
  server timezone), three counters (users / movies / series - tolerant
  of Radarr / Sonarr being offline), and links to the project sources
  and issue tracker.
- **Reset display preferences** button in `/admin/settings` - clears
  every `display_*` key so reading them falls back to the defaults.
- **Twig filters** `|prismarr_date`, `|prismarr_time` and
  `|prismarr_datetime` - apply the admin's chosen timezone, date
  format (FR / US / ISO) and time format (24 h / 12 h) to any
  `DateTimeInterface`, ISO 8601 string or timestamp.
- **Global search improvements** - ARIA combobox, arrow-key
  navigation with visible highlight, an inline clear button, a
  recent-searches list stored in `localStorage` (shown when focusing
  an empty input), and Everything / Movies / Series filter pills.
  Results are now grouped (online discovery first, local library
  second).
- **Main dashboard** at `/tableau-de-bord` - the new default landing page
  for logged-in users. Aggregates seven widgets with graceful degradation
  when a service is offline: hero spotlight (random library pick with
  fanart, genres, rating, quality and a CTA), upcoming releases
  (seven-day mini-calendar), pending Jellyseerr requests enriched with
  TMDb metadata, live health of the six services, personal watchlist,
  weekly TMDb trending, and most-recent library additions merged
  across movies and series.
- **Display preferences** in `/admin/settings` - nine typed options
  (home page, toasts, timezone, date/time format, theme colour,
  default Radarr/Sonarr view, qBit auto-refresh, UI density) stored
  as `display_*` keys. The admin page now uses tab navigation
  (Services / Display) with URL-hash + `sessionStorage` persistence so
  the admin stays on the same section across POST/Redirect/GET.
  Effective behaviour wiring for these preferences lands in a follow-up.
- **Collapsible sidebar** with a toggle button at the bottom: 4 rem
  icons-only width when collapsed, CSS-only tooltips on hover, state
  persisted in `localStorage` with FOUC-prevention in `<head>`.
- **Admin settings page** at `/admin/settings` - edit service URLs and API keys
  without replaying the setup wizard. Per-service "test connection" button,
  live status pill, show/hide toggle for each service in the sidebar, and
  show/hide toggle for internal features (Calendar). Two-column layout with
  sticky section nav, designed to host future preference sections.
- **Branded error pages** for 403/404/500/503 rendered with the Prismarr
  chrome (sidebar, theme) instead of the default Symfony exception page.
  Upstream exception message is never exposed - only the status code, a
  friendly French title, and a CTA back to home.
- Password show/hide toggle in the setup wizard (admin step + qBittorrent
  download step) for users typing long API keys on small screens.
- `/api/health` now returns `{status, db, timestamp}` (ISO 8601) so
  external monitoring dashboards can track liveness over time.
- OCI image labels on the production Docker image (title, description,
  licenses, source, url, documentation, vendor) - surfaced on Docker Hub
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
- **Admin credentials no longer wiped on partial saves** - browsers (Firefox,
  Chrome) strip the `value` attribute of `input[type=password]` fields on
  page render. Previously, any admin save action (e.g. changing the theme
  colour) would silently overwrite every API key and password in the database
  with an empty string, eventually causing qBittorrent to ban the Prismarr IP
  after repeated empty-password login attempts. `saveSubmitted()` now skips
  any field whose trimmed value is empty and whose name matches the sensitive
  key pattern (`password`, `api_key`, `secret`). A regression test
  (`testEmptyPasswordFieldsAreNotWiped`) is added.

- `always_use_default_target_path: true` on the main firewall - Symfony
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
- **README is now in English and is the sole published version** of the
  project README. The temporary French copy used during the v1.0 review pass
  has been removed; English is the source of truth for all public-facing
  documentation. Twig `<title>` separators were also migrated from em dash
  (`-`) to ASCII dash (`-`) for cleaner browser tab titles
  (`base.html.twig`, `security/login.html.twig`, all `setup/*.html.twig`).
- All user-visible strings in Twig templates (~50 hard-coded strings) and PHP
  controllers are now routed through the Symfony Translator. The EN and FR
  YAML files are in exact parity (4 188 keys each, zero duplicates, zero
  broken placeholders). ICU plural forms are used where count varies
  (`media.import.blocked_warning`). Flash messages, JSON API responses, and
  the `UniqueEntity` constraint on `User::email` are fully translated.
- Internal service messages (RadarrClient, SonarrClient, TorrentResolverService)
  are hardcoded in English - they surface only in server logs, never in the UI.
- English is now the default application locale (`default_locale: en`).
  French remains the first and complete translation. New installs default
  to `display_language: en` and `display_metadata_language: en-US`. Users
  who prefer French can switch via `/admin/settings → Languages`.
- The Discovery search block stays visible when a query returns zero
  results - it now shows a "no results" message instead of disappearing,
  so users know the search completed rather than silently failing.
- `/admin/settings → Display` no longer shows the language dropdowns
  (`display_language`, `display_metadata_language`) since they are already
  editable in the dedicated Languages section. The defaults are preserved
  internally so the Languages section can pre-select the current values.
- Series library now has a "Recently added" sort option, mirroring the one
  already present on the movies page. Sort is client-side using
  `data-added` (ISO 8601 from `s.addedAt`) so it works without an extra
  API call.

- Display preferences are now effective - theme colour drives a dynamic
  `--tblr-primary` / `--tblr-primary-rgb` CSS variable (declared after
  the Tabler stylesheet so Tabler's default `:root` no longer wins the
  cascade), UI density toggles `body.density-compact` /
  `body.density-comfortable`, toasts toggles `body.toasts-off`, qBit
  auto-refresh reads its interval from the preference (setting it to
  0 disables polling entirely), and the `last` home option reads a
  rotating `prismarr_last_route` HttpOnly cookie to resume where the
  user left off.
- `display_default_view` (default Radarr / Sonarr view) has been
  dropped from the preferences - wiring it to the client-side view
  switcher was too invasive for v1.0 and the feature is deferred to a
  later release. The key is no longer written; any stale value already
  stored in a user's DB is simply ignored.
- 27 media templates had their browser tab title cleaned up: the
  trailing `- IH-ARGOS` is gone, the tab now just reads `Prismarr`.
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
- Gluetun HTTP client timeout raised from 4 s to 8 s (connect 2 s → 3 s) -
  the previous values were too aggressive on slow VPN handshakes.
- Migrated from a multi-container stack (PHP-FPM + nginx + Redis) to a single
  FrankenPHP container with filesystem cache and sessions.
- Retired `api-platform/core` and `lexik/jwt-authentication-bundle` - unused.
- Multi-stage-like Dockerfile: `.build-deps` purged after PHP extensions compile.
  `git` and `zip` also moved into `.build-deps` and purged after `composer install`.
- Composer version pinned (`composer:2` → `composer:2.8`) to avoid drift
  across rebuilds.
- Final image trimmed from 577 MB to 282 MB, then another ~10 MB after
  purging the Composer build-time deps.
- Settings live in the `setting` DB table, not in `.env.local` - managed by
  the wizard, persistent across container recreations.
- Home page chooses the first configured service (TMDb → Radarr → Sonarr → qBit
  → welcome fallback) instead of hardcoding `/decouverte`.
- Sidebar "Paramètres" link moved to the footer area next to logout (admin-only).
- "Modifier la configuration" banner button points to `/admin/settings` now
  rather than replaying the setup wizard.
- Session files moved from `var/sessions/` to `var/data/sessions/` so they
  persist inside the one Docker volume mounted in production and survive
  `docker compose up -d --force-recreate`.
- Gluetun HTTP timeout bumped from 4 s to 8 s (connect 2 s → 3 s) - the
  previous values were too aggressive on slow VPN handshakes.

### Fixed
- TMDb client timeouts raised from 4 s connect / 10 s total to 8 s / 15 s,
  with `CURLOPT_NOSIGNAL=1` added. The 4 s budget could not absorb the
  occasional Docker embedded-DNS latency spike (`127.0.0.11`) plus the
  IPv6-then-IPv4 connect fallback inside the container, leading to
  spurious "Resolving timed out" errors on TMDb calls even with a healthy
  internet connection. Same pattern as the GluetunClient bump.
- The Jellyseerr language dropdown in `/admin/settings → Languages` now
  reads and writes the global app locale (`GET/POST /api/v1/settings/main`)
  instead of the per-user admin setting (`/api/v1/user/1/settings/main`).
  The dropdown was showing "English" while Jellyseerr's own Settings →
  General → Display Language correctly showed "Français". On save,
  Prismarr now pushes a minimal `{locale}` payload to the global endpoint
  (a full payload triggers HTTP 400 because `apiKey` is read-only there)
  and also updates user 1's per-user setting (which drives the language
  of TMDb metadata returned by Jellyseerr API calls made via the admin
  API key).
- Dashboard mini-calendar no longer drops the upcoming events of the
  last displayed days. The earlier 8-item cap was applied globally and
  silently truncated the week; events are now limited per day with a
  clickable "+N more" link that deep-links into the calendar day view.
  Today's morning episodes also stop being misclassified as "past".
- Dashboard and calendar hovers no longer get stuck in a highlighted
  state after a tap on touch devices - hover rules are now wrapped in
  `@media (hover: hover) and (pointer: fine)`.
- The trending / recent tiles on the dashboard now open the discovery
  modal correctly (the previous link pointed at the JSON resolver
  endpoint, which did nothing visible for the user).
- qBittorrent client cURL calls now set `CURLOPT_NOSIGNAL=1` and an
  explicit 3 s connect timeout on the four entry points
  (login / getRaw / request / post). Without `NOSIGNAL`, libcurl falls
  back to `SIGALRM` for DNS resolution - a signal PHP masks - leaving
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
  and every call site uses the return value - previously the redirect
  was issued but the method kept running, potentially double-rendering
  the wizard step.
- `GluetunClient::reset()` now also zeroes the three cache timestamps
  (`publicIpCacheAt`, `statusCacheAt`, `portCacheAt`); previously reset
  would keep stale entries alive for the rest of the TTL.

### Contributor

- **GitHub Actions CI workflow** (`.github/workflows/ci.yml`) running
  `make check` (PHP syntax lint + Twig lint + full PHPUnit suite) on every
  pull request and on every push to `main`. The job builds the Prismarr
  container with the dev compose overlay, installs Composer dev dependencies
  inside it, waits for `/api/health` to be ready, then runs the same
  `make check` contract that contributors run locally.
- **GitHub Actions release workflow** (`.github/workflows/release.yml`):
  triggered by pushing a `v*.*.*` tag, sets up QEMU + Buildx, builds a
  multi-architecture image (`linux/amd64` + `linux/arm64`), pushes it to
  Docker Hub under `shoshuo/prismarr` (or a configurable image name) with
  semver tags `:X.Y.Z`, `:X.Y`, `:X` and `:latest`, and creates a GitHub
  release whose body is auto-extracted from the matching `CHANGELOG.md`
  section.
- **Public docs polished for the v1.0 release**: every em dash (`-`)
  replaced by an ASCII dash (`-`) across `CONTRIBUTING.md`, `SECURITY.md`,
  `.github/PULL_REQUEST_TEMPLATE.md` and `.github/ISSUE_TEMPLATE/*.md`;
  `CONTRIBUTING.md` updated to reflect the EN-first i18n reality (UI strings
  go through `messages+intl-icu.{en,fr}.yaml`, English is the source of
  truth) and the live CI workflow (no longer "starting in v1.1"). Commit
  messages are now allowed in either English or French.
- PHPUnit 13 deprecations and notices eliminated: one `with()` call without a
  matching `expects()` rule was converted to `expects($this->once())`, and 17
  TestCase classes that use mocks purely for stub return values are now
  annotated with `#[AllowMockObjectsWithoutExpectations]`. The test run output
  is now a clean `OK (179 tests, 376 assertions)` with no extra lines.
- `CONTRIBUTING.md` adds a six-category "Definition of Done" checklist and
  five non-negotiable golden rules. `make check` must be green before every commit.
- New `tests/AbstractWebTestCase` base class boots a real kernel with an
  isolated SQLite file, seeds an admin + the `setup_completed` flag, and
  logs in the admin - foundation for functional tests that need a live
  request/response cycle.
- `make test` now passes `-e APP_ENV=test` to `docker exec`; previously
  the container's `APP_ENV=dev` was overriding the `APP_ENV` directive
  declared in `phpunit.dist.xml`.

## Template for future versions

<!-- Copy this block above [Unreleased] when cutting a release. -->

<!--
## [X.Y.Z] - YYYY-MM-DD

### Added
### Changed
### Deprecated
### Removed
### Fixed
### Security
### Contributor

[X.Y.Z]: https://github.com/Shoshuo/Prismarr/compare/vPREV...vX.Y.Z
-->

[Unreleased]: https://github.com/Shoshuo/Prismarr/compare/v1.0.6...HEAD
[1.0.6]: https://github.com/Shoshuo/Prismarr/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/Shoshuo/Prismarr/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/Shoshuo/Prismarr/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/Shoshuo/Prismarr/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/Shoshuo/Prismarr/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/Shoshuo/Prismarr/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/Shoshuo/Prismarr/releases/tag/v1.0.0
