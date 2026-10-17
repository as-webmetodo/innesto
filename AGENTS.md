# AGENTS.md

Guidance for AI coding agents working in this repository.

## Project status

Innesto is in the **concept & architectural design** phase. There is no code,
build system, test suite, or linter yet. Sources of truth: the brief
`README.md`, `GLOSSARY.md`, `docs/adr/`, and the wayfinder map (GitHub issue
#1) whose closed tickets hold each design decision. Until code exists, work here is
specs and design documents, not implementation; do not invent commands or
stack choices — confirm them with the user and record them in this file once
decided.

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

## Known inconsistency in the brief (README.md)

The "Project Status" section names the first packages *Mail / Book / Hours*,
while the ecosystem section names them `in.modulo` / `in.agenda` / `in.hours`.
Ask which naming is canonical before using either in specs or code.

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
