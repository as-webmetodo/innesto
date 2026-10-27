# AGENTS.md

Guidance for AI coding agents working in this repository.

## Project status

Design is complete; implementation has not started. There is no code, build
system, test suite, or linter yet. Sources of truth: the accepted spec
`docs/specs/2026-10-17-tracer-bullet-design.md` (the first slice; its §8 lists
where it changed a ticket), `GLOSSARY.md`, `docs/adr/`, and the wayfinder map
(GitHub issue #1) whose closed tickets hold the rationale of each decision.
Commands and stack choices not decided below are confirmed with the user
first, then recorded in this file.

Decided: license is **MPL 2.0** for the whole repo; contributions use a DCO
sign-off (`git commit -s`); every source file carries
`SPDX-License-Identifier: MPL-2.0` (rationale: issue #5).

Decided: PHP floor is **8.3**, raised when a branch leaves php.net security
support; every PHP file uses `declare(strict_types=1)`; only always-enabled
extensions may be required (rationale: issue #8).

Decided: storage uses SQLite where available and safe, guarded flat files
otherwise, behind one Roots storage API; one Store per Module
(rationale: issue #9).

Decided: tests use **Pest** (PHP), **Vitest** in browser mode and
**Playwright** (JS, end-to-end against an Apache+PHP container), and
**PHPStan** at max level; Composer and npm are dev-only, never shipped or run
on the host; tests live beside their owner (`modules/<id>/tests/`); CI is
GitHub Actions (rationale: issue #16).

Decided (2026-10-27, issue #25): **Pest 4** (not 5, which needs PHP 8.4),
**PHPStan 2.3**, **Vitest 5** with `@vitest/browser-playwright`,
**Playwright 1.64**, browsers **Chromium and WebKit**, **Node 26** pinned in
`.nvmrc`; constraints are caret on the minor and both lock files are
committed. CI runs Pest and PHPStan through `shivammathur/setup-php` on PHP
8.3, 8.4 and 8.5, and Playwright against the container on the same matrix.
The container is the official `php:<version>-apache` image, orchestrated by
`compose.yaml` (Docker or Podman); the no-optional-extensions job uses our own
image with PHP 8.3 built from source with `--disable-all`, because the
official image compiles in `pdo_sqlite`, `mbstring`, `openssl` and `curl`; the
`.htaccess`-ignored job mounts an `AllowOverride None` Apache config. Commands:
`composer test` (Pest, including `arch()`, `strict_types` and SPDX checks),
`composer analyse` (PHPStan), `npm run test:browser` (Vitest),
`npm run test:e2e` (Playwright, starts the container), and `composer check` /
`npm test` to run each side in full.

## What Innesto is

A suite of dynamic micro-modules for static sites, deployed on cheap **shared
PHP hosting via FTP**. These constraints shape every design decision:

- **No MySQL, no mandatory terminal/CLI step, no build step on the server.**
  Data lives in local files or SQLite. Anything that needs Composer, Node, or a
  shell on the host breaks the core promise.
- **No external SaaS dependency** for core features.

## Architecture (planned)

- **Innesto Roots** — the core/hub uploaded once to the server. It installs and
  activates modules, holds shared config (SMTP, anti-spam, timezone,
  credentials), generates embed snippets for the developer, and provides the
  client's access point (PIN or Magic Link).
- **Modules**, namespaced `in.<name>`, each depending on Roots:
  - `in.modulo` — form mailer (visual field/validation configurator, SMTP,
    cookie-less anti-spam, webhook/Telegram notifications, temporary message log).
  - `in.agenda` — availability/booking calendar with two-way iCal (`.ics`) sync.
  - `in.hours` — opening hours with real-time status, Schema.org
    `LocalBusiness` output, extraordinary closures.
  - Future: `in.notice`, `in.menu`, `in.gallery`.

Cross-cutting conventions every module must honour:

- **Dual-key UX**: a developer side (full config, code, styling) and a stripped
  client side (mobile PWA) for daily operations that cannot break the layout.
- **Four integration surfaces from the same module**: Web Components (static
  HTML/SSG), plain HTML fragments from endpoints (htmx/Unpoly), direct PHP
  include (SSR), and optional JSON (Alpine.js/fetch). Design endpoints so one
  rendering path serves all four rather than four implementations.
- **Non-intrusive styling**: semantic HTML, CSS custom properties, optional
  utility-class mapping, full template overrides.

## Documents

- Design docs / specs: `docs/specs/YYYY-MM-DD-<name>-design.md`
- Implementation plans: `docs/plans/YYYY-MM-DD-<name>.md`

## Git

Remote: `as-webmetodo:as-webmetodo/innesto.git` (SSH alias for the work
account). Branch flow: commit directly to `main` (decided 2026-10-16). Never
push; pushing is the maintainer's action.

## Agent skills

### Issue tracker

Issues are tracked in GitHub Issues for `as-webmetodo/innesto`, using the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five default triage labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `GLOSSARY.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
