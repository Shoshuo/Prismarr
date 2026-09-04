# Changelog

All notable changes to Prismarr are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Optional Bazarr integration for subtitles** ([#103](https://github.com/Shoshuo/Prismarr/issues/103)). Configure a Bazarr URL and API key in `/admin/settings → Services` and an admin-only **Subtitles** entry appears in the sidebar with four views: Wanted (badge counters, a "most missing" poster row and the wanted movie/episode lists), Movies and Series (searchable, filterable, lazily-rendered poster grids over Bazarr's own lists), and History. Every in-library title also gains a subtitle-status badge — on the Films and Series grids and tables, in the quick-look modal, in the film/series detail modals (with per-language present/missing chips) and in `Ctrl+K` results — and clicking a "missing" badge opens a subtitle search you can download from in place, per movie or per episode. The badge read path never talks to Bazarr during a page render: a full-library fetch takes seconds and the Films page renders hundreds of badges, so the datasets are served stale-while-revalidate from `cache.app` and rebuilt in the existing `messenger-worker` service, at most one Bazarr list fetch per dataset per minute. A cold cache renders a "warming" placeholder that fills itself in rather than blocking, a mutation patches just the acted-on item, and everything fails closed — an unreachable or unconfigured Bazarr shows the standard service banner, never a 500. The whole feature is off unless configured, and has its own kill switch alongside the other services.

### Performance
- **The Radarr/Sonarr library is served stale-while-revalidate, from one shared entry.** The 45 s `MediaLibraryCache` window still expired inside a request, so whoever arrived first after it lapsed paid the full multi-second `getMovies()` / `getSeries()` fetch before anything rendered — and the dashboard kept its own second copy of the same payload (`dash.movies` / `dash.series`) with no invalidation hook, so a change made on the Films page could stay invisible on the dashboard for the rest of the window. A new shared `StaleWhileRevalidateCache` splits the window in two: 45 s is now the *soft* TTL (a lapsed entry is served immediately and a rebuild is queued to the already-running `messenger-worker` service, so the refetch happens outside the request) and 600 s is the hard ceiling past which a read still blocks, because the library pages cannot render without the list. The dashboard now reads the same per-instance entry as the library pages, so the three duplicate fetch paths collapse into one and existing library invalidations finally reach the dashboard. Measured on a large library: the Films page's cold load drops from ~11.5 s to ~0.32 s and the dashboard's recent-additions fragment from ~3.8 s to ~0.25 s. An empty or failed fetch is still never cached, a mutation still invalidates hard (the next read refetches), a refresh is skipped for an instance whose circuit breaker is open, and a rebuild that goes unanswered for 180 s logs one rate-limited error — the visible symptom of a stopped worker.
- **The Ctrl+K search index is normalized once, not on every keystroke.** `/search` transliterated three fields (`title`, `originalTitle`, `sortTitle`) on every row of the whole library on every request, then re-normalized both sides of every comparison in its `usort()` — so the accent-insensitive matching that makes the dropdown pleasant to type into was being recomputed from scratch for each character typed. The `Any-Latin; Latin-ASCII; Lower()` work now happens once per 60 s index build, with a single reusable `Transliterator` instead of the procedural `transliterator_transliterate()` (which re-parses the compound rule id on every call); matching is then plain `str_contains` over the pre-normalized fields, the comparator reads the pre-normalized title, and the helper fields are stripped before the JSON so nothing extra reaches the DOM. The index itself no longer runs its own second `/api/v3/movie` + `/api/v3/series` fetch either — it reads the shared `MediaLibraryCache` entry, so search results can no longer drift out of sync with the Films/Series pages. Measured on a large live library: a warm Ctrl+K query drops from 1.6–5 s to 13–31 ms (isolated, without HTTP overhead: 0.8–3.3 s to ~3 ms). Result order is unchanged (titles starting with the term first, then alphabetical, capped at 12) and the cache keys bump to `_v3`, since a cached index built before this change would silently match nothing.


## [1.2.0] - 2026-08-29

### Fixed
- **Whole-server lockup on Unraid when the data volume sits on a FUSE share (`/mnt/user`)**. PHP's native file session handler holds an exclusive `flock` on the session file for the entire request, and the dashboard fires ~6 widget fragments in parallel, so they all serialised on that one lock while their slow Radarr/Sonarr calls ran. On Unraid the session file lives on the shfs/FUSE share, where `flock` contention is expensive enough to peg every core and freeze the whole machine (mapping the volume to `/mnt/cache` "fixed" it only by bypassing FUSE). A new `SessionLockReleaseSubscriber` now closes the session right after authentication on read-only GET requests, releasing the lock immediately so the parallel fragments stop fighting over it. POSTs, the setup wizard and internal routes keep the session open and write normally. Unraid users should still map the data volume to `/mnt/cache/...` rather than `/mnt/user/...`.
- **Gluetun integration with API key set, and incorrect endpoints.** The Gluetun client authenticated using `Authorization: Bearer <key>`, but Gluetun expects it as `X-API-Key`, so it would return a 401 error when an API key is required. Additionally, referring to the older [Control Server Docs](https://github.com/qdm12/gluetun-wiki/blob/7025b1c0e4427d4477e47d4bbd2ef3f1b5c4da71/setup/advanced/control-server.md#openvpn-and-wireguard), WireGuard doesn't get its own endpoint, so the `/v1/wireguard/status` and `/v1/wireguard/portforwarded` calls were incorrect. The client now sends `X-API-Key` and uses the unified `/v1/vpn/status` and `/v1/portforward` endpoints, with the legacy `/v1/openvpn/` paths as a fallback. With that, the protocol selector in the settings becomes redundant and was removed.
- **Radarr/Sonarr sidebar entry unusable with 4+ instances** ([#44](https://github.com/Shoshuo/Prismarr/issues/44)). The dropdown toggle shown for 4 or more instances of the same service used the native Bootstrap `data-bs-toggle`, but nothing in the app initializes Bootstrap's dropdown JS anymore — every other dropdown had already moved to a shared click-delegate. The triangle rendered but never opened, making every instance beyond the first three unreachable. The toggle now uses the same delegate as the rest of the app, and its label shows the active instance's name instead of staying stuck on the generic service name.
- **A torrent client page could show another client's downloads mid-session.** Turbo Drive re-executes the incoming page's `<script>` with a fresh closure while the outgoing page's — and its live 3-second poll timer — kept running; since the qBittorrent, Deluge and Transmission pages render the same element IDs, the orphaned poller kept overwriting the page you'd navigated to with its own client's torrents. The poll timer now lives on a single shared handle so the incoming page can kill the outgoing one's, and these three pages always do a full reload in and out instead of a Turbo visit.

### Performance
- **Service-health pings are now cached across requests.** `HealthService::statusFor()`'s 10 s memo lived in a per-object array, which the classic (non-worker) FrankenPHP throws away with each request — so every topbar/dashboard health poll, from every open tab, re-pinged all configured services with sequential blocking calls. The verdicts are now shared through `cache.app` (same 10 s TTL): the whole install performs at most one probe sweep per window, and `invalidate()` (admin "Test connection" / settings save) rotates a generation token so stale verdicts can't outlive a reconfiguration.
- **Static assets get real browser-cache headers.** Caddy now serves AssetMapper-compiled `/assets/*` (content-hashed filenames) with `Cache-Control: public, max-age=31536000, immutable`, and the unfingerprinted `/static/*` vendor bundles (Tabler, Chart.js) plus `/img/*` with a one-day TTL — repeat page loads stop re-negotiating ~600 KB of CSS/JS.
- **Prod cache pre-warmed at image build.** The Dockerfile runs `cache:warmup` after `asset-map:compile`, so the first request after a container (re)start no longer pays the 1–3 s container/route/Twig compile. Env vars stay runtime-resolved placeholders, so the boot-generated `APP_SECRET` doesn't invalidate the baked cache.
- **Faster Radarr / Sonarr library pages.** The heavy `getMovies()` / `getSeries()` payload is now cached per instance for 45 s (`MediaLibraryCache`) instead of being re-fetched and re-normalised on every visit, and the per-page status / queue / indexers / health / calendar calls run in a single `curl_multi` batch (`multiGet()`) rather than sequentially. Cold loads are unchanged, but revisits within the window are roughly 3× faster, and a slow or unreachable instance now costs one timeout window for the whole page instead of stacking one timeout per call. Empty results are not cached and library mutations invalidate the entry, so user changes still show immediately. Same per-handle semantics as the existing `get()` (SSRF protocol guard, connect/total timeouts, per-instance circuit breaker).

### Internal
- **CI / release workflows modernised.** Bumped the pinned GitHub Actions to current majors (`checkout@v5`, `setup-qemu@v4`, `setup-buildx@v4`, `login@v4`, `metadata@v6`, `build-push@v7`, `dockerhub-description@v5`, `action-gh-release@v3`) across `ci`, `beta`, `release` and `dockerhub-readme`, added a `workflow_dispatch` trigger to `ci` so the suite can be run on demand, and guarded the Docker Hub README sync with `if: github.repository == 'Shoshuo/Prismarr'` so forks don't fail the job on missing secrets.
### Added
- **Deluge tab (optional).** A full torrent-management page for [Deluge](https://deluge-torrent.org/) (deluge-web URL + password in the setup wizard or `/admin/settings`, with per-service kill switch, Test connection and health chip), mirroring the qBittorrent tab: live torrent table (2s-refresh API with server-side pagination/filter/sort/search), state filters, read-only **Label** filter (Label plugin — labels are set by Sonarr/Radarr and never written from Prismarr), seeding-focused Ratio / Uploaded / Completed columns, a detail panel (status, files, trackers, peers) with Radarr/Sonarr resolve, single + bulk pause/resume/delete(±data)/recheck, reannounce, move storage, session-wide Pause All / Resume All (`core.pause_session`), add via magnet/URL/.torrent upload (SSRF-guarded, bencode-validated) with save-path, per-torrent and global speed limits, and a sidebar badge with download-complete toasts. The JSON-RPC client judges success on the response envelope (deluge-web answers HTTP 200 even on failure), auto-reconnects a daemon-disconnected web UI, supports an authenticating-reverse-proxy mode (empty password), and runs behind the same circuit breaker + SSRF protocol locks as every other service client.
- **Transmission torrent client integration.** A new download-client page (setup wizard step, admin settings entry, sidebar badge/poll, health circuit breaker) alongside qBittorrent, modeled on the same conventions. Transmission's RPC session handshake is handled transparently: the first request without a valid `X-Transmission-Session-Id` gets an expected HTTP 409 carrying the real token, which the client caches and retries with — the setup wizard's Test button and the dashboard health check both treat that 409 as "reachable," while a genuine `401` (bad RPC password) is reported as an auth failure. The user/password fields are optional, matching qBittorrent's reverse-proxy-friendly config shape. The page covers the list/table/compact torrent views, filtering, search, sort, pagination, bulk actions (pause/resume/delete/recheck, plus pause-all/resume-all), a per-torrent detail modal (general/files/trackers/peers) and add-by-URL / add-by-file, with a read-only category filter derived from Transmission's own labels.
- **Plex activity via Tautulli (optional).** A new read-only Tautulli integration (URL + API key in `/admin/settings`, behind the per-service health circuit breaker) surfaces current Plex activity. The dashboard gets a "Current Plex activity" widget — active streams, Direct Play / Direct Stream / Transcode counts, total / LAN / WAN bandwidth and a per-session card (quality, HDR/SDR badge, source→target codec when transcoding) — that hydrates on its own and refreshes every 10 s. A dedicated **Plex Activity** page (own sidebar entry) adds a now-playing strip, watch statistics with a 7 / 30 / 90-day toggle (top movies / shows / users / platforms), plays-over-time graphs with a Media-type ⇄ Stream-type toggle plus by-hour and by-day-of-week breakdowns and a platform × stream-type "problem clients" chart, a dense watch-history grid, and per-library item counts. Each title opens an in-app info modal (synopsis, ratings, cast/crew). The API key never leaves the server, every response is sanitised before it reaches the browser, and each section fails open independently so a down/misconfigured Tautulli never breaks the dashboard or page. Chart.js is self-hosted (`public/static/chart/`) for CSP compliance and reused by the existing Radarr stats chart.
- **In-place quick-look on the dashboard.** Clicking any media tile — hero spotlight, upcoming, weekly trending, watchlist or latest additions — now opens a read-only detail modal right on the dashboard (poster, year, status, rating, genres, synopsis) instead of navigating away, with a deep-link to manage the item in Radarr/Sonarr or open it in Discover. Library items resolve from the already-cached dashboard aggregate (zero extra upstream calls on a warm page, falling back to a direct fetch on a miss); TMDb items resolve straight from TMDb. The fragment is read-only and fails open to a small graceful body, and the modal is Turbo-safe (declarative trigger, escaped values).
- **Plex Activity page — statistics, graphs & a per-user filter.** The activity page's statistics gain Most Popular Movies / Shows tiles (ranked by distinct viewers), a Most Concurrent Streams tile, and a **Play Count ⇄ Play Duration** toggle that reformats every count-based tile and chart into watch time. Four new graphs are added — plays by source resolution, plays by stream resolution, streams by user, and concurrent streams over time (always a count, never reformatted as duration) — alongside a new privacy-safe **Users** overview table (friendly name, relative last-seen, last played, play count, total watch time). A page-wide **per-user filter** dropdown scopes the statistics, all graphs and the history grid to a single user, or all users (the Users overview table always lists everyone). The metric and user-id are clamped/validated server-side (`metric` ∈ `plays|duration`, user filter digits-only), every new client method and endpoint fails open to its neutral shape, and the allow-list keeps email / IP / avatar / Plex login off the wire — the opaque Tautulli user-id is a filter token only and never rendered. All data comes from read-only Tautulli commands (`get_home_stats`, `get_users_table`, `get_user_names`, `get_plays_by_*`, `get_concurrent_streams_by_stream_type`); strings are i18n'd (en/fr).
- **Dashboard theming (glance-style presets).** A single admin-chosen instance theme (Settings → Display, beside the accent picker) restyles the whole UI from a curated catalogue of 17 presets adapted from [glance](https://github.com/glanceapp/glance)'s theme list. Each preset is a full colour model — HSL background / primary / positive / negative plus contrast and text-saturation multipliers and a light/dark flag — resolved server-side into concrete CSS variables (no flash of unstyled colour, `--tblr-primary-rgb` precomputed) and injected into the base layout. Picking a preset fixes the palette for everyone; the new default "Classic" mode keeps the original per-browser light/dark toggle instead, so existing installs are a visual no-op until a theme is explicitly picked. The accent picker gains a "theme default" option that follows the chosen theme's primary (or the original indigo in Classic mode). A theme change forces one full reload so the new colours apply immediately (normal Turbo navigation stays fast); strings are i18n'd (en/fr).
- **Rich detail modal from top-bar search, with release dates.** Clicking a search result now opens the quick-look detail modal (instead of jumping straight to the quick-add form), enriched with release/air dates: movies show theater / digital / physical dates (the next upcoming one emphasised), TV shows a Continuing/Ended status with first-aired and next-episode (or end) dates. The action button is context-aware — **Manage** (deep-link) for items already in your library, **Add** (the existing quick-add flow) for ones that aren't. In-library results use the authoritative Radarr/Sonarr dates; not-yet-added results read from TMDb (theater/digital/physical parsed from TMDb's `release_dates`, FR→US fallback; series air dates from `next/last_episode_to_air`). The quick-look modal is now app-global (extracted to a shared partial), so it's available from search on every page and the dashboard tiles reuse it. Labels are i18n'd (en/fr); the modal stays read-only and fails open.
- **Dashboard layout customization.** Reorder and hide/show the dashboard's content sections (upcoming releases, requests, health, Plex activity, watchlist, trending, recent additions) from Settings → Display, or directly on the dashboard itself via an on-page edit mode — drag to reorder, per-section hide toggle. The Hero banner stays pinned at the top and is never reordered. Order and visibility resolve server-side (`DashboardLayoutService`) from the flat settings table; unknown or duplicate keys are dropped, and any section missing from a saved order is appended in its default position, so a section added in a future release always shows up instead of silently vanishing.
### Changed
- **Download queue widget starts collapsed on Radarr/Sonarr.** The films/series queue card now loads collapsed by default instead of open; the existing toggle (click the header) still expands it, and the 2s auto-refresh no longer forces it back open.
- **Plex items open the app-global quick-look modal.** Clicking a title on the Plex Activity page or the dashboard's Plex widget now opens the same rich detail modal used everywhere else (poster, synopsis, ratings, watchlist button, Manage/Discover deep-links) instead of the bespoke Tautulli metadata pop-up. The click resolves the item's TMDb id server-side (`/tautulli/api/quicklook/{ratingKey}`, one `get_metadata` call — episodes/seasons resolve to their show, with a grandparent hop for older Tautulli payloads that lack show-level guids); only the numeric TMDb id reaches the browser, keeping raw Plex guids inside the existing allow-list sanitization. Items with no TMDb match (music, home videos) fall back to the legacy Plex metadata modal, so every click keeps working.
- **Dashboard service-health chips show latency.** `HealthService::statusFor()` returns a status word plus a round-trip reading (cached 10 s like the existing bool path, which now delegates to it); the dashboard chips render five states — up / slow / very_slow / down / degraded — with a coloured dot, so a reachable-but-slow service is visibly distinct from a healthy one. `isHealthy()` keeps its old contract for every existing caller.
## [1.1.1] - 2026-06-10

### Fixed
- **qBittorrent 5.2+ integration broke, every data call returned 403** ([#33](https://github.com/Shoshuo/Prismarr/issues/33)). qBittorrent 5.2.0 renamed the WebUI session cookie from `SID` to `QBT_SID_<port>` (and added `HttpOnly` / `SameSite`). The login cookie extraction only matched `SID=`, so the client got no session and every authenticated GET/POST 403'd, while the connection test (which only checks the HTTP status) still showed "Connected". The cookie is now captured under whichever name qBit returns (`SID` or `QBT_SID_<port>`) and echoed back verbatim. Affected users on an unpatched build can stay on / roll back to qBittorrent 5.1.4 in the meantime.

## [1.1.0] - 2026-06-09

### Added
- **Usenet download clients: SABnzbd and NZBGet** ([#20](https://github.com/Shoshuo/Prismarr/issues/20)). Two new download pages modelled on the qBittorrent one, one per client. Each has three view modes (list / table / compact, persisted in `localStorage`), column and menu sorting, a status-filter dropdown plus search, multi-select with a bulk action bar (pause / resume / delete), a per-item detail modal, and an Add modal that takes drag-dropped `.nzb` files, several URLs at once and a category picker (categories read live from the client). Rows carry a per-state icon (downloading / queued / paused / repairing / extracting / moving / done / failed), URL fetches show a "wait Xs" badge while the server holds the slot, and each action raises its own toast instead of a generic confirmation. NZBGet failure statuses (`FAILURE/PAR`, …) are translated to a readable message.
- **Dedicated paginated Usenet history page** - `/usenet/{client}/history`, reached from a toolbar button, paginates server-side (SABnzbd `start`/`limit`, NZBGet sliced) so a long history never bloats the live queue page. The main page no longer fetches history at all, saving one upstream call per poll.
- **SABnzbd and NZBGet in the setup wizard** - the `/setup/downloads` step now configures both Usenet clients (URL, credentials, Test connection, recap) alongside qBittorrent.
- **Usenet health pill and sidebar download badge** - each client gets the same health dot as the rest of the services (key-aware, behind the per-service circuit breaker) and a background-hydrating queue-count badge in the sidebar.
- **Server-side pagination, filtering and sorting on the films / series libraries** ([#19](https://github.com/Shoshuo/Prismarr/issues/19)). The browser no longer receives the whole library at once: the page renders a single page (50 / 100 / 200 / 500 items, set in `/admin/settings → Display`) with status, quality/network, genre, language, sort and search all applied server-side. Facets are computed over the full library, `?open={id}` deep-links resolve to the right page, and v1.0 `?filter=` bookmarks still work. Libraries of 10,000+ items stay responsive.
- **Multi-instance Radarr and Sonarr** ([#21](https://github.com/Shoshuo/Prismarr/issues/21)). Configure as many instances per service as you need (1080p / 4K / Anime…); legacy single-config is migrated to a default instance at first boot. Each instance has its own URL, API key, name, slug, position, enabled flag.
- **Instance manager in `/admin/settings`** - add / rename / reorder / enable-disable / set default / delete + per-row Test connection. CSRF per action, ROLE_ADMIN.
- **Dynamic sidebar** - flat link (1 instance) → pill group (2–3) → dropdown (4+). Active instance highlighted across navigation.
- **Slug-aware routing** - every Radarr/Sonarr admin and media route is now `/medias/{slug}/...`. A `MultiInstanceBinderSubscriber` binds the right client per request; unknown slug → clean 404.
- **Per-instance health circuit breaker** - `ServiceHealthCache` keyed by `(service, slug)`, one outage doesn't silence its siblings.
- **Cross-instance aggregation everywhere** (Phase D) - dashboard widgets, calendar (UI + iCal), Ctrl+K search, qBit badge resolver and TMDb library lookup merge every enabled instance and dedup by `tmdbId` / `tvdbId`. iCal `UID`s rooted on `tmdbId`/`tvdbId` for cross-instance stability.
- **Quick-Add target picker** (Phase E) - `/decouverte/resolve` exposes `instances` (current owners) + `candidates` (every enabled instance, with `is_default`). The modal lets the user pick where to add when 2+ Radarr/Sonarr exist.
- **Settings export v2** - JSON dump now includes the `instances[]` topology (no API keys); restore preserves the original slug ordering. v1 backups still accepted.
- **Expandable shelves on Radarr/Sonarr shelf views** ([PR #29](https://github.com/Shoshuo/Prismarr/pull/29)).
- **Per-service enable/disable toggle** ([#15](https://github.com/Shoshuo/Prismarr/issues/15)) - a switch in `/admin/settings` for Prowlarr, Jellyseerr, qBittorrent and TMDb. Disabled: the service drops out of the sidebar, its pages bounce home with a "{service} is disabled" notice, HealthService stops pinging it, the dashboard/topbar treat it as not configured, and the clients themselves refuse to talk to it (so dashboard widgets that call them directly stop fetching from a "disabled" service). URL and API key stay in the DB, re-enabling is one click. Disabling a Radarr/Sonarr instance gives the same notice, named after the instance, instead of a bare 404.
- **`PRISMARR_FRAME_ANCESTORS` env var** ([#25](https://github.com/Shoshuo/Prismarr/issues/25)) - set it to a space-separated origin list to embed Prismarr in an iframe (Organizr, Heimdall, …). Unset keeps the default lockdown (`frame-ancestors 'self'` + `X-Frame-Options: SAMEORIGIN`).
- **Pending-requests badge on the Jellyseerr sidebar entry** - a count bubble, like the qBittorrent downloads badge, shows how many requests are awaiting action. It hydrates in the background (60s poll), hides itself when nothing is pending, and backs off instead of flashing zero when Jellyseerr is unreachable.
- **Support links in `/admin/settings`** - a discreet "Support" link at the bottom of the settings sidebar and a "Support & Community" card in the About tab, pointing to the project's Buy Me a Coffee page and Discord server. The two URLs are centralised as Twig globals so they live in a single place.

### Changed
- **Dashboard "Services health" widget stays live and breaks down by instance.** The card re-fetches its fragment on a 30s interval instead of freezing at first paint (paused while the tab is hidden), and Radarr/Sonarr now render one chip per enabled instance, named after the instance (Radarr 1080p, Radarr 4K) like the topbar dropdown, instead of a single aggregated dot.
- **Settings services page restyled.** Each integration shows its real brand logo (from the Apache-2.0 dashboard-icons set) on a light chip with the brand colour kept as a ring, instead of a generic coloured cog. The service cards also lay out in a responsive two-column grid so the list stays compact as clients are added; the multi-instance Radarr/Sonarr cards span the full width since their table needs the room.
- **UI emojis replaced with Tabler icons** ([#18](https://github.com/Shoshuo/Prismarr/issues/18)). Status emojis now rely on the toast/banner type icon; the remaining semantic emojis became inline Tabler SVG through a shared Twig macro, so icons follow the surrounding text colour and the theme picked in `/admin/settings` and render the same on every platform.
- **Action buttons on the About and Updates settings sections carry Tabler icons** - the GitHub, Docker Hub, roadmap, bug-report and docs links are now icon-prefixed (same macro as #18) so the page reads at a glance.
- **Films / series toolbar redesign + loading feedback** - the filter, sort and view-mode controls were reorganised into a two-card toolbar for the server-side pagination, and the grid dims briefly while a filter or page change reloads so the action feels responsive.
- **Dashboard loads instantly, widgets hydrate in the background** ([#27](https://github.com/Shoshuo/Prismarr/issues/27), [#30](https://github.com/Shoshuo/Prismarr/issues/30)) - the page renders as a shell with skeletons and each widget (hero, upcoming, requests, health, recommendations, recent additions) hydrates from its own fragment endpoint after first paint, so the dashboard is interactive in ~25ms instead of blocking 7-12s on upstream calls. A short shared cache (45s) on the heavy Radarr/Sonarr aggregates dedupes the parallel fragment calls and brings warm loads to ~70ms; empty results aren't cached, so a transient upstream failure retries on the next paint.
- **Slim stats strip on the films / series pages** ([#7](https://github.com/Shoshuo/Prismarr/issues/7)) - the four tall stat cards became a single one-line strip (numbers plus inline labels) to reclaim vertical space, and the films Downloads queue card is now collapsible to match the series page.
- **Compact interface density tightens the detail modals** ([#6](https://github.com/Shoshuo/Prismarr/issues/6)) - when Interface density is set to Compact, the film and series detail modals scroll internally to fit the viewport, with a shorter fanart header, smaller title and denser cards; Comfortable density is unchanged. Reuses the existing `display_ui_density` preference instead of adding a dedicated toggle.
- **Languages card redesigned for multi-instance** - per-service blocks, per-instance UI + info-language selectors; partial failures reported by instance name.
- **Sonarr manual import is reliable** - uses `GET /api/v3/manualimport?downloadId=<hash>` instead of forging the payload, dedups queue items sharing a downloadId, reports grouped reject reasons.
- **Interactive release search is more patient and tells you why it's empty** - the upstream search ran out of time at 45-60s while setups with several slow indexers routinely take 70-90s (Sonarr/Radarr themselves wait that long); bumped to 90s, with `set_time_limit()` raised to match on the three search routes. And a search that times out now returns 504 so the UI shows "the indexers took too long" instead of a misleading "no releases found". `RadarrClient::getReleasesForMovie` also gained the `CONNECTTIMEOUT`/`NOSIGNAL` the other clients have.
- **Calendar uses Sonarr local `airDate`** ([#26](https://github.com/Shoshuo/Prismarr/issues/26)) - episodes stay on the right day regardless of viewer TZ. Same fix on `series_missing` / `series_cutoff`.
- **`TorrentResolverService` matches `originalTitle` + every `alternateTitles[].title`** - French installs (Aventures croisées ↔ Swapped) resolve correctly. Accent folding moved to `intl Transliterator` to dodge an Alpine/musl iconv bug.
- **Topbar health badge surfaces every instance** - one row per enabled instance instead of one aggregate per service.
- **Queue card on the series page is collapsible** - mirrors the existing calendar card.
- **`ServiceInstanceProvider::getDefault()` only returns an enabled instance** - disabling the default Radarr/Sonarr instance no longer leaves it as the fallback target the autowired clients bind to; the first enabled instance takes over (or the service reads as unconfigured if none are).
- **`AppVersion` reports the build's own version** - reads `PRISMARR_VERSION` (stamped by the release/beta workflows), falls back to the `1.1.0-dev` constant for local builds. `:latest`, `:beta` and `make dev` each show the right string; `version_compare` ranks `1.1.0-beta.N` below `1.1.0` so beta testers get nudged to the stable.

### Fixed
- **10 sub-pages of `/admin/settings/{radarr,sonarr}/...` had buttons silently doing nothing in multi-instance** - hardcoded legacy URLs migrated to slug-aware routes, all sub-pages also moved to Turbo-safe JS.
- **Sonarr indexer / notification test+delete buttons** - 4 `fetch()` URLs leaked the Twig `~` operator into a JS string literal, killing the IIFE on script load.
- **Films "Rename file" preview** - hit the legacy non-slug route and never received a payload.
- **TMDb "My recommendations" biased to the default instance** - seeds now iterate every enabled Radarr/Sonarr, dedup by `tmdbId`.
- **`/admin/settings` About widget counts** - aggregate films / series across instances, dedup by `tmdbId` / `tvdbId`. Falls back to `-` only if every instance fails.
- **Dashboard "Services" card aggregates Radarr/Sonarr health across instances** - was reflecting the default only, divergent from the topbar dropdown.
- **Dashboard mini-calendar dedups episodes on `tvdbId`** - `seriesId` is per-instance, so two Sonarr instances tracking the same show duplicated rows.
- **Quick-Add modal slug context** - Ctrl+K from a Sonarr page no longer 404s when adding a film.
- **Home redirect 500** - `redirectToRoute('app_media_films')` was called without `{slug}`; helper now hydrates from the default instance.
- **Sonarr / Radarr `request()` blew up on bare-string responses** - `"OK"` from notification test/delete is now coerced to `[]`.
- **`window.prismarrBytes` race on films page** - hoisted to the `<head>` script so `setInterval(refreshQueue)` doesn't fire before the definition.
- **Queue count badge illegible** (grey on indigo) - forced `text-white`.
- **`TorrentResolverService` URLs lacked the slug prefix** - qBit Radarr/Sonarr badges always 404'd in v1.1.0.
- **Settings import v2 lost the original sidebar ordering** - `position` is now restored from the export.
- **iCal dedup key parenthesised explicitly** - visual ambiguity around `??` vs `.` precedence (the previous form was correct but error-prone).
- **Dead `CURRENT_RADARR_SLUG` / `CURRENT_SONARR_SLUG` JS globals removed** - Quick-Add was reverted to `DEFAULT_*_SLUG` semantics and nothing else read them.
- **qBittorrent 5.2.0 reported as unreachable** ([#28](https://github.com/Shoshuo/Prismarr/issues/28)) - the runtime client demanded HTTP `200` exactly; qBit 5.2.0 answers `204 No Content` on some Web API endpoints. Now accepts the whole 2xx range, matching the connection-test path.
- **`HomeController` redirect-loop when the chosen home page is a disabled service** - the fallback chain and the `display_home_page = 'discovery' / 'qbittorrent' / 'last'` paths now ask `HealthService::isConfigured()` instead of `ConfigService::has()`, so a disabled service is treated as not-configured and the next viable target is picked. Without this guard, `ServiceRouteGuardSubscriber` would redirect the disabled service back to `app_home` and the browser would bounce until the redirect cap kicked in.
- **`pollCmd` no longer claims "no release found" when Sonarr/Radarr's completion message lacks the report-count phrase** - the regex `(\d+) reports downloaded` only matched the plural form (so `1 report downloaded` was read as zero) and didn't match newer Sonarr v4 messages that just say "Completed". Now permissive (singular/plural, `downloaded`/`grabbed`) and falls back to the neutral "complete" banner when no count is found - instead of the misleading "no result found" warning.
- **Pre-1.1.0 media URLs 404'd after upgrade** - `/medias/films`, `/medias/series`, `/medias/radarr/...` and `/medias/sonarr/...` (and the AJAX routes a cached v1.0.x page keeps polling) now 307-redirect to the default instance's slug-aware path. Method preserved, so cached POST handlers keep working. Bookmarks survive `docker compose pull`.
- **Multi-instance Radarr/Sonarr table overflowed on narrow viewports** - the six-column instance table had no scroll container and spilled out of its card on phone widths; it now scrolls horizontally inside the card (same pattern as the languages table).

### Security
- **Sanitised upstream bodies before logging** - `RadarrClient::request()` / `SonarrClient::request()` redact `apikey=`, `"apiKey":`, magnet links, then truncate to 200 chars.
- **Sanitised JSON 500 on the films bulk endpoints** - `filmsBulkRefresh`, `filmsBulkSearch` and `filmQueueImport` no longer leak `$e->getMessage()`.
- **`showPageBanner` XSS hardening** - text-safe `showPageBanner` (via `textContent`) for the 46 sites with upstream-controlled strings; explicit `showPageBannerHtml` for the 11 sites that intentionally render markup.
- **`window.escHtml()` helper + 7 innerHTML splice sites** - escape `& < > " '` in the Quick-Add picker rows + candidates `<select>`, the topbar health dropdown, the Ctrl+K search result row, the recent-search row, the Quick-Add profile/folder `<option>` lists, the TMDb discovery cards, and the calendar tooltip.
- **`ServiceInstanceProvider::create` / `update` reject bad URL schemes** - `file://` / `javascript:` / `gopher://` blocked at write time via `HealthService::urlBlockedReason`. Defense in depth, the cURL layer is already pinned to HTTP(S).
- **`AdminInstancesController::testInstance` asserts type ∈ `ServiceInstance::TYPES`** - guard against a future probeFor() that lazily accepts more types.
- **`HealthService::urlBlockedReason` reports `malformed`** for a URL `parse_url()` can't parse (e.g. a port outside 0-65535), so editing an instance with a bad port shows "Invalid instance URL (malformed)" instead of a misleading "(scheme)".
- **`PRISMARR_FRAME_ANCESTORS` also strips `;` and `,`** alongside control chars: `;` would close `frame-ancestors` and smuggle a fresh CSP directive, `,` splits the header into multiple intersected policies (a footgun that silently breaks the app even though it can't weaken the policy). Origins are space-separated, neither character is ever legitimate.
- **Global qBittorrent poll script gated on `service_configured('qbittorrent')`** - when qBit is disabled the poll endpoint redirects to home, the JS reads HTML, `r.json()` throws, the circuit breaker backs off to two minutes. Skipping the script entirely when the service is off keeps the page quiet.
- **Usenet Add-URL is pinned through `HealthService::urlBlockedReason`** ([#20](https://github.com/Shoshuo/Prismarr/issues/20)) - a submitted fetch URL is rejected if it isn't HTTP(S) or resolves to the link-local cloud-metadata range, so the feature can't be turned into an SSRF probe. The guard itself was hardened to also catch IPv4-mapped IPv6 literals (`::ffff:169.254.169.254`) and trailing-dot hosts that slipped past the first check yet still resolved to the metadata IP at request time.
- **The Usenet wizard no longer wipes a stored secret on re-submit** - `/setup` prefills password and API-key fields blank (browsers strip `type=password` values), so re-submitting a step used to overwrite the saved secret with an empty string. `save()` now skips an empty sensitive field instead of nulling it, and the managers step falls back to the stored key. Same guard applied to the Radarr/Sonarr API keys.
- **Usenet poll-summary endpoint returns a fixed `unreachable` marker** instead of the raw upstream exception message, so a misconfigured client can't echo connection internals back into the page.

### Tests
- 32 new unit tests on the v1.1.0 plumbing - `ServiceInstanceProvider` (22), `MultiInstanceBinderSubscriber` (7), `ServiceHealthCache` instance-keyed entries (3). Plus ~17 on `TorrentResolverService` + `SonarrClient::manualImportFromQueueItems`.
- 4 new `TmdbControllerTest` cases pinning Phase D+E - `/decouverte/resolve` exposes `instances` + `candidates`, series match by `tvdbId`, recommendations dedup across instances. Smoke tests seed default `radarr-1` / `sonarr-1` instances.
- 5 new dataProvider cases on `ServiceInstanceProvider::create` pinning the URL-scheme rejection.
- 3 new `AppVersionTest` cases - `PRISMARR_VERSION` overrides the constant, `dev`/empty falls back, a beta build is ranked below the matching stable.
- 16 new cases on the #28 / #15 / #25 work - `QBittorrentClient` 2xx acceptance (9), `HealthService` per-service kill switch (3), `AdminSettingsController` persisting the `<service>_enabled` form flag (1), `CspHeaderSubscriber` frame-ancestors widening + header-injection guard (3).
- 3 new `LegacyMediaRedirectTest` cases - index + sub-page redirects land on the default instance, real slug routes aren't intercepted.
- 2 new `urlBlockedReason` cases (malformed-URL reason) + a blocked-URL provider entry for an out-of-range port.
- 4 more #15 cases - `ConfigExtension` hides a disabled service from the sidebar (2), `ServiceRouteGuardSubscriber` bounces a disabled service (1) and a disabled instance (1) home.
- 2 `getDefault()` cases - skips a disabled flagged instance, returns null when every instance is disabled.
- 4 `MediaReleasesSearchTest` cases - episode/season/film release search returns 504 when the upstream call doesn't complete, a plain JSON array when it does.
- 5 `FlatServiceClientDisabledTest` cases - Prowlarr/Jellyseerr/qBittorrent/TMDb clients throw `ServiceNotConfiguredException` from `ensureConfig` when their kill switch is on, fall through to the credential check when the flag is absent.
- 2 `CspHeaderSubscriber` cases - `PRISMARR_FRAME_ANCESTORS` strips `;` and `,` on top of the existing CR/LF strip.
- 2 new `HomeControllerTest` cases pinning the redirect-loop fix - `discovery` and `last` preferences fall through cleanly when the target service is disabled.
- Usenet ([#20](https://github.com/Shoshuo/Prismarr/issues/20)) - `NzbgetClient` byte-pair recombination (signed 32-bit low half) and percentage clamp, `SabnzbdClient` wait-label parsing (only "sec" labels yield a wait), `SetupController` empty-secret preservation + managers key fallback, `UsenetController` Add-URL SSRF rejection, and `HealthService::urlBlockedReason` link-local evasions (IPv4-mapped IPv6, trailing dot) with loopback still allowed.
- Suite is **534 tests / 1150 assertions**, up from 273 / 565 at the end of v1.0.6.

### Migrations
- `migrations/Version20260503000000.php` (Big Bang) - creates `service_instance`, seeds the legacy `radarr_url` / `radarr_api_key` / `sonarr_url` / `sonarr_api_key` settings into a default instance per service (`slug = radarr-1` / `sonarr-1`), then drops the four settings rows. Reversible.

### Internal
- `docker-compose.example.yml` surfaces the `TZ`, `PHP_MEMORY_LIMIT` and `PHP_MAX_EXECUTION_TIME` knobs already supported by the image so users don't need to dig through docs to discover them.
- New `beta.yml` workflow - manual dispatch builds the current `main` as `shoshuo/prismarr:beta` (multi-arch). Pushes only the `:beta` tag, never `:latest`, never a GitHub release. README has a "Testing pre-release builds" note for opt-in testers.
- `release.yml` ignores pre-release tags (`v*-*`) and gates the `:latest` Docker tag behind a no-hyphen check - a `v1.1.0-beta.1` git tag can no longer trigger a release or clobber `:latest`.
- **Unraid Community Applications template** (`unraid/prismarr.xml`) - one-click install template: image, WebUI on 7070, the data volume pinned to `/var/www/html/var/data`, and the optional `TZ` / `PHP_MEMORY_LIMIT` / `PHP_MAX_EXECUTION_TIME` / `TRUSTED_PROXIES` env vars.
- **`.github/FUNDING.yml`** enables the GitHub Sponsor button (Buy Me a Coffee). The README gained Discord and Buy Me a Coffee badges plus a "Support and community" section.

## [1.0.6] - 2026-05-03

### Added
- **"Test connection" buttons in the setup wizard.** Each service step (TMDb, Radarr, Sonarr, Prowlarr, Jellyseerr, qBittorrent) now has an inline `Test connection` button next to its inputs. Result is shown as a small status badge (green "Connected", red category like "Wrong API key" / "Cannot reach service" / "Endpoint not found"). Non-blocking - users can still continue without testing. Categories are surfaced from `HealthService::diagnose()` so the same labels appear in `/admin/settings`.

### Security
- **SSRF guard on `HealthService::httpProbe()`.** The probe now hard-rejects any URL that isn't `http://` or `https://`, blocks the `169.254.0.0/16` link-local range used by AWS / GCP / Azure cloud-metadata endpoints, and pins cURL to `CURLPROTO_HTTP | CURLPROTO_HTTPS` for both the initial request and any redirect. RFC1918 LAN ranges (`10/8`, `172.16-31`, `192.168/16`) are intentionally still allowed because Prismarr legitimately needs to reach Radarr/Sonarr/Prowlarr/Jellyseerr/qBittorrent on private addresses. This closes a dormant blind-SSRF that wasn't exploitable in v1.0.5 (the only call site was admin-only `/admin/settings`) but would have become exploitable as soon as the new public `/setup/test/<service>` endpoint shipped.
- **Rate limiter on `/setup/test/<service>`.** 30 attempts per minute per (client IP × service), `sliding_window` policy. Neuters scripted port-scan attempts during the brief window where the wizard is publicly reachable (post-image-pull, pre-`setup_completed=1`).
- **Strict response envelope** on `/setup/test/<service>` - `{ok, category}` only, no echo of the URL probed, no echo of the API key submitted, no upstream response body. Headers force `Cache-Control: no-store, no-cache, private` and `X-Content-Type-Options: nosniff` so the response cannot be cached by an intermediate proxy.
- **Auth gate (`ROLE_USER`) on `/setup/test/<service>`.** The wizard step that hosts the test buttons (TMDb / Managers / Indexers / Downloads) only renders *after* the admin has been created at step 2 and auto-logged-in via `$security->login()`, so requiring `ROLE_USER` never blocks a legitimate flow. It does close the small window between image start and `setup_completed=1` where `/setup/*` is otherwise `PUBLIC_ACCESS`, eliminating the unauthenticated reachability of the probe endpoint entirely. Defense-in-depth on top of the existing CSRF token, rate limiter, service whitelist, and SSRF guard.
- 7 new PHPUnit tests covering the new endpoint (guard / CSRF / rate limit / strict payload / field whitelist) and the SSRF guard (file:// / gopher:// / dict:// / link-local IPs blocked, RFC1918 + public HTTPS allowed).

### Fixed
- **"Test connection" button is no longer rendered for Gluetun on `/admin/settings`.** `HealthService::probeFor()` has no Gluetun handler so the probe always came back as `unconfigured`, which made the button look broken even when Gluetun was correctly set up. The button is hidden until/unless we add a real Gluetun probe.
- **Locale-aware byte units** (issue [#4](https://github.com/Shoshuo/Prismarr/issues/4)). All filesize and transfer-rate displays now follow the active UI locale: English renders `GB / MB / KB / B` and `MB/s`, French keeps `Go / Mo / Ko / o` and `Mo/s`. Previously the FR abbreviations were hardcoded everywhere, including in the EN UI. Implemented as two new Twig filters (`prismarr_bytes`, `prismarr_speed`) and a global JS helper (`window.prismarrBytes`) so server- and client-rendered sizes stay consistent. Covered: root folders (Radarr / Sonarr), backups, Jellyseerr cache stats, qBittorrent dashboard totals, film/series detail cards, the post-download toast notification, and the qBittorrent torrent upload size-limit error message.
- **Stop logging spurious `tabler.min.css.map` 404s.** The bundled Tabler CSS files referenced an upstream sourcemap (`/*# sourceMappingURL=tabler.min.css.map */`) that wasn't shipped with the image, so any browser opening DevTools triggered a 404 caught and logged as an exception by Symfony. The reference is now stripped from both `tabler.min.css` and `tabler-themes.min.css`. Cosmetic only - no impact on the rendered UI, just a quieter `docker logs prismarr` for users triaging real bugs.
- **Stop pinging unconfigured services** (issue [#9](https://github.com/Shoshuo/Prismarr/issues/9)). `HealthService::isHealthy()` now returns `null` (was: a stale `false`) when a service has no URL or API key in the DB, and skips the ping entirely - so users who only enabled a subset of the stack don't see "Jellyseerr ping failed" / "Gluetun ping failed" warnings every minute in their logs. The dashboard, topbar dropdown, and `/api/health/services` endpoint already handled `null` as "not configured", so the new state propagates without any UI breakage. The `ServiceNotConfiguredException` thrown by Radarr/Sonarr clients on missing config is also caught silently inside the dashboard's `safeFetch()` for the same reason. New `HealthService::isConfigured()` helper exposes the check to the dashboard so it can hide widgets bound to disabled services entirely (mini-calendar, Jellyseerr requests, TMDb trending, recent additions) instead of rendering empty cards.
- **qBittorrent behind a reverse proxy** (issue [#10](https://github.com/Shoshuo/Prismarr/issues/10)). Empty username/password are now a legitimate configuration: when qBittorrent sits behind a proxy that injects authentication itself (qui, traefik forward auth, …) Prismarr treats the credentials as optional. `HealthService::isConfigured()` only requires the URL; `HealthService::probeFor()` falls back to a lightweight `GET /api/v2/app/version` (instead of `POST /auth/login` with an empty body, which qBit answers `Fails.`); `QBittorrentClient::login()` returns a sentinel SID that `getRaw()` / `postAction()` recognize and skip the `Cookie: SID=…` header for. The wizard step Downloads now displays an inline hint explaining the reverse-proxy setup, and `/admin/settings` exposes a "Clear" button next to the qBit user/password fields so a deliberate wipe bypasses the empty-value guard that protects the other credentials from a Firefox/Chrome silent strip. 5 new PHPUnit tests covering `isConfigured()` URL-only mode and `login()` sentinel behavior.

### Changed
- **Removed the "Coming soon" section in `/admin/settings` sidebar.** The disabled "Email notifications" and "Security · sessions" rows haven't been wired up to a real feature yet, and showing them as "v1.1" pollution every time the user opens settings adds noise without value. They will reappear when the corresponding features actually land.
- **Public roadmap link.** Added a "Roadmap" entry in the `/admin/settings` About page (next to Source / Bug / Docs) and on the Updates page (next to GitHub / Docker Hub), plus an explicit mention in the README's Project status section. Points to the public GitHub project at https://github.com/users/Shoshuo/projects/4 so users can see what's queued and follow progress without needing to dig through the issue list.
- **"Monitored only" filter and persistent state on films / series pages** (issue [#14](https://github.com/Shoshuo/Prismarr/issues/14)). Adds a "Monitored" pill to the existing status filter bar (next to All / Downloaded / Missing / Unmonitored) so users with a mix of monitored and unmonitored items can quickly narrow down to the ones actually being tracked. The active status filter is persisted in two layers: the URL (`/medias/films?filter=monitored`, `/medias/series?filter=continuing`, …) so refreshes keep the view and shared links land on the same filter, and localStorage so sidebar navigation back to `/medias/films` (without the URL parameter) restores the user's last choice. The URL takes precedence when present so shared links always override the local preference. Quality / genre / language / sort / search will follow alongside the v1.1.0 server-side pagination refactor.
- **Renamed "Jellyseerr" to "Seerr" in the UI** (issue [#2](https://github.com/Shoshuo/Prismarr/issues/2)). Overseerr and Jellyseerr were both archived in February 2026 and replaced by [Seerr](https://docs.seerr.dev/), a unified API-compatible fork. The wizard, sidebar, admin settings, dashboard and README now refer to "Seerr" instead. Internal identifiers (class names, route names, settings keys like `jellyseerr_url` / `jellyseerr_api_key`) are unchanged so existing installs aren't disrupted, and the API endpoints Prismarr calls all exist verbatim in Seerr's spec - pointing your config at a fresh Seerr container instead of the archived Jellyseerr one keeps everything working without any setting edit.
- **Single source of truth for the running version** (issue [#11](https://github.com/Shoshuo/Prismarr/issues/11)). The `/admin/settings` About card was reading a `PRISMARR_VERSION` env var that was never injected at build, so it always displayed the literal `1.0.0-dev` fallback while the Updates card on the same page showed the real version from `App\Service\AppVersion::VERSION`. The About card now reads from the same constant. Bumped at every release tag along with `CHANGELOG.md`. The boot banner in `init.sh` still respects the `PRISMARR_VERSION` env var, and the release workflow now passes `--build-arg PRISMARR_VERSION=$TAG_WITHOUT_V` so the banner displays the correct version on official images instead of `1.0.0-dev`.
- **Headroom for large libraries** (issue [#13](https://github.com/Shoshuo/Prismarr/issues/13) + duplicate). The `films` and `series` pages used to crash with `ERR_EMPTY_RESPONSE` (or a 500 / blank page) on libraries bigger than ~3,000–5,000 items, because the entire library is loaded in one shot then rendered in one Twig pass - easily blowing past the default 256 MB / 60 s ceiling. Four layers of fix: `php.ini` is bumped to **1024 MB / 120 s** as a sane default for medium-large homelabs; the `films` and `series` controllers also call `set_time_limit(120)` defensively; users with even bigger libraries can override both at runtime via the new `PHP_MEMORY_LIMIT` and `PHP_MAX_EXECUTION_TIME` env vars in `docker-compose.yml` (the init script writes them to `/usr/local/etc/php/conf.d/zz-runtime.ini` at boot, no image rebuild needed); and a new `FatalErrorHandlerSubscriber`, registered very early in `public/index.php` so it runs before Symfony's own error handler, catches `E_ERROR` (out-of-memory or max-execution-time) at PHP shutdown and emits a self-contained 503 HTML page that explains what happened and how to bump the limits, instead of letting the connection die mid-render. **The proper fix - server-side pagination so the page never loads more than ~100 items at once - is deferred to v1.1.0**: it's a substantial refactor of the films / series templates (1900+ lines each) plus the JS view-switching, and shipping it in 1.0.6 alongside the other fixes would risk regressions. The bumped limits cover libraries up to ~50,000 items in practice; users with bigger collections set `PHP_MEMORY_LIMIT=2048M` (or higher) in their compose.
- **Honor the `TZ` env var** (issue [#12](https://github.com/Shoshuo/Prismarr/issues/12)). The image used to ship `tzdata`-less and the `php.ini` had `date.timezone = Europe/Paris` hardcoded, so `TZ=Pacific/Honolulu` (or any other zone) in your `docker-compose.yml` was ignored at every layer: `date` inside the container, PHP's date helpers, the `/admin/settings` server time line. Now `tzdata` is bundled in the image, `php.ini` defaults to `UTC`, and the init script reads `$TZ` at boot to (a) symlink `/etc/localtime` to the right zone file, (b) write `/etc/timezone`, and (c) drop a `/usr/local/etc/php/conf.d/zz-tz.ini` that overrides PHP's default. Invalid or missing `$TZ` falls back to UTC instead of pretending everyone is in Paris. The boot banner shows the resolved zone so users can confirm at a glance.

## [1.0.5] - 2026-04-26

### Security

- **CRITICAL - credential leak via /setup/* after the wizard is completed.** The setup wizard pages (`/setup/tmdb`, `/setup/managers`, `/setup/indexers`, `/setup/downloads`) were marked `PUBLIC_ACCESS` to allow first-time install without login, and stayed reachable even after `setup_completed=1`. They pre-rendered the values of every saved API key / password in plain `<input type="text" value="...">` for the "Back" button UX, so any unauthenticated client able to reach the Prismarr port could `curl /setup/tmdb` and harvest the stored TMDb / Radarr / Sonarr / Prowlarr / Jellyseerr / Gluetun API keys plus the qBittorrent password. Fixed with two layers of defense:
  1. `SetupController::guardSetupNotCompleted()` - every wizard step (`tmdb` / `managers` / `indexers` / `downloads` / `finish`) now redirects to the home page when `setup_completed=1`. Re-configuration is only available via the auth-protected `/admin/settings` (ROLE_ADMIN).
  2. `SetupController::prefill()` - values whose key ends with `_api_key`, `_password`, `_secret` or `_token` are NEVER copied from the DB into the wizard render. Even if the redirect ever gets bypassed, the HTML emitted by the wizard cannot contain the secret. Trade-off: navigating "Back" through the wizard during the initial install no longer pre-fills these fields, the user has to re-paste them. This is acceptable on a one-time install flow.
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
