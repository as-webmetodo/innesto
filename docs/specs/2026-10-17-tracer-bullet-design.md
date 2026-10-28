# Tracer-bullet design: Innesto Roots, integration conventions, Innesto Orario

Status: accepted · Map: [#1] · Ticket: [#24]

This spec covers the first end-to-end slice of Innesto:

> **FTP upload → Module activation → Embed on a Site → the Client edits hours from a phone.**

It brings together the decisions resolved on the wayfinder map ([#2]–[#23]). It does not argue them again: each section links the ticket that holds the rationale. Terms are used as defined in [`GLOSSARY.md`](../../GLOSSARY.md). Where this spec settles something a ticket left open, it says so and points to [§8](#8-gaps-settled-by-this-spec).

## 1. Constraints

These hold for every section below ([`AGENTS.md`](../../AGENTS.md), [#2]):

- Deployment is to shared PHP hosting over FTP. There is no MySQL, no mandatory CLI step, no build step on the server, and no external SaaS for core features.
- PHP floor is **8.3**. It is raised when a branch leaves php.net security support. CI runs 8.3, 8.4 and 8.5. Every PHP file starts with `declare(strict_types=1)` and carries full type declarations ([#8]).
- Roots requires only the always-enabled extensions (core, `date`, `hash`, `json`, `pcre`, `random`, `SPL`, `standard`). Everything else is detected and has a per-feature fallback ([#8]). `intl` and `mbstring` are never used ([#10]).
- Every source file carries `SPDX-License-Identifier: MPL-2.0`, including templates, CSS and browser JS. The license is MPL 2.0 and contributions are DCO-signed ([#5], [ADR-0001](../adr/0001-mpl-2-0-license.md)).
- Composer and npm are dev-only. The release zip is built with `git archive`, and `export-ignore` strips tests, tooling and dev dependencies ([#16]).
- HTTPS is required. The only exception is an explicit override for `localhost` ([#7]).
- One install serves one Site and one business. Installs may sit side by side ([#7]).

## 2. The slice, step by step

This is the flow the Playwright end-to-end test walks ([§7](#7-tests-that-prove-the-tracer-bullet)). Each step links the section that specifies it.

1. **Upload.** The Developer unzips the release and uploads `innesto/` (Roots, with `modules/in.hours/` inside) into the webroot over FTP, e.g. as `https://example.com/innesto/` ([§3.1](#31-files-on-the-server)).
2. **First run.** The Developer opens `https://example.com/innesto/`, which redirects to `dev/`. Roots picks the Data directory, writes a Setup code into it and asks for that code. The Developer reads it over FTP and enters it with a new Developer password ([§3.6](#36-authentication-and-sessions)).
3. **Self-check.** The setup page runs the Self-check, including the browser-run HTTP probes. A Fail blocks setup. Warns are listed with fix hints. The storage backend is chosen and the reason is shown ([§3.11](#311-self-check)).
4. **Shared config.** The Developer confirms the Install timezone (pre-filled from the browser), the Site URL, the Default and Enabled locales, and the Business profile. All of these can be edited later ([§3.5](#35-shared-config-and-roots-settings)).
5. **Activate.** The Modules list shows Innesto Orario as Uploaded. Activate checks its requirements, creates its Store and seeds a "Main" Schedule ([§3.7](#37-modules-discovery-manifest-lifecycle)).
6. **Schedule.** In Orario's Developer Screens, the Developer names the Schedule, keeps or edits its Schedule key, sets its timezone and enters its Weekly hours ([§4.6](#46-developer-screens)).
7. **Embed.** In the Embed code generator, the Developer picks *Innesto Orario → week → Schedule "main" → locale*. They copy the static-HTML Embed code (the stylesheet link, two module scripts, and `<in-hours>` with its fallback line) into a page of the static Site ([§3.10](#310-embed-code-generator)).
8. **Visitor.** On the Site, the empty `<in-hours>` fetches its fragment from `view.php`, replaces itself with it, and enhances it with the current Status picked from the Status timeline ([§3.8](#38-rendering-one-path-four-surfaces)).
9. **Client PIN.** The Developer generates the Client PIN. It is shown once, next to a QR code of the `app/` URL, and the Developer hands both to the Client ([§3.6](#36-authentication-and-sessions), [§3.13](#313-client-pwa-shell)).
10. **Phone.** The Client scans the QR, enters the PIN on the keypad and lands on the Launcher, which shows one tile per Schedule with its live Status. They can install the PWA from the install hint ([§3.13](#313-client-pwa-shell)).
11. **Edit.** The Client opens the Schedule and taps "Closed today". Roots confirms the save with "Saved: visible on the Site within N minutes" ([§4.7](#47-client-screens)).
12. **Propagation.** Within N minutes (the Visitor `max-age`), the Site's Embed shows the Closure and the Status reads closed ([§3.9](#39-http-behaviour)).

## 3. Innesto Roots

### 3.1 Files on the server

```
innesto/                        ← <roots>, e.g. https://example.com/innesto/
  index.php                     redirect to dev/                                   [#19]
  view.php                      Visitor endpoint for every View                    [#19]
  asset.php                     static JS/CSS/images from assets/ folders          [#19]
  embed.php                     defines innesto_render(); no-op over HTTP          [#20]
  data-path.php                 absolute Data directory path, written at setup     §8
  dev/index.php                 Developer area (incl. setup)                       [#19]
  app/index.php                 Client PWA; manifest + service worker under app/   [#19][#21]
  src/                          Roots PHP (namespace Innesto\Roots, PSR-4)
  templates/  locales/          Roots shell templates and catalogues
  assets/                       base class innesto.js, shell CSS/JS, default icons
  modules/
    in.hours/
      module.php                Manifest                                           [#13]
      src/                      PHP, PSR-4 from the Manifest namespace             [#13]
      templates/                View + Screen templates (plain PHP)                [#12]
      locales/                  it.php, en.php, en-GB.php                          [#10]
      assets/                   in-hours.js, in-hours.css                          §8
      tests/                    stripped from the release by export-ignore         [#16]
  overrides/
    in.hours/                   templates/, locales/, override.php                 [#23]
  data-<random>/                only when no writable directory exists outside the webroot [#9]
```

- The thin stub entry points (`index.php`, `view.php`, `asset.php`, `dev/index.php`, `app/index.php`) all call one front controller in `src/`. There is no URL rewriting ([#19]).
- `src/`, `templates/`, `locales/`, `modules/`, `overrides/` and any in-webroot Data directory get an `.htaccess` deny-all. Because some hosts ignore `.htaccess` ([#2]), every PHP file in those trees that would output anything if requested directly (templates, catalogues, Manifests, `override.php`) begins with a guard that exits unless Roots defined its bootstrap constant. A direct request therefore prints nothing on any host. Class files only declare symbols ([§8](#8-gaps-settled-by-this-spec)).

### 3.2 Data directory and Stores

- **Location** ([#9]): at setup, Roots walks up from `<roots>` looking for a writable directory outside the webroot and creates `innesto-data-<random>/` in it, so side-by-side installs ([#7]) never share one. If there is none, it creates `<roots>/data-<random>/` with layered defences. The chosen absolute path is written to `<roots>/data-path.php`, which the Developer may edit over FTP to move it ([§8](#8-gaps-settled-by-this-spec)). If `<roots>` is not writable, setup shows the exact contents of `data-path.php` for the Developer to upload over FTP.
- **HTTP proof** ([#9], [#22]): two probe files in the Data directory, both fetched by the Developer's browser ([§3.11](#311-self-check)). A **non-`.php` probe** that is reachable is a Warn and forces the flat-file backend, whose files are all guarded `.php`. A **guarded `.php` probe** that returns any content is a Fail, and setup refuses to finish.
- **Contents**: Roots' two Stores (content and runtime, [§3.4](#34-roots-own-stores)) and one Store per Module that has ever been activated, the Roots log (two generations, [§3.12](#312-roots-log-and-health-screen)), the Setup code file while it is live, the Developer reset marker when one is uploaded, the uploaded Client PWA icon, and the protected config for recoverable secrets (none in the tracer bullet).
- **Backend** ([#9], [ADR-0002](../adr/0002-dual-storage-backend.md)): **SQLite** is used when `pdo_sqlite` is present, SQLite is 3.27 or later, and a non-`.php` probe file in the Data directory proved unreachable over HTTP. **Guarded flat files** (`<?php exit; ?>` followed by JSON) are used otherwise. The choice is automatic and explained. The Developer may deliberately downgrade to files. It stays fixed for the life of the install.
- **Write safety** ([#9]): the file backend keeps each Store in one guarded file with one lock file. A transaction takes the lock with `flock`, reads the file, runs entirely in memory, then writes a temp file and commits with a single atomic `rename`; a throw discards the in-memory copy. Stores are small, so rewriting the whole file is cheap. SQLite uses the default rollback journal (never WAL) with a `busy_timeout` of a few seconds. Schemas stay within SQLite 3.27: no `RETURNING`, `DROP COLUMN`, generated columns or STRICT tables.

### 3.3 Store API

The collection-of-documents API is owned by Roots. Modules never touch SQL or files ([#9]).

- A **document** is a JSON-serialisable array with a string id, in a named **collection**.
- Operations: `get`, `put` (create or replace), `delete`, `list` (equality and range filters on declared fields, ordering, limit), and `transaction(callable)`. Nothing else, so both backends serve the whole API.
- **Declared fields**: each collection declares the fields it filters or orders on, with their types (Manifest, [§3.7](#37-modules-discovery-manifest-lifecycle)). SQLite indexes them. The file backend scans and filters in PHP. A declared field may never be `null` or missing: `put` rejects such a document, so filters never need a `null` semantics.
- **Store schema version** (stored-data versioning, required even though upgrades are out of scope, [#9], [#13]): each Store records the schema version it was created with. Roots' own Stores carry Roots' schema version. A Module's Store carries the version from its Manifest. A mismatch Halts the Module. Nothing migrates it.
- **Store revision**: a counter in each Store, bumped by every committed write transaction. It feeds the `ETag` ([§3.9](#39-http-behaviour)). This is the only thing a save does for propagation ([#17]).
- **Isolation**: a Module receives only its own Store, through its context object ([§3.7](#37-modules-discovery-manifest-lifecycle)).
- One contract test suite proves both backends behave identically ([§7](#7-tests-that-prove-the-tracer-bullet)).

### 3.4 Roots' own Stores

Roots keeps two Stores, so that operational writes never change what Visitors see ([#13], [#14], [#20], [#22]):

- **Content Store**: Shared config, Roots settings, each Module's state (Uploaded / Active / Inactive), the Developer password hash and the Client PIN hash. Its Store revision feeds the `ETag` ([§3.9](#39-http-behaviour)).
- **Runtime Store**: session token hashes with their expiry, short-lived throttling state, the latest Self-check results with their time, and per-Module error counts with the last error. Its revision never enters the `ETag`. A session's sliding expiry is written at most every 5 minutes.

### 3.5 Shared config and Roots settings

Both tiers are edited by the Developer in the Developer area and live in Roots' content Store, so any change bumps its Store revision ([#20]).

| Shared config: every Module reads it, none changes it | Roots settings: Roots only, never passed to Modules |
|---|---|
| **Business profile**: name, Schema.org type (curated list of `LocalBusiness` subtypes, default `LocalBusiness`), structured postal address, phone, URL (defaults to the Site URL), optional image. No Localised text ([#18]). | Developer password hash and Client PIN hash ([#14]) |
| **Default locale** and **Enabled locales** ([#10]) | **Visitor `max-age` N**: default 5 min, range 1–60 ([#19]; placed here by [#20]) |
| **Install timezone**, used only as the starting Schedule timezone ([#20]) | **Extra CORS origins**. The Site URL's origin is always allowed ([#7], [#20]) |
| **Site URL**, defaulting to the root of Roots' origin ([#20]) | Client PWA icon (one square PNG of at least 512 px, [#21]) |

- A locale can be enabled only when Roots and every Active Module cover it. Activation reports any gap ([#10]).
- Mail: the tracer bullet has no SMTP settings ([§3.14](#314-mail-service)).

### 3.6 Authentication and sessions

From [#14], with the URL scoping from [#19].

**Developer**
- There is exactly one Developer password and no username. It needs at least 12 characters, with no composition rules.
- **First run**: Roots writes a random one-time Setup code into a guarded file in the Data directory. The setup form asks for it together with the new password. The code is single-use, deleted on completion, and throttled like sign-in.
- **Recovery**: the Developer uploads a reset marker file into the Data directory over FTP. Roots then issues a fresh Setup code and the first-run flow repeats. The old password stays valid until the reset completes.
- Changing the password signs out every Developer session. A wrong current password counts as a sign-in failure ([§8](#8-gaps-settled-by-this-spec)).

**Client**
- One shared **Client PIN** of 8 digits, generated by Roots, stored only as a `password_hash` hash and shown once.
- The Developer (Developer area) and the Client (Account tile) may regenerate it. Regenerating signs out every other Client device and keeps the current one signed in. This is also how a lost phone is cut off.
- No Magic Link (out of scope, [§9](#9-out-of-scope)).

**Sessions**
- Roots owns the sessions; native PHP sessions are not used. The cookie holds a random token and is `HttpOnly`, `Secure` and `SameSite=Strict`. Only the token's hash is stored.
- The Developer cookie has `Path=<roots>/dev/` and the Client cookie has `Path=<roots>/app/`, so `view.php` and `asset.php` never receive a cookie ([#19]).
- Idle expiry is sliding: **Client 90 days**, **Developer 12 hours**. Expired sessions are deleted at each sign-in.

**Throttling**: after a few failures from one IP, further attempts are refused at once with `429` and `Retry-After` for a growing interval, plus a global rate cap. Roots never waits server-side (no `sleep()`). `dev/` and `app/` keep separate counters, so an attack on one never slows the other. It never locks the account. Failures are logged and counted ([#14], [#22]). Starting values are in [§8](#8-gaps-settled-by-this-spec).

**CSRF**: a per-session synchronizer token in every Roots form, `SameSite=Strict`, and an Origin/Referer check against Roots' origin. All of it sits in Roots' form-post routing, so Modules never see tokens ([#14]). Forms posted before a session exists rely on the last two ([§8](#8-gaps-settled-by-this-spec)).

**Framing**: the Developer area and the Client PWA send `Content-Security-Policy: frame-ancestors 'none'` ([#7]).

**Who may do what**: the Developer reaches every Screen, client ones included, and alone activates, deactivates and deletes Module data. The Client reaches only `client` Screens. A Visitor is never authenticated. The **Actor** in the context is just `developer`, `client` or absent ([#14]).

### 3.7 Modules: discovery, Manifest, lifecycle

From [#13], extended by [#19] (View options), [#21] (tiles) and [#23] (CSS).

**Discovery**: Roots scans `modules/` whenever the Developer area loads. The folder name must equal the Package id: `in.<name>` (reserved for official Modules) or `<vendor>.<name>`, each part matching `[a-z][a-z0-9]*`. The custom element is derived from it (`in.hours` → `<in-hours>`). A broken Manifest or a mismatched id lists that one Module as Halted. Discovery never fails as a whole.

**Manifest** (`module.php` returns a plain array, read without running any Module code). For Innesto Orario:

```php
<?php
// SPDX-License-Identifier: MPL-2.0
declare(strict_types=1);
defined('INNESTO_ROOTS') || exit;

return [
    'id'           => 'in.hours',
    'name'         => 'Innesto Orario',
    'version'      => '1.0.0',
    'requires'     => ['roots_api' => 1, 'php' => '8.3', 'capabilities' => [], 'optional' => []],
    'namespace'    => 'Innesto\\Hours',
    'entry'        => 'Innesto\\Hours\\Module',
    'views'        => [
        'week'   => ['v' => 1, 'options' => ['schedule' => ['type' => 'string', 'required' => true]]],
        'status' => ['v' => 1, 'options' => ['schedule' => ['type' => 'string', 'required' => true]]],
        'jsonld' => ['v' => 1, 'options' => ['schedule' => ['type' => 'string', 'required' => true]]],
    ],
    'screens'      => [
        'schedules' => 'developer', 'schedule' => 'developer',
        'home' => 'client', 'regular' => 'client', 'exception' => 'client',
    ],
    'tiles'        => true,
    'store'        => [
        'schema'      => 1,
        'collections' => [
            'schedules'  => ['fields' => ['position' => 'int']],
            'exceptions' => ['fields' => ['schedule' => 'string']],
        ],
    ],
];
```

The key names are illustrative and the plan fixes them. What each one carries is decided: a View option may not use a reserved name (`module`, `view`, `lang`, `format`, `v`), and a Manifest that declares one is Halted ([#19]).

**Code loading**: Roots registers a PSR-4 autoloader mapping the Manifest namespace to `modules/<id>/src/`, with no Composer. Only Active Modules are loaded. The entry class implements Roots' `Module` interface.

**Lifecycle**

| From | Action (Developer only) | Result |
|---|---|---|
| Uploaded | **Activate** | Roots checks requirements and names each unmet one. It creates the Store from the Manifest, then calls the optional `onActivate(context)` in a transaction (Orario seeds a "Main" Schedule). If anything throws, everything rolls back and the Module stays Uploaded. → **Active** |
| Active | **Deactivate** | Nothing of the Module runs. Its Store is kept. → **Inactive** |
| Inactive | **Activate** | → **Active**, with the existing Store |
| Inactive | **Delete data** (confirmed) | The whole Store is removed. → **Uploaded** |

- **Halted** applies in any state when the Manifest is broken, a requirement is unmet, or the Manifest's Store schema version differs from the Store's. Roots runs nothing of the Module and the Developer area says why. Nothing fixes it automatically.
- **Inactive, Halted, unknown View or unknown Schedule** all give the same Visitor outcome: `view.php` answers `404` with an empty body, `innesto_render()` returns `''`, and the loader keeps its fallback ([#12], [#13]).
- No deactivate or delete hooks. No Module→Module dependencies.

**Context object** (the whole Module API, injected, fakeable in tests, [#13]): the Module's own Store; its settings (a document in its Store); Shared config (read-only); i18n (catalogue lookup, Innesto's formatter, plural rules); the template render and escaping helper; a logger (tags lines with the Package id); a **clock**; the Actor; and the mail service, but only for Modules that declare the `mail` capability ([§3.14](#314-mail-service)).

**Fault fence**: every call into Module code is wrapped. Any `Throwable` is logged with context, counted, and shown as "last error and a count" in the Developer area. That request gets the empty fallback, but the Module **stays Active**. PHP fatals are recorded by a shutdown hook but cannot be caught ([#13], [#22]).

**Screens**: plain-PHP templates rendered inside Roots' shell, with optional static JS. Roots owns layout, navigation, the authentication gate, CSRF and form-post routing ([#13]).

**Tiles**: a Module whose Manifest declares tiles returns a list of tile data from its entry class: title, summary text, tone (`open`/`closed`/`neutral`), target client Screen and params. Roots renders every tile the same way. A failing hook shows a neutral tile and the error is logged ([#21]).

**Option choices for the generator**: for options whose values change (Orario's `schedule`), the entry class supplies the choices through a hook ([#20]).

### 3.8 Rendering: one path, four surfaces

From [#12], with [#4]'s light-DOM finding.

- Each **View** builds one **view model**: a plain PHP array holding raw values (ISO instants, open/closed), sentences already localised in the resolved locale, and, where relevant, the Status timeline. It is the single source of truth.
- **HTML** is the View's plain-PHP template applied to the view model, using Roots' escaping helper. PHP include, htmx/Unpoly and the Web Component all receive **the same HTML**.
- **The fragment's root** is `<in-hours lang="…" data-in-rendered>`. The Status timeline travels as a child `<script type="application/json">` with the same content as the JSON `timeline` field.
- **JSON** is the view model in a versioned envelope: `{"v":1,"module":"in.hours","view":"week","locale":"it","data":{…}}`. Each View's `data` shape is a public contract, versioned by `v`.
- **Server-rendered markup never contains time-relative text** ("open now", "today", "tomorrow"). The Status line is empty in the HTML and is filled only by the enhancer ([#11]).

**Web Component** (one light-DOM element per Module, served as a static ES module extending Roots' base class from `assets/innesto.js`):
- **Empty** element = *loader*. It derives Roots' URL from the base-class script's `import.meta.url`. It sends only `view`, `lang` and the option names its ES module lists for that View, then replaces itself with the fragment (`outerHTML`). It copies its non-option attributes (`class`, `id`, `style`, `data-*`, …) onto the new root, where the fragment's own `lang` and rendered marker win and `class` is merged with the fragment's own classes rather than replacing them ([#20]). If the fetch fails, the Developer's fallback content stays.
- **Rendered** element = idempotent *enhancer*. It reads the timeline, shows the segment containing "now" on the Visitor's device clock, sets a timer for the next boundary, re-checks on `visibilitychange`, and shows nothing when "now" falls outside the timeline. It toggles only its own variant classes and cleans up in `disconnectedCallback` ([#11], [#23]).
- No shadow DOM and no declarative shadow DOM ([#4]).

**PHP include**: `embed.php` defines `innesto_render(string $module, string $view, array $params = []): string`. `$params` takes exactly the URL's keys, including `lang`. It runs the same code as `view.php` without HTTP, returns the fragment or `''`, and sends no headers ([#20]).

**Locale resolution** ([#10]): explicit `lang` if it is enabled. Otherwise the Default locale, with a warning logged when a non-enabled locale was requested. `Accept-Language` is never consulted for public output.

### 3.9 HTTP behaviour

From [#19], with the ETag inputs from [#18] and [#23].

**`view.php`**: `?module=in.hours&view=week&schedule=main&lang=it`, plus `&format=json&v=1` for JSON.
- GET and HEAD only. There is no `Accept` negotiation, and `format` defaults to HTML.
- Strict validation: an unknown option, an invalid value, a missing required option, an unknown View or Schedule, a JSON request without `v` or with a `v` other than the View's current one, or an Inactive or Halted Module all give **`404`, empty body, `Cache-Control: no-store`**.
- `200`: `Cache-Control: public, max-age=N`, with no `stale-while-revalidate`. The same N applies to fragments, JSON and `jsonld`.
- **`ETag`**: a hash of the Module's Store revision, Roots' content Store revision, the Roots and Module versions, the normalised request parameters, the **current UTC quarter-hour** (every local midnight, in zones offset by :30 or :45 too, falls on a bucket boundary), and the paths, mtimes and sizes of the Module's override files. All inputs are known before rendering, so `If-None-Match` answers `304` without a render.
- **CORS**: for an allowed origin, `Access-Control-Allow-Origin: <origin>` and `Vary: Origin`. `OPTIONS` preflights are answered for allowed origins, since htmx and Unpoly add headers. No credentials are ever accepted ([#7]).

**`asset.php`**: `?module=in.hours&file=in-hours.js&ver=1.0.0` (`module=roots` for Roots' files).
- Serves only regular files directly inside that owner's `assets/` folder, after canonicalising the name and rejecting any path separator, with extensions whitelisted as `.js`, `.css`, `.png` and `.svg` ([§8](#8-gaps-settled-by-this-spec)).
- Sends `Access-Control-Allow-Origin: *` and `Cache-Control: public, max-age=31536000, immutable`. `ver` busts the cache.

**Developer area and Client PWA**: `dev/?screen=…` and `app/?screen=…` (plus Module and params), POST-redirect-GET for forms (a refused post re-renders its form with `422` or `429`, [§8](#8-gaps-settled-by-this-spec)), and `Cache-Control: no-store` on Screen HTML. `app/` answers the web app manifest and the PWA icon without a session, because the browser fetches them without cookies ([§3.13](#313-client-pwa-shell)); every other `app/` request needs one.

### 3.10 Embed code generator

This is one Developer-area Screen, driven by the Manifest. Third-party Modules get it for free ([#20]). The flow is: pick Module → View → fill the form built from the View's options (choices from the Module hook) → locale (Default preselected, and `lang` left out when it is the Default). Roots does not store or track Embeds. URLs are always absolute on Roots' origin.

Tabs, for `week` / Schedule `main` / `it`:

**Static HTML (loader)**
```html
<link rel="stylesheet" href="https://example.com/innesto/asset.php?module=in.hours&file=in-hours.css&ver=1.0.0">
<script type="module" src="https://example.com/innesto/asset.php?module=roots&file=innesto.js&ver=1.0.0"></script>
<script type="module" src="https://example.com/innesto/asset.php?module=in.hours&file=in-hours.js&ver=1.0.0"></script>
<in-hours view="week" schedule="main" lang="it">Orari: chiamaci al +39 …</in-hours>
```

**PHP include** (labelled "only when the Site's PHP runs on this server", always shown)
```php
<?php require_once '/home/user/www/innesto/embed.php'; ?>
<?= innesto_render('in.hours', 'week', ['schedule' => 'main', 'lang' => 'it']) ?>
```
It also includes the stylesheet link and the two script tags, so the enhancer runs.

**htmx**: the fragment URL, one `hx-get` + `hx-trigger="load"` + `hx-swap="outerHTML"` example, the stylesheet link and the script tags. A caveat notes that htmx 2 blocks cross-origin requests by default (`selfRequestsOnly`), so the loader is the simpler choice cross-origin. Unpoly gets a docs-only example, checked against Unpoly's docs when that page is written.

**JSON**: the URL with the View's current `v`, plus a pointer to that View's documented `data` contract.

Rules:
- The **stylesheet link** is a checkbox, on by default, added to every surface ([#23]).
- The **fallback line** is pre-filled in the chosen locale from the Business profile phone, or with a link to the Site URL when there is no phone. The Developer can edit it before copying. It is never stored and **never a rendered snapshot** ([#17]). The `jsonld` View has no fallback line.
- The **`jsonld` View** uses the same element (`view="jsonld"`). It is placed once per page, server-side whenever possible, and loader-injected on static pages, which is documented as weaker ([#3], [#17]).

### 3.11 Self-check

From [#22], with thresholds settled in [§8](#8-gaps-settled-by-this-spec).

- **When it runs**: the full check runs at setup and from a button on the Health Screen. Cheap server-side probes (PHP version, extensions, SQLite version, limits) re-run on every Developer-area load. Results are stored with their time.
- **HTTP-level probes run in the Developer's browser**, against Roots' own origin: the two Data directory probes (non-`.php` and guarded `.php`, [§3.2](#32-data-directory-and-stores)), `.htaccess` honoured, HTTPS, and clock skew. The browser posts the results back. They are accepted only inside an authenticated Developer session or the Setup-code-gated setup flow.

| Grade | Findings |
|---|---|
| **Fail** (blocks setup) | PHP < 8.3; Data directory unwritable; the guarded `.php` probe returns content over HTTP; no HTTPS (outside the `localhost` override) |
| **Warn** | No usable SQLite (≥ 3.27), so flat files are used · the non-`.php` probe is reachable over HTTP, so flat files are used · `mail()` unavailable · incomplete Business profile (no name or address) · Site URL left at its default · Data directory inside the webroot · `.htaccess` not honoured · clock skew **> 2 min** · `max_execution_time` **< 30 s** (0 is OK) · `memory_limit` **< 64 MB** (-1 is OK) · `upload_max_filesize` or `post_max_size` **< 2 MB** · a Template override whose declared View `v` is outdated or missing ([§3.15](#315-styling-contract-and-overrides)) · an Embed that asked for a locale that isn't enabled ([#10]) |
| **OK** | Everything else |

- `intl` is not probed.
- A Fail found after install is shown on the Health Screen, raises the badge and is logged. Roots keeps serving.

### 3.12 Roots log and Health Screen

From [#22].

- **Roots log**: one append-only guarded file (`<?php exit; ?>` followed by JSON lines) in the Data directory, separate from every Store. Each line holds a UTC timestamp, a per-request id, a level, the source (`roots` or a Package id), an event name, a message and its context.
  - It records errors (fenced faults, PHP warnings through Roots' handler, fatals through a shutdown hook), security events (sign-in failures, throttling, Setup code issued or used, PIN regenerated, password changed) and changes (Activate, Deactivate, Delete data, settings saved, and Client and Developer saves to Module data).
  - There are no per-request lines.
  - When the file passes about 1 MB at write time, it is renamed over one previous generation.
  - Appends and rotation happen under `flock`. The `<?php exit; ?>` header is written whenever the file is empty.
  - IPs are truncated (/24 for IPv4, /48 for IPv6). Throttling keeps the full IP only in short-lived state.
  - Roots turns off `display_errors` for its own requests.
- **Health Screen** (Developer only): the latest Self-check with grades and fix hints, recent errors per Module, sign-in failures and throttling, and a tail of the Roots log with a download of both generations. The Developer-area home shows a badge for any Warn or Fail, or for errors the Developer hasn't seen.
- The Client PWA shows no health information. There are no push alerts.

### 3.13 Client PWA shell

From [#15] and [#21].

- **Launcher** (`app/`, the `start_url`): a grid of tiles from Active Modules, plus Roots' **Account** tile (Regenerate PIN with confirmation, "N other phones will be signed out", the new PIN shown once with "I've written it down"; and "Sign out of this phone"). Inside a Module there is a top bar with a back arrow. There is no tab bar or drawer. When the Developer views client Screens, a band says so.
- **Sign-in**: an 8-dot keypad. Throttling shows "wait N s and try again, your account is never locked".
- **Web app manifest**: `name`/`short_name` come from the Business profile name, falling back to "Innesto". `id` and `start_url` are `app/`. `display: standalone`, with no forced orientation and the shell's theme colour. Icons are the default 192/512 px maskable set plus `apple-touch-icon`, or the Developer's one square PNG of at least 512 px, kept in the Data directory and served as-is with no resizing. Non-square or undersized uploads are rejected. `app/` serves the manifest and the icon without a session ([§3.9](#39-http-behaviour)).
- **Service worker** (scope `app/`): precaches the shell's static CSS, JS, icons and one offline page. It never caches Screen HTML or data.
- **Forms**: the shell's JS submits Screen forms with `fetch`.
  - **Offline** (a banner shows while the device has no connection): a failed Save stays on the form with its input intact and says "Not saved: no connection. Try again when you have signal." There is no queue.
  - **Expired session**: if Roots answers that the session has expired, nothing is written. The PIN pad opens as an overlay over the form, which stays in the page with its input intact. After sign-in the shell puts the new session's CSRF token into the form, closes the overlay and says "Not saved yet: you were signed out. Check and tap Save again." Nothing is stored in the browser ([§8](#8-gaps-settled-by-this-spec)).
  - **Confirmed**: the "Saved: visible on the Site within N minutes" toast appears only after Roots confirms the save. N is the Visitor `max-age` ([#17]).
- A stale cookie gets "You were signed out (unused for 90 days, or the Client PIN was changed)".
- **Freshness**: on returning to the foreground, the Launcher and Module home Screens reload if the page is more than about a minute old, unless a form holds unsaved input.
- **Onboarding**: next to a freshly generated PIN, the Developer area shows the `app/` URL as a QR code, made by a bundled pure-PHP encoder. The QR holds the URL only, never the PIN. After the first sign-in, a dismissible install hint appears: `beforeinstallprompt` on Android and Chromium, or Share → Add to Home Screen on iOS, noting that iOS asks for the PIN once more.

### 3.14 Mail service

[#20] and [#22], narrowed in [§8](#8-gaps-settled-by-this-spec):

- In the tracer bullet the context's mail service is backed by PHP `mail()` only. There are no SMTP settings, no SMTP probe and no anti-spam; those arrive with the Innesto Modulo spec.
- The service is passed only to Modules that declare the `mail` capability. Innesto Orario declares none.
- The Self-check reports the transport (`mail()`, or none when the function is missing or disabled). The Health Screen offers "Send test mail" to an address the Developer types in. Nothing is ever sent unprompted.

### 3.15 Styling contract and overrides

From [#23].

- **Markup** follows [RSCSS](https://rstacruz.github.io/rscss/). `<in-hours>` is the component. Parts are single-word element classes selected through the child combinator (`in-hours > .day`). States are variants (`.status.-open` / `.-closed`). Class and custom-property names are a public contract that changes only in a Module major release. Host global single-word rules may reach these elements, which is accepted and documented.
- **Default stylesheet** (`assets/in-hours.css`, optional): every selector is wrapped in `:where()`. It is themed by `--in-hours-*` properties that fall back to suite tokens (`--in-accent`, `--in-open`, `--in-closed`, `--in-gap`, …), with defaults only in the `var()` fallbacks.
- **Overrides** live in `<roots>/overrides/in.hours/`, blocked from HTTP like `modules/`:
  - `templates/` shadows any template file at the same relative path. A Template override copied from an Innesto template stays MPL ([#5]).
  - `locales/` holds Catalogue overrides, merged key-by-key.
  - `override.php` returns `['views' => ['week' => 1], 'classes' => ['week' => ['day' => '…']]]`: the View `v` each View's Template overrides target, plus the per-View **Class map** (elements only), which the Module's template adds server-side.
- **Drift**: if a View's declared `v` is missing or differs from the current one, Roots ignores that View's Template overrides and renders the Module's own templates. It reports a Self-check Warn and writes a Roots log entry.
- **Targeting one Embed**: wrap it in your own element. The loader's attribute carry-over remains a convenience.

### 3.16 Localisation

From [#10].

- Catalogues are plain PHP arrays, one per owner per locale (`locales/it.php`), with symbolic keys. `en` must always be complete. Per-key lookup goes region → language → the owner's `en`, never the install default.
- Shipped: `it`, `en` (12-hour clock) and `en-GB` (a minimal catalogue that switches to a 24-hour clock).
- Innesto's own formatter covers weekday and month names, times, today/tomorrow/weekday and day-month dates, with its data in the catalogues. Each locale declares a CLDR plural-rule function.
- The Developer area and the Client PWA follow the browser language among the locales Roots ships, falling back to `en`, independently of public output.
- **Localised text** is stored as `{"it": "…", "en": "…"}`. The Default locale's entry is required, and the others fall back to it. Text in a locale that gets disabled is kept but not shown.
- Output marking: fragment roots carry `lang`. JSON carries raw values and sentences. JSON-LD is locale-independent, except for any Localised text.

## 4. Innesto Orario (`in.hours`)

### 4.1 Domain model

From [#6]: an install holds one or more **Schedules**. Each has a **Schedule key** (fixed at creation, unique, suggested from the name, matching `[a-z][a-z0-9-]{0,31}`, [#19]), a public name (Localised text, [#10]), a **Schedule timezone** (IANA; it starts as the Install timezone, [#20]), and optionally **Location** fields ([#18]).

- **Weekly hours**: per weekday, zero or more **Opening periods** `HH:MM–HH:MM`. A weekday with no periods is closed. 24 hours is `00:00–24:00`. A period whose end is not after its start ends the next day, always within 24 h, and belongs to the day it **starts**. Periods within a day may not overlap.
- **Season**: alternative Weekly hours over a date span. A yearly Season uses day–month bounds and may cross the new year. A one-off Season uses full dates. Seasons may not overlap each other.
- **Exception**: a date span whose hours **replace** the applicable hours entirely. A **Closure** has no periods and may be open-ended. **Special hours** have their own periods. An Exception may repeat yearly on fixed dates and carries an optional visitor note (Localised text).
- **Precedence per date: Exception > Season > Weekly hours.** Among overlapping Exceptions, the shorter span wins, and on a tie the most recently edited one wins. A past-midnight spill follows its start day's rules: it survives a Closure on the next day, and an Exception on the start day replaces it. A spill that overlaps the next day's first period is refused by the Weekly hours and Season editors. When an Exception creates one, the two periods merge into one opening.
- **DST**: times are literal wall-clock in the Schedule timezone, with no special rules.
- **Lifecycle**: a non-repeating Exception stops affecting output once its end date has passed. It is deleted **30 days later**, inside the transaction of the next save to that Schedule. Rendering never writes ([§8](#8-gaps-settled-by-this-spec)). Repeating Exceptions never expire.
- Only open and closed exist.

### 4.2 Store

Schema version 1, two collections (field names illustrative, shapes decided):

- `schedules` (id = Schedule key): `name` (Localised text), `timezone`, `position`, `weekly` (`{"mon":[{"start":"09:00","end":"13:00"},…],…}`), `seasons` (`[{"id","from","to","yearly","weekly"}]`, embedded because they are few and their no-overlap rule is per Schedule), `location` (`null` or `{"name","address","phone"}`).
- `exceptions` (random id): `schedule` (the only filterable field; a Schedule's Exceptions are few, so dates are filtered in PHP), `kind` (`closure`|`special`), `start` (`YYYY-MM-DD`, or `MM-DD` when yearly), `end` (`null` = open-ended Closure), `yearly`, `periods`, `note` (Localised text), `edited` (UTC instant, for the tie-break).
- Module settings document: none needed in the tracer bullet.
- Deleting a Schedule deletes its Exceptions in the same transaction. Embeds that use its key then get `404` and keep their fallback.

### 4.3 Status timeline

From [#11]. This is a pure function of (Schedule, Exceptions, now from the context clock, locale), so it is testable at a fixed clock.

1. Take the local dates in the Schedule timezone from yesterday (for spills) through today + 14.
2. For each date, resolve its periods by precedence ([§4.1](#41-domain-model)) and convert them to UTC intervals. Merge overlapping or touching intervals.
3. Over the horizon `[now, now + 14 days)`, emit alternating open and closed **segments** with absolute UTC `from` and `to`, **split at each local midnight** so that a segment's wording never changes while it lasts.
4. Give each segment its sentence, localised in PHP. Examples: "Open · closes 13:00"; "Closed · opens at 15:00"; "Closed · reopens tomorrow at 9:00"; "Closed · reopens Monday at 9:00"; "Closed · reopens on 3 November"; "Closed until further notice". "Today" and "tomorrow" are relative to the local date on which the segment starts (its `from`), never to the render time, so a cached segment stays true while it lasts. A segment that runs past the horizon ends with an absolute date or "until further notice".
5. The shape is `[{"from":"…Z","to":"…Z","open":true,"text":"…"}]`, identical in the fragment's JSON script and the JSON `timeline` field.

Intermediate states ("closes soon") are deferred. The segment model takes them later by adding more splits.

### 4.4 Views

Each View has a single required option, `schedule` (a Schedule key).

- **`week`**: the hours **in effect on the render date**: the Weekly hours, or the running Season with its dates. It also shows any Season change that starts within the 14-day horizon ("From 1 June: …"), every Exception that overlaps the horizon (with its dates and note), and a running Closure in absolute terms ("Closed until further notice, since 3 October"). Then come an empty Status line for the enhancer and the timeline script ([§8](#8-gaps-settled-by-this-spec)). Everything is absolute text. The quarter-hour `ETag` bucket keeps it current.
- **`status`**: the Status line alone (e.g. for a header), with the timeline script.
- **`jsonld`**: a `<script type="application/ld+json">` holding the Schema.org markup ([#3], [#11], [#18]):
  - **Identity**: for a Location, a standalone `LocalBusiness` (of the Business profile's type) with the Location's name, address and phone and the inherited URL and image. Otherwise the Business profile itself. No `parentOrganization` and no `department`.
  - **Hours**: `openingHoursSpecification` only, using Google's documented encodings: split shifts as several entries per day; past midnight as `closes` < `opens`; 24 h as `00:00`–`23:59`. Seasons and Exceptions that haven't ended become dated entries with `validFrom`/`validThrough`, a Closure being `00:00`/`00:00`. A yearly Season or Exception uses its running occurrence, or else the next one, with full dates, also when it crosses the new year. Every dated entry lists the `dayOfWeek` values it covers. Expired Exceptions are pruned at render. An **open-ended Closure** is emitted as closed all day for all 7 days with `validFrom` and no `validThrough`; this encoding is unverified with Google, and the docs say so.
  - **Incomplete Business profile** (no name or address): empty output, a warning in the log, and a Self-check Warn. Activation is never blocked by it.
  - Never any Status.

### 4.5 Visitor-facing behaviour summary

| Situation | Visitor sees |
|---|---|
| JS on, fetch OK | The fragment plus the current Status |
| JS off, or fetch fails | The Developer's fallback line |
| Page older than the horizon, or wrong device clock | The fragment without a Status line |
| Inactive, Halted, unknown View or Schedule, bad option | The fallback line (endpoint `404`, empty) |
| Module fault | The fallback line (fault fenced and logged) |

### 4.6 Developer Screens

The UI design is out of scope; these are the capabilities ([#15], [#18], [#19]):

- **Schedules**: list, create (name, Schedule key suggested from it and editable until saved, timezone pre-filled with the Install timezone), rename, reorder and delete with confirmation. Delete warns that Embeds using the key will fall back.
- **Schedule**: Location on/off with name, address and phone. Every client Screen can also be reached from here.
- The Developer may also open every client Screen, with the band from [§3.13](#313-client-pwa-shell).

### 4.7 Client Screens

From [#15]:

- **Tile** (one per Schedule): the Schedule name, plus the Status summary from the timeline at render time (e.g. "Open · closes 13:00"), with the tone `open` or `closed`.
- **Home** (per Schedule):
  - a **Status card**;
  - a one-tap **"Closed today"**, which creates a one-day Closure for today's local date, with Undo in the confirmation toast. A past-midnight spill from yesterday survives it ([§4.1](#41-domain-model)), so when one is running the confirmation says so (e.g. "Still open until 2:00, closed for the rest of today");
  - **Next 7 days**, with Closure and Special-hours days flagged;
  - a **Regular hours** card summarising the Weekly hours and the current or next Season, which opens the **Regular hours** editor;
  - a **Closures and special hours** card listing Exceptions that haven't ended, each removable with Undo;
  - **+**, which offers "We're closed" or "Different hours" and opens the **Exception** form.
  - When a "closed until further notice" Closure is running, the home shows it even on a day a shorter Exception overrides, with **"We're open again"**, which sets the Closure's end to yesterday, or deletes it (with Undo) when it started today.
- **Regular hours editor**: Weekly hours and Seasons, with inline errors for #6's rules (overlapping periods, a spill into the next day's first period, overlapping Seasons).
- **Exception form** (one full page): dates; "no end date (until further notice)" for Closures; openings for Special hours; "every year"; and the visitor note as one plain-text field per Enabled locale, side by side (a single field when only one locale is enabled).
- The Client edits Weekly hours, Seasons and Exceptions. They never create, rename or delete Schedules or change timezones, Locations or anything else. They only ever enter structured data and plain text, never HTML.
- Every confirmed save ends with "Saved: visible on the Site within N minutes".

## 5. Observability summary

- **Roots log** ([§3.12](#312-roots-log-and-health-screen)): structured JSON lines with a per-request id, covering errors, security events and changes.
- **Counts**: errors per Module and sign-in failures. There are no request metrics ([#22]).
- **Agent and Developer vision**: the Health Screen, and the Self-check results stored with their time. Tests read the same log and the same Self-check results ([§7](#7-tests-that-prove-the-tracer-bullet)).

## 6. Security summary

- Credentials never cross origins: Visitor endpoints are cookie-less by path scoping and accept no credentials ([#7], [#19]).
- Login secrets are stored only as `password_hash` hashes. Session tokens are stored only as hashes ([#9], [#14]).
- Strict parameter whitelisting on `view.php`. `asset.php` canonicalises file names, rejects path separators and whitelists extensions.
- Data protection works in layers, proven over HTTP at setup and re-checked on demand ([#9], [#22]).
- Templates escape through Roots' helper. Client input is structured data or plain text only ([#12], [#15]).
- The Developer area and the PWA cannot be framed ([#7]).

## 7. Tests that prove the tracer bullet

Tooling from [#16]: **Pest 4**, **PHPStan max** (no baseline), **Vitest browser mode** (Playwright provider), and **Playwright Test** against an **Apache+PHP container**, all run in GitHub Actions on a PHP 8.3/8.4/8.5 matrix. Tests sit beside their owner (`tests/`, `modules/in.hours/tests/`). No test reads the real time: time-dependent code uses the context clock.

The tests below must pass for the tracer bullet to be done:

1. **Store contract suite over both backends** ([#9], [#16]). One Pest suite, run against SQLite and guarded flat files, covering put/get/delete, filters, ordering, limits, transactions (commit and rollback), Store revision bumps, the schema-version record, and `put` rejecting a `null` or missing declared field.
2. **Status timeline at a fixed clock** ([#11], [#16]). Pest, through a fixed context clock. Cases: split shifts; a past-midnight spill surviving a next-day Closure; Exception precedence (shorter span wins, then most recently edited); a Season crossing the new year; DST start and end; midnight splits with constant wording; a render at 23:45 whose segment after midnight reads "opens at 9:00", not "reopens tomorrow"; an open-ended Closure beyond the horizon; and "now" outside the horizon producing no Status. A Vitest browser test checks that the enhancer picks the right segment and shows nothing outside the timeline.
3. **Playwright end-to-end flow** ([#16], [#21]). This is [§2](#2-the-slice-step-by-step) end to end in the container:
   - setup with the Setup code, the Self-check, and the Data directory proven unreachable over HTTP;
   - Activate Innesto Orario, then copy the static-HTML Embed code into a static page;
   - the Visitor sees the `week` View and a Status;
   - in a phone viewport, the Client signs in with the PIN and taps "Closed today", and the toast reads N minutes;
   - after the cache window (the test uses N = 1 and a fresh browser context), the static page shows the Closure;
   - **an offline Save is refused with its input kept**;
   - **an expired session shows the PIN overlay** and, after the PIN, the form still holds its input.
4. **JS option list matches the Manifest** ([#20]). A test asserts that each Module's ES-module per-View option list equals the View options declared in its Manifest.
5. **No-optional-extensions CI job** ([#16], [#22]). On PHP 8.3 with `pdo_sqlite`, `intl`, `mbstring`, `sodium`, `curl` and `openssl` disabled, the Self-check grades missing SQLite as **Warn**, picks **flat files** and **still completes setup**.
6. **Template override drift and cache** ([#23]). A Template override declaring an outdated View `v` falls back to the Module's own template with a **Self-check Warn** (and a Roots log entry), and uploading an override file **changes the ETag**.
7. **Setup on a host that ignores `.htaccess`** ([#2], [#9]). Playwright, in the container with `AllowOverride None` and no writable directory outside the webroot: Roots creates `data-<random>/` in the webroot, the non-`.php` probe is reachable and graded **Warn**, **flat files** are picked, the guarded `.php` probe returns nothing, and **setup completes**.

Supporting checks that also gate CI ([#16]):
- Pest `arch()` rules enforcing Module isolation: no superglobals, no reach into Roots internals or another Module's namespace.
- PHPStan max.
- Vitest browser tests of Roots' base class: loader `outerHTML` swap with attribute carry-over and `class` merge, fallback kept on fetch failure, idempotent enhancement, `disconnectedCallback` cleanup.
- `view.php` behaviour: strict `404` + `no-store`, `304` from `If-None-Match`, CORS for allowed origins only, and no `Set-Cookie` ever.

## 8. Gaps settled by this spec

The user decided these on 2026-10-17 while the spec was assembled ([#24]). They narrow, but do not contradict, the ticket decisions, except where a row of the second table names the ticket it changes:

| Gap | Decision |
|---|---|
| Mail in the tracer bullet ([#20] left SMTP to Modulo; [#22] probes mail) | `mail()`-only mail service and transport report plus "Send test mail". No SMTP settings, probe or anti-spam until the Modulo spec ([§3.14](#314-mail-service)). |
| What `week` shows when Seasons exist ([#12]) | The hours in effect on the render date, Season changes and Exceptions within the 14-day horizon, and a running Closure ([§4.4](#44-views)). |
| Which folder `asset.php` serves ([#19]) | `modules/<id>/assets/` (and Roots' `assets/`), regular files only, extensions whitelisted, not listed in the Manifest ([§3.9](#39-http-behaviour)). |
| Expired-Exception retention ([#6]) | Deleted 30 days after its end, inside the next save to that Schedule ([§4.1](#41-domain-model)). |
| Self-check thresholds ([#22]) | Clock skew > 2 min; `max_execution_time` < 30 s; `memory_limit` < 64 MB; upload/post size < 2 MB ([§3.11](#311-self-check)). |

The user decided these on 2026-10-27, after an adversarial review of this draft:

| Gap | Decision |
|---|---|
| Atomic transactions on flat files ([#9] locked per collection) | One guarded file and one lock per Store; the transaction runs in memory and commits with one `rename` ([§3.2](#32-data-directory-and-stores)). |
| Operational writes changing the public `ETag` ([#14], [#22]) | Roots splits its data into a content Store, which feeds the `ETag`, and a runtime Store, which does not. Sliding expiry is written at most every 5 minutes ([§3.4](#34-roots-own-stores)). |
| Throttling values ([#14]) | Per IP and per area (`dev/`, `app/`): 5 free failures, then `429` for 1 s, doubling per failure up to 15 min. Global cap of 100 failures per hour per area; while it is hit, only IPs with a failure in that hour are refused, so a clean device still signs in ([§3.6](#36-authentication-and-sessions)). |
| "Closed today" during yesterday's past-midnight spill ([#6], [#15]) | The spill stays open until it ends, as [§4.1](#41-domain-model) already implies, and the confirmation says so ([§4.7](#47-client-screens)). |
| `lang` for the Default locale in the Embed code ([#20]) | Still left out, as [#20] decided: pages follow the Default locale if it changes. |
| How input survives an expired-session sign-in ([#21]) | A PIN overlay over the still-live form; nothing is stored in the browser ([§3.13](#313-client-pwa-shell)). |
| Side-by-side installs sharing a Data directory ([#7]) | Outside the webroot the directory is `innesto-data-<random>/`, recorded in `data-path.php` ([§3.2](#32-data-directory-and-stores)). |
| Where the PWA manifest and custom icon are served ([#21]) | From `app/`, without a session ([§3.9](#39-http-behaviour)). |

The user decided this on 2026-10-27, while #29 was implemented:

| Gap | Decision |
|---|---|
| CSRF on forms posted before a session exists ([#14] asks for a synchronizer token in every form) | The synchronizer token applies to the forms of a signed-in session. The setup and sign-in forms rely on `SameSite=Strict` and the Origin/Referer check against Roots' origin ([§3.6](#36-authentication-and-sessions)). |

The user decided these on 2026-10-28, after the review of #29:

| Gap | Decision |
|---|---|
| POST-redirect-GET for a refused post ([§3.9](#39-http-behaviour)) | Only a successful post redirects. A refused one (wrong code or password, too short, throttled) re-renders its form with `422` or `429` and the error, so nothing has to carry the error across a redirect before a session exists. |
| Throttling the password change ([#14] throttles sign-in) | A wrong current password on the password change is a sign-in failure of `dev/`: it counts toward the throttle and is refused with `429` while the IP waits ([§3.6](#36-authentication-and-sessions)). |
| How Roots knows a request is HTTPS ([#7]) | `HTTPS` set and not `off`, `REQUEST_SCHEME` of `https`, or `SERVER_PORT` 443. `X-Forwarded-Proto` and `X-Forwarded-SSL` are never read, since any client can send them; a host that reports HTTPS only through them fails the HTTPS rule and the browser-run HTTPS probe says why ([§3.11](#311-self-check)). |

Defaults this spec sets without a separate question. Each is cheap to change in the plan:

- `<roots>/data-path.php` records the Data directory, so Roots finds it on every request and the Developer can move it ([§3.2](#32-data-directory-and-stores)).
- A bootstrap-constant guard at the top of every directly-outputting PHP file in protected trees, so they stay silent where `.htaccess` is ignored ([§3.1](#31-files-on-the-server)).

Reconciled between tickets:

- **Where the Visitor `max-age` lives**: [#19] says "shared config", but [#20] (later, and matching the glossary) moves it to **Roots settings**. This spec follows [#20].
- **ETag inputs**: [#11] (Store revision) was refined by [#19] (versions, params, UTC hour, Roots' revision) and [#23] (override files). This spec uses the full set, with the UTC hour narrowed to the UTC quarter-hour so that local midnights in :30 and :45 zones also start a new bucket.
- **Data directory HTTP proof**: [#9] blocks setup on any reachable probe. This spec splits it in two ([§3.2](#32-data-directory-and-stores)): a reachable non-`.php` file only forces flat files (Warn), and only a guarded `.php` file that leaks content blocks setup (Fail), so in-webroot installs on hosts that ignore `.htaccess` still work as [#9] intended.
- **README "shared config (SMTP, anti-spam, timezone, credentials)"** is narrowed by [#20]: secrets and HTTP policy sit in Roots settings.
- **README package names "Mail / Book / Hours"**: `GLOSSARY.md` settles them as Innesto Modulo, Innesto Agenda and Innesto Orario.

## 9. Out of scope

From the map ([#1]):

- Remote Module download and one-click install from a catalogue. Modules arrive over FTP, and Roots only discovers and activates them.
- Upgrades of Roots or Modules over an existing install, and data migrations. Stored data is still versioned ([§3.3](#33-store-api)), and a mismatch Halts the Module.
- Portable export and import, backups, and switching storage backend after install.
- The UI design of the Developer area and its configurators. Only the capability lists are in scope.
- Specs for Innesto Modulo (`in.modulo`) and Innesto Agenda (`in.agenda`), including SMTP settings, anti-spam and the SMTP Self-check probe ([§8](#8-gaps-settled-by-this-spec)).
- States beyond open and closed ("by appointment", "pickup only") in Innesto Orario ([#6]).
- Multi-tenancy: one install serves one Site and one business ([#7]).
- Magic Link sign-in for the Client ([#14]).
- Build-time baking of Views into static pages, and rebuild or deploy triggers after Client edits ([#17]).
- Also deferred by individual tickets:
  - intermediate Statuses such as "closes soon" ([#11]);
  - a per-device session list ([#14]);
  - a Client-editable Business profile tile ([#18]);
  - `parentOrganization` and `department` markup, and Business-profile extras such as geo, `priceRange` and `sameAs` ([#18]);
  - push alerts ([#22]);
  - pretty URLs ([#19]);
  - computed holiday calendars ([#6]).

[#1]: https://github.com/as-webmetodo/innesto/issues/1
[#2]: https://github.com/as-webmetodo/innesto/issues/2
[#3]: https://github.com/as-webmetodo/innesto/issues/3
[#4]: https://github.com/as-webmetodo/innesto/issues/4
[#5]: https://github.com/as-webmetodo/innesto/issues/5
[#6]: https://github.com/as-webmetodo/innesto/issues/6
[#7]: https://github.com/as-webmetodo/innesto/issues/7
[#8]: https://github.com/as-webmetodo/innesto/issues/8
[#9]: https://github.com/as-webmetodo/innesto/issues/9
[#10]: https://github.com/as-webmetodo/innesto/issues/10
[#11]: https://github.com/as-webmetodo/innesto/issues/11
[#12]: https://github.com/as-webmetodo/innesto/issues/12
[#13]: https://github.com/as-webmetodo/innesto/issues/13
[#14]: https://github.com/as-webmetodo/innesto/issues/14
[#15]: https://github.com/as-webmetodo/innesto/issues/15
[#16]: https://github.com/as-webmetodo/innesto/issues/16
[#17]: https://github.com/as-webmetodo/innesto/issues/17
[#18]: https://github.com/as-webmetodo/innesto/issues/18
[#19]: https://github.com/as-webmetodo/innesto/issues/19
[#20]: https://github.com/as-webmetodo/innesto/issues/20
[#21]: https://github.com/as-webmetodo/innesto/issues/21
[#22]: https://github.com/as-webmetodo/innesto/issues/22
[#23]: https://github.com/as-webmetodo/innesto/issues/23
[#24]: https://github.com/as-webmetodo/innesto/issues/24
