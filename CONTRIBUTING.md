# Contributing to Prismarr

> Definition of Done - every feature, fix or refactor must pass this checklist
> before being considered complete and pushed to `main`.

Prismarr is a self-hosted app published publicly on Docker Hub. Quality and
security directly affect real users who run this on their homelab. Respect
the process - "we'll fix it later" becomes a user-reported bug in v1.x.

---

## 🔧 1. Code

- [ ] The feature works end-to-end, tested manually in a running container.
- [ ] No leftover `console.log`, `dd()`, `var_dump`, `dump()`, `TODO`, `FIXME`.
- [ ] Comments are written in **English**. User-visible strings go through the i18n layer (`messages+intl-icu.{en,fr}.yaml`), with English as the source of truth.
- [ ] **Zero credentials** in code, commits, logs, docs (sacred rule).
- [ ] No unused `use` / imports.

## 🧪 2. Tests

- [ ] At least **one unit test** for any new business logic.
- [ ] For a bug fix: **a regression test that first reproduces the bug**, then
      the fix turns it green.
- [ ] `make test` passes at 100% - no `markTestSkipped()` or `markTestIncomplete()`.
- [ ] Edge cases covered: `null`, empty strings, unicode, boundaries.

## 💾 3. Schema / Migrations

- [ ] If you touched an Entity: ran `make migrations-diff` and committed the
      generated file.
- [ ] Reviewed the SQL in `migrations/Version*.php` - especially `DROP` and
      `RENAME` operations (they lose data).
- [ ] Tested with a clean DB: `docker compose down -v && make restart`.
- [ ] **Never modify a migration once pushed to `main`.** If a released
      migration is wrong, create a new migration that corrects it.

## 🔒 4. Security

- [ ] `#[IsGranted('ROLE_ADMIN')]` on any destructive action (delete, reset,
      bulk operations, config changes).
- [ ] `$e->getMessage()` is never leaked in a JSON response - log it internally
      and return a generic message.
- [ ] All user input is validated (email format, URL scheme, length, type).
- [ ] No concatenated SQL - use Doctrine parameter binding.
- [ ] CSRF token on every sensitive form.
- [ ] If you fetch a user-supplied URL: SSRF guard (protocol whitelist +
      `CURLOPT_REDIR_PROTOCOLS`).

## 🎨 5. UI / UX

- [ ] Tested in a real browser: happy path + 1–2 edge cases.
- [ ] Turbo-safe if there's inline JS:
  - IIFE wrapper `(function(){ ... })()`
  - `var` instead of `const`/`let`
  - `.then()` instead of `async/await`
  - Declarative `data-bs-toggle` for modals (never `new bootstrap.Modal()`)
  - Helpers `bindDoc(event, key, handler)` for global listeners
  - `window._prismarr*Timer` + `turbo:before-render` cleanup for polling
- [ ] User-facing error messages go through the translator (`trans()`), in plain language, no technical jargon.
- [ ] Any dynamic value spliced into an `innerHTML` template runs through `window.escHtml()` (escapes `& < > " '`).
- [ ] Dark **and** light theme both checked.
- [ ] Responsive verified on at least one mobile viewport.

### 5.1 Multi-instance Radarr / Sonarr

Since v1.1.0, `RadarrClient` and `SonarrClient` are autowired *unbound* —
they only point at a real instance once `bindInstance($i)` (mutating, used
by `MultiInstanceBinderSubscriber` for slug-aware routes) or
`withInstance($i)` (immutable clone, used in dashboard / calendar / search
where the same request iterates over every instance) has been called.

- [ ] Inside a slug-aware route (`/medias/{slug}/…`), the autowired client
      is already bound — call its methods directly. The subscriber 404s
      unknown slugs before the controller runs.
- [ ] Outside a slug-aware route, **never** call the autowired client
      directly: it points at whichever instance was last bound and is
      effectively undefined. Iterate `ServiceInstanceProvider::getEnabled()`
      and call `withInstance($i)->method()` per loop iteration.
- [ ] Items present on multiple instances are deduped by `tmdbId` (movies)
      or `tvdbId` then `tmdbId` (series) — same key Phase D uses
      everywhere (dashboard, calendar, search, qBit resolver).
- [ ] If the data needs to know which instance it came from (badge,
      "open in Radarr" link, owner status) carry the slug alongside the
      record — the slug is the primary key the frontend uses to round-trip.
- [ ] iCal `UID`s and any persistent identifier surfaced to the user must
      be rooted on `tmdbId` / `tvdbId`, not on the per-instance internal
      Radarr/Sonarr `id`, so the same item stays stable across instance
      swaps.

## 📚 6. Docs & Git

- [ ] Commit message is clear and self-contained (one or two lines describing
      the change and its motivation).
- [ ] `README.md` updated if the feature changes user-facing behaviour.
- [ ] `CHANGELOG.md` updated under `[Unreleased]` with a one-liner entry.
- [ ] `git push` immediately after the commit (no local-only commits).

---

## When you hit any of these situations, stop

- You catch yourself saying *"I'll add the test later"* → **no**, add it now.
- You want to tweak an already-pushed migration → **no**, write a new one.
- A user-supplied input is going into a URL request → **no**, SSRF-guard it.
- A JSON error response is about to contain an exception message → **no**,
  strip it and log internally.

---

## The workflow in one sentence

> Feature works → tests green → migration reviewed → security checked →
> UI tested → docs updated → commit message in English or French → push.

---

## The single command before every commit

```bash
make check
```

Runs PHP lint + Twig lint + full PHPUnit suite in ~2 seconds. If it fails,
do not commit. Fix first.

A GitHub Actions CI workflow runs `make check` on every PR and blocks merging
if it fails (`.github/workflows/ci.yml`) - the same contract, enforced.

---

## Evolving this workflow

This document is not frozen. As the project grows (new tooling, new types
of tests, new security concerns), amend it:

1. Propose the change in a PR, motivated by a real failure or a new risk.
2. Update `CONTRIBUTING.md` + `Makefile` + `.github/workflows/ci.yml` together.
3. Announce the change in `CHANGELOG.md` under a `### Contributor`
   heading so existing contributors notice it.
4. Do not quietly lower the bar - always raise it. If a check becomes
   redundant, replace it with something stronger, not nothing.

---

## 🔒 Absolute golden rules (non-negotiable, no exceptions)

These five rules override every other concern, including urgency. Breaking them
is what kills a public project. If a contribution requires violating any of
them, reject it and propose an alternative.

1. **No credentials** in code, commits, logs, docs, or messages. Prismarr's
   repo is public; a leaked secret is exploited by a bot within minutes.
   Credentials live in `.env.local` (gitignored) or the `setting` DB table.

2. **No modified migrations after push.** Once a `migrations/Version*.php`
   is on `main`, it is immutable. To fix a bad schema, write a NEW migration.
   Editing a released migration corrupts every user's database on upgrade.

3. **No destructive or breaking changes for production users.** Covers:
   renaming a public env var, renaming a `setting` DB key (users lose their
   config), changing the default port, breaking the `.env.local` /
   `prismarr.db` format without a migration path, removing a feature without
   a deprecation cycle, hard-deleting entities instead of soft-deleting.
   **If `docker compose pull` can break an existing install, the change
   needs a documented migration plan or must be rethought.**

4. **`make check` must be green before every commit.** Runs lint + full
   PHPUnit suite. Completes the Definition of Done checklist above - no
   test for new logic = no commit. No regression test for a bug fix = no
   commit.

5. **No silent weakening of existing security checks.** CSP, CSRF,
   rate-limiter login, SSRF guard, ProfilerGuard, CURLOPT_REDIR_PROTOCOLS,
   `ROLE_ADMIN` attributes - each one has a documented reason (see commit
   history for Sessions 7a–7d + 8a). If a check blocks a legitimate feature,
   refine it (e.g. extend the CSP with justification) - do not disable it.

Violating any of these, even if `make check` passes, makes the commit invalid.
