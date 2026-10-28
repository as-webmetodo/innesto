# Fixes from the two-axis review of #32

Source: the review of `806504f..HEAD`, saved in the shared notes as `review-32-aihero.md`. Each step below is one commit on `main` with the `(#32)` suffix, signed off (`git commit -s`). Behaviour fixes go test first: write the failing Pest test, then make it pass. Every step ends with `bin/composer check` green. The e2e suite runs once at the end on all three containers.

The steps marked **Decision** need the maintainer's choice before work starts. The plan carries a recommendation for each.

## Behaviour fixes (Spec axis)

1. **Roots' own Screen names cannot shadow a Module Screen (Spec 1).** Today `dev/?module=x&screen=config` serves Roots' config Screen on GET (`src/DeveloperArea.php:67`), while a POST to the same URL reaches the Module.
   - Fix in two places:
     - Move the `isset($request->query['module'])` arm above `$screen === 'config'` in the GET `match`. Keep `!$config->confirmed()` first, so an unconfirmed install still lands on config.
     - Leave `Manifest::read` free to accept `config` as a Screen name: once the route is fixed, the name is harmless. This avoids a reserved list that would have to grow with every Roots Screen.
   - Test: a fixture Module declaring developer Screen `config` renders its own page on GET. **Verify:** the new test fails before the fix and passes after.

2. **The superglobal rule covers the whole Module folder (Spec 2).** `tests/ModuleIsolationTest.php:41-49` scans `modules/<id>/src` only.
   - Fix: scan `modules/<id>` recursively, which takes in `templates/`, `locales/` and `module.php`. Skip `tests/`, since Pest tests may use superglobals legitimately; check whether any do first.
   - **Verify:** temporarily add `$_GET` to a template, see the test fail, then remove it.

3. **No locale gap on rows without a Manifest (Spec 5).** In `src/ModulesScreen.php:41`, compute `gap` only when `$module['manifest'] !== null`, else `[]`.
   - Test: a folder with a bad name and a non-empty Enabled list shows no gap line. **Verify:** red, then green.

4. **Output from `module.php` never reaches the page (Spec 6).** In `src/Manifest.php:71`, wrap the require in `ob_start()` and discard the buffer in a `finally` with `ob_end_clean()`. A Manifest that echoes still loads, and its output is dropped.
   - Decide while implementing: whether output should Halt the Module (`module.php must not print anything`). Recommended: Halt. A Manifest is a plain array, and output means something is wrong.
   - Test: an `acme.noisy` fixture whose `module.php` echoes. **Verify:** the Modules page body does not contain the echoed text, and the Module is Halted.
   - Warnings already go through the Roots error handler, so no extra work there.

5. **`ScreenResult::page()` accepts only page statuses (Spec scope creep).** In `src/ScreenResult.php:18`, allow 2xx, 4xx and 5xx, and throw `InvalidArgumentException` otherwise. (Done this way rather than with 200, 404 and 422 only: the existing test of a deliberate empty 500 page relies on a 5xx.) The fence then counts the throw as the Module's fault.
   - Test: `page('', 302)` throws. **Verify:** red, then green.

6. **`Manifest::element()` gets a test (Spec 4).** Pest unit tests: `in.hours` → `in-hours`, `acme.probe` → `acme-probe`. The method stays as is: the embed and View tickets consume it.

## Structure fixes (Standards axis)

7. **Narrower public surface (Standards 1, constants).** Make `Manifest::RESERVED_OPTIONS` and `Modules::ROOTS_API` `private`. A grep confirms that only their own classes read them. **Verify:** PHPStan clean.

8. **One rule for "developer Screens of a runnable Module" (Standards 2).**
   - Add `Manifest::developerScreens(): list<string>`, the filter on `screens` where the side is `developer`.
   - Add `Modules::runnable(array $module): bool`, meaning Active, not Halted, with a Manifest. Make it private if only `Modules` needs it, else public.
   - `ModulesScreen.php:42-44` and `:93`, and `Modules::developerScreen` (`src/Modules.php:281-286`) all call these.
   - **Verify:** the existing Modules tests stay green unchanged.

9. **Module state as an enum (Standards 3).**
   - Replace `Modules::UPLOADED/ACTIVE/INACTIVE` with `enum ModuleState: string { case Uploaded = 'uploaded'; … }` in `src/ModuleState.php`. Its values equal today's strings, so stored state documents are unchanged.
   - Discovery maps the stored string with `ModuleState::from()`. An unknown value fails fast, as stored documents do elsewhere (§8, "A stored document that fails these checks fails fast").
   - The template compares against `ModuleState::Active` and `ModuleState::Inactive`. Tests building state documents keep their strings, because that is the storage format.
   - **Verify:** PHPStan max is clean, and `grep "'active'\|'inactive'\|'uploaded'" src templates` finds nothing.

10. **One Halt type everywhere (Standards 4).**
    - `ModulesScreen` and the template import the existing `@phpstan-type Halt` from `Modules` (`@phpstan-import-type Halt from Modules`) instead of retyping it.
    - Add a `private const STATE_ERROR = ['dev.modules.error.state', []]`, or a tiny helper, for the four repeated literals. Recommended: no `Halt` value class, since a two-field tuple behind one PHPStan type is enough (no classitis, `design.md`).
    - **Verify:** PHPStan clean.

11. **Consistent names (Standards 5).**
    - Rename `gap` in `ModulesScreen`/`templates/dev/modules.php` to `uncovered`, as `ConfigScreen` already uses.
    - Rename `Modules::$rootsCatalogue` to `$dates`, matching `Context` and `StatusTimeline`.
    - The catalogue key `dev.modules.gap` is renamed with it in `locales/en.php` and `locales/it.php`.

12. **Tests for the Context helpers (Standards 8).** In a Pest test of `Context` built from fakes:
    - `plural()` picks the locale's plural form.
    - `formatter()` formats in the Context's locale with Roots' catalogue.
    - `escape()` escapes `<&"'`.
    - `mail` is `null` unless the Module requires or optionally uses `mail` and the host has `mail()`. This tests `Modules::context` directly through two fixture Manifests, not via `Mail::available()` in the echo page.

## Decisions for the maintainer

13. **Decision: Context gives two ways into the catalogues (Standards 1, Context).**
    - Orario calls `$context->catalogue->text($default, …)` because `onActivate` names the seed Schedule in the Default locale, not the Context's.
    - `StatusTimeline` takes both `Catalogue`s, so a later ticket will need them from the Context.
    - Options:
      - (a) Give `text()` an optional `?string $locale = null`, have Orario use it, and keep the catalogues public for `StatusTimeline`.
      - (b) Make the catalogues private and add the explicit-locale `text()`. `StatusTimeline` would then need a Context-level factory later.
      - (c) No change, recorded as intended.
    - Recommended: (a). It is the smallest change that removes Orario's reach-through and blocks nothing.

14. **Decision: `onActivate` is mandatory, though the spec says "optional" (Spec 3).**
    - Options:
      - (a) Keep it in the interface, say in its doc comment that an empty body opts out, and add a §8 row recording this.
      - (b) Move it to a separate `Activates` interface that Roots checks with `instanceof`.
    - Recommended: (a). An empty method is the PHP way to opt out, and a second interface adds a type for nothing.

15. **Decision: Orario declares developer Screens that always throw (Standards 7).**
    - Options:
      - (a) Accept this as transitional until #33, which builds them, and note it in #33.
      - (b) Remove `schedules`/`schedule` from Orario's Manifest until #33. `ModulesTest.php:61,83` and `modules.spec.js:35` would then need moving to `acme.probe`'s `echo` Screen.
      - (c) Return a placeholder page instead of throwing.
    - Recommended: (a). Nothing ships before the tracer bullet is complete, and (b)/(c) add churn #33 undoes.
    - Noting it in #33 means a GitHub comment, which the maintainer posts or approves.

16. **Decision: `src/Modules.php` does many jobs (Standards 6).**
    - Options:
      - (a) Extract the static PSR-4 autoloader (`entry()`'s `spl_autoload_register` and `$autoload`) into `ModuleAutoloader`.
      - (b) No change. `Modules` is the deep module the Developer area talks to, and the parts share its state and lock.
    - Recommended: (b). The file is 355 lines, cohesive around one state machine, and `design.md` warns against shallow splits. Revisit if #33–#43 grow it.

17. **Decision: record the two other scope-creep items in spec §8.** Deactivating a Halted Active Module and Mail's header and address validation are both kept, because they let a Developer recover and they defend against header injection. Add one §8 row each, under the 2026-10-28 #32 table. Recommended: yes.

## Final check

18. Run `bin/composer check`, then `nvm use` and `npm run test:e2e` with `INNESTO_WEB=web`, `web-noext` and `web-no-htaccess`. **Verify:** all green. Update the memory note `tracer-bullet-spec-status.md` with the new commit range.
