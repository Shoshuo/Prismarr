# Contributing to Prismarr

> Definition of Done — every feature, fix or refactor must pass this checklist
> before being considered complete and pushed to `main`.

Prismarr is a self-hosted app published publicly on Docker Hub. Quality and
security directly affect real users who run this on their homelab. Respect
the process — "we'll fix it later" becomes a user-reported bug in v1.x.

---

## 🔧 1. Code

- [ ] The feature works end-to-end, tested manually in a running container.
- [ ] No leftover `console.log`, `dd()`, `var_dump`, `dump()`, `TODO`, `FIXME`.
- [ ] Comments are written in **English** (UI strings stay in French until i18n).
- [ ] **Zero credentials** in code, commits, logs, docs (sacred rule).
- [ ] No unused `use` / imports.

## 🧪 2. Tests

- [ ] At least **one unit test** for any new business logic.
- [ ] For a bug fix: **a regression test that first reproduces the bug**, then
      the fix turns it green.
- [ ] `make test` passes at 100% — no `markTestSkipped()` or `markTestIncomplete()`.
- [ ] Edge cases covered: `null`, empty strings, unicode, boundaries.

## 💾 3. Schema / Migrations

- [ ] If you touched an Entity: ran `make migrations-diff` and committed the
      generated file.
- [ ] Reviewed the SQL in `migrations/Version*.php` — especially `DROP` and
      `RENAME` operations (they lose data).
- [ ] Tested with a clean DB: `docker compose down -v && make restart`.
- [ ] **Never modify a migration once pushed to `main`.** If a released
      migration is wrong, create a new migration that corrects it.

## 🔒 4. Security

- [ ] `#[IsGranted('ROLE_ADMIN')]` on any destructive action (delete, reset,
      bulk operations, config changes).
- [ ] `$e->getMessage()` is never leaked in a JSON response — log it internally
      and return a generic message.
- [ ] All user input is validated (email format, URL scheme, length, type).
- [ ] No concatenated SQL — use Doctrine parameter binding.
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
- [ ] User-facing error messages in French, no technical jargon.
- [ ] Dark **and** light theme both checked.
- [ ] Responsive verified on at least one mobile viewport.

## 📚 6. Docs & Git

- [ ] Commit message in **French**, format: `Feature|Fix|Docs : description courte`.
- [ ] **Zero** `Co-Authored-By: Claude` in the commit message.
- [ ] `CLAUDE.md` / `PROGRESSION.md` updated if the session is wrapping up.
- [ ] `README.md` updated if the feature changes user-facing behaviour.
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
> UI tested → docs updated → commit in French → push.

---

## The single command before every commit

```bash
make check
```

Runs PHP lint + Twig lint + full PHPUnit suite in ~2 seconds. If it fails,
do not commit. Fix first.

Starting in v1.1, a GitHub Actions CI workflow will run `make check` on every
PR and block merging if it fails — the same contract, enforced.

---

## Evolving this workflow

This document is not frozen. As the project grows (new tooling, new types
of tests, new security concerns), amend it:

1. Propose the change in a PR, motivated by a real failure or a new risk.
2. Update `CONTRIBUTING.md` + `Makefile` + `.github/workflows/ci.yml` together.
3. Announce the change in `CHANGELOG.md` under a `### Contributor`
   heading so existing contributors notice it.
4. Do not quietly lower the bar — always raise it. If a check becomes
   redundant, replace it with something stronger, not nothing.

---

## Non-negotiable rules (even under pressure)

- **No credentials** in code, commits, logs, or docs — ever.
- **No modified migrations after push** — create a new migration to fix.
- **No bug fix without a regression test** — write the test first (red), then
  fix (green).

These three carry the same weight as the rest of the checklist combined.
If `make check` is green but any of these are violated, the commit is invalid.
