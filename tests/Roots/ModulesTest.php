<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

/** The section of the Modules list about Module $id. */
function moduleSection(string $page, string $id): string
{
    expect(preg_match('~<section id="module-' . preg_quote($id, '~') . '">(.*?)</section>~s', $page, $match))->toBe(1, "$id is not listed");

    return $match[1] ?? '';
}

test('an uploaded Module is listed as Uploaded, with its Display name', function (): void {
    $install = install();
    uploadModules($install, ORARIO);
    $token = confirmedDeveloper($install);

    $home = signedInPage($install, $token);
    $page = devPage($install, $token, ['screen' => 'modules']);

    expect($home->body)->toContain('href="?screen=modules"')
        ->and($page->status)->toBe(200)
        ->and(moduleSection($page->body, 'in.hours'))->toContain('Innesto Orario')
        ->and(moduleSection($page->body, 'in.hours'))->toContain('Uploaded')
        ->and(moduleSection($page->body, 'in.hours'))->toContain('value="activate"');
});

/**
 * Module $id's Store as Roots opens it.
 *
 * @param array{roots: string, webroot: string} $install
 */
function moduleStore(array $install, string $id): Innesto\Roots\Store\Store
{
    $manifest = Innesto\Roots\Manifest::read($install['roots'] . "/modules/$id");

    return rootsStores($install)->module($id, $manifest->storeSchema());
}

/** @param array{roots: string, webroot: string} $install */
function moduleStoreExists(array $install, string $id): bool
{
    return rootsStores($install)->moduleExists($id);
}

test('Activate creates Orario\'s Store and seeds the "Main" Schedule in the Install timezone', function (): void {
    $install = install();
    uploadModules($install, ORARIO);
    $token = confirmedDeveloper($install);

    $response = devPost($install, $token, ['action' => 'activate', 'module' => 'in.hours']);
    $page = devPage($install, $token, ['screen' => 'modules']);
    $main = moduleStore($install, 'in.hours')->get('schedules', 'main');

    expect($response->status)->toBe(303)
        ->and($response->header('Location'))->toBe('/innesto/dev/?screen=modules')
        ->and(moduleSection($page->body, 'in.hours'))->toContain('Active')
        ->and(moduleSection($page->body, 'in.hours'))->toContain('value="deactivate"')
        ->and(moduleSection($page->body, 'in.hours'))->toContain('href="?module=in.hours&amp;screen=schedules"')
        ->and(moduleSection($page->body, 'in.hours'))->not->toContain('screen=home')
        ->and($main)->toMatchArray(['name' => ['it' => 'Principale'], 'timezone' => 'Europe/Rome', 'position' => 0, 'seasons' => [], 'location' => null])
        ->and($main['weekly'] ?? null)->toBe(['mon' => [], 'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => []])
        ->and(loggedEvents($install))->toContain('module.activated')
        ->and(loggedEvents($install))->toContain('schedule.created');
});

test('Deactivate keeps the Store; Activate again uses it without seeding again', function (): void {
    $install = install();
    uploadModules($install, ORARIO);
    $token = confirmedDeveloper($install);
    devPost($install, $token, ['action' => 'activate', 'module' => 'in.hours']);
    moduleStore($install, 'in.hours')->put('schedules', ['id' => 'second', 'position' => 1]);

    $deactivated = devPost($install, $token, ['action' => 'deactivate', 'module' => 'in.hours']);
    $inactive = moduleSection(devPage($install, $token, ['screen' => 'modules'])->body, 'in.hours');
    $activated = devPost($install, $token, ['action' => 'activate', 'module' => 'in.hours']);

    expect($deactivated->status)->toBe(303)
        ->and($inactive)->toContain('Inactive')
        ->and($inactive)->toContain('value="delete_data"')
        ->and($inactive)->not->toContain('screen=schedules')
        ->and($activated->status)->toBe(303)
        ->and(array_column(moduleStore($install, 'in.hours')->list('schedules'), 'id'))->toBe(['main', 'second'])
        ->and(array_count_values(array_filter(loggedEvents($install), is_string(...))))->toMatchArray(['module.activated' => 2, 'module.deactivated' => 1, 'schedule.created' => 1]);
});

test('Delete data needs confirming, then removes the Store and the Module is Uploaded again', function (): void {
    $install = install();
    uploadModules($install, ORARIO);
    $token = confirmedDeveloper($install);
    devPost($install, $token, ['action' => 'activate', 'module' => 'in.hours']);
    devPost($install, $token, ['action' => 'deactivate', 'module' => 'in.hours']);

    $unconfirmed = devPost($install, $token, ['action' => 'delete_data', 'module' => 'in.hours']);
    $kept = moduleStoreExists($install, 'in.hours');
    $deleted = devPost($install, $token, ['action' => 'delete_data', 'module' => 'in.hours', 'confirm' => 'on']);

    expect($unconfirmed->status)->toBe(422)
        ->and($unconfirmed->body)->toContain('Tick the box')
        ->and($kept)->toBeTrue()
        ->and($deleted->status)->toBe(303)
        ->and(moduleStoreExists($install, 'in.hours'))->toBeFalse()
        ->and(moduleSection(devPage($install, $token, ['screen' => 'modules'])->body, 'in.hours'))->toContain('Uploaded')
        ->and(loggedEvents($install))->toContain('module.data_deleted');
});

test('a lifecycle action that does not apply to the Module\'s state is refused with 422', function (array $form): void {
    /** @var array<string, string> $form */
    $install = install();
    uploadModules($install, ORARIO);
    $token = confirmedDeveloper($install);

    expect(devPost($install, $token, $form)->status)->toBe(422);
})->with([
    'deactivate an Uploaded Module' => [['action' => 'deactivate', 'module' => 'in.hours']],
    'delete the data of an Uploaded Module' => [['action' => 'delete_data', 'module' => 'in.hours', 'confirm' => 'on']],
    'activate an unknown Module' => [['action' => 'activate', 'module' => 'in.nothing']],
    'activate a path' => [['action' => 'activate', 'module' => '../in.hours']],
]);

test('a Module Roots cannot run is listed as Halted with the reason, and the others still work', function (string $folder, array|string $changes, string $reason): void {
    /** @var array<string, mixed>|string $changes */
    $install = install();
    uploadModules($install, ORARIO);
    uploadManifest($install, $folder, $changes);
    $token = confirmedDeveloper($install);

    $page = devPage($install, $token, ['screen' => 'modules']);
    $refused = devPost($install, $token, ['action' => 'activate', 'module' => $folder]);

    expect($page->status)->toBe(200)
        ->and(moduleSection($page->body, $folder))->toContain('Halted')
        ->and(moduleSection($page->body, $folder))->toContain($reason)
        ->and(moduleSection($page->body, $folder))->not->toContain('value="activate"')
        ->and($refused->status)->toBe(422)
        ->and(moduleStoreExists($install, 'in.hours'))->toBeFalse()
        ->and(devPost($install, $token, ['action' => 'activate', 'module' => 'in.hours'])->status)->toBe(303);
})->with([
    'syntax error' => ['acme.broken', "<?php\nreturn [;\n", 'The Manifest is broken: module.php cannot be read'],
    'not an array' => ['acme.broken', "<?php\nreturn 'in.hours';\n", 'The Manifest is broken: module.php must return an array'],
    'missing key' => ['acme.broken', "<?php\nreturn ['id' => 'acme.broken'];\n", 'The Manifest is broken: module.php must return an array'],
    'no module.php' => ['acme.broken', '', 'module.php is missing'],
    'id mismatch' => ['acme.menu', ['id' => 'acme.other'], 'declares the id acme.other'],
    'folder not a Package id' => ['Acme-Menu', [], 'The folder name is not a Package id'],
    'reserved View option' => ['acme.menu', ['views' => ['week' => ['v' => 1, 'options' => ['lang' => ['type' => 'string', 'required' => false]]]]], 'reserved option name lang'],
    'entry outside its namespace' => ['acme.menu', ['entry' => 'Innesto\\Roots\\App'], 'entry must be a class in the Module'],
    'Roots settings collection' => ['acme.menu', ['store' => ['schema' => 1, 'collections' => ['settings' => ['fields' => []]]]], 'settings is Roots'],
    'newer Roots API' => ['acme.menu', ['requires' => ['roots_api' => 2, 'php' => '8.3', 'capabilities' => [], 'optional' => []]], 'Unmet requirements: Roots API 2'],
    'newer PHP' => ['acme.menu', ['requires' => ['roots_api' => 1, 'php' => '99.0', 'capabilities' => ['sms'], 'optional' => []]], 'Unmet requirements: PHP 99.0, sms'],
]);

test('a Store schema version that differs from the Manifest\'s Halts the Module, until its data is deleted', function (): void {
    $install = install();
    uploadModules($install, ORARIO);
    $token = confirmedDeveloper($install);
    // Activate, then upload a Manifest with schema 2 over it.
    devPost($install, $token, ['action' => 'activate', 'module' => 'in.hours']);
    uploadManifest($install, 'in.hours', ['store' => ['schema' => 2, 'collections' => ['schedules' => ['fields' => ['position' => 'int']], 'exceptions' => ['fields' => ['schedule' => 'string']]]]]);

    $halted = moduleSection(devPage($install, $token, ['screen' => 'modules'])->body, 'in.hours');
    $screen = devPage($install, $token, ['module' => 'in.hours', 'screen' => 'schedules']);
    devPost($install, $token, ['action' => 'deactivate', 'module' => 'in.hours']);
    devPost($install, $token, ['action' => 'delete_data', 'module' => 'in.hours', 'confirm' => 'on']);
    $fresh = moduleSection(devPage($install, $token, ['screen' => 'modules'])->body, 'in.hours');

    expect($halted)->toContain('Halted')
        ->and($halted)->toContain('schema version 1, but the Module expects 2')
        ->and($halted)->toContain('value="deactivate"')
        ->and($screen->status)->toBe(404)
        ->and($fresh)->toContain('Uploaded')
        ->and($fresh)->not->toContain('Halted');
});

test('a throw in onActivate rolls everything back: the Module stays Uploaded, without a Store, and the error is counted', function (): void {
    $install = install();
    uploadModules($install, FAKE_MODULES . '/acme.failing');
    $token = confirmedDeveloper($install);

    $refused = devPost($install, $token, ['action' => 'activate', 'module' => 'acme.failing']);
    $section = moduleSection(devPage($install, $token, ['screen' => 'modules'])->body, 'acme.failing');
    $fault = array_values(array_filter(logLines(dataPath($install) . '/roots-log.php'), static fn (array $line): bool => $line['event'] === 'module.fault'));

    expect($refused->status)->toBe(422)
        ->and($refused->body)->toContain('Activation failed and was undone: RuntimeException: Seeding failed')
        ->and(moduleStoreExists($install, 'acme.failing'))->toBeFalse()
        ->and($section)->toContain('Uploaded')
        ->and($section)->toContain('Errors: 1. Last, at 2026-10-27T10:00:00Z: RuntimeException: Seeding failed')
        ->and($fault)->toHaveCount(1)
        ->and($fault[0]['source'] ?? null)->toBe('acme.failing')
        ->and($fault[0]['level'] ?? null)->toBe('error')
        ->and(loggedEvents($install))->not->toContain('module.activated');
});

test('a developer Screen renders inside Roots\' shell with the Module\'s Context', function (): void {
    $install = install();
    uploadModules($install, FAKE_MODULES . '/acme.probe');
    $token = confirmedDeveloper($install);
    devPost($install, $token, ['action' => 'activate', 'module' => 'acme.probe']);

    $page = devPage($install, $token, ['module' => 'acme.probe', 'screen' => 'echo', 'tab' => 'two']);

    expect($page->status)->toBe(200)
        ->and($page->header('Cache-Control'))->toBe('no-store')
        ->and($page->body)->toContain('<title>Innesto Roots: Developer area</title>')
        ->and($page->body)->toContain('<a href="?screen=modules">Modules</a>')
        ->and($page->body)->toContain('href="?module=acme.probe&amp;screen=boom"')
        ->and($page->body)->not->toContain('screen=phone')
        ->and($page->body)->toContain('<h1>Probe (en)</h1>')
        ->and($page->body)->toContain('<dd id="greeting">hello</dd>')
        ->and($page->body)->toContain('<dd id="actor">developer</dd>')
        ->and($page->body)->toContain('<dd id="business">Forno Rossi</dd>')
        ->and($page->body)->toContain('<dd id="mail">' . (Innesto\Roots\Mail::available() ? 'yes' : 'no') . '</dd>')
        ->and($page->body)->toContain('<dd id="query">tab=two</dd>')
        ->and($page->body)->toContain('<dd id="today">October 27</dd>')
        ->and($page->body)->toContain('name="csrf" value="' . csrfToken($install, $token) . '"');
});

test('a Screen form is posted through Roots\' CSRF check, then redirects to a Screen of the Module', function (): void {
    $install = install();
    uploadModules($install, FAKE_MODULES . '/acme.probe');
    $token = confirmedDeveloper($install);
    devPost($install, $token, ['action' => 'activate', 'module' => 'acme.probe']);
    $query = ['module' => 'acme.probe', 'screen' => 'echo'];

    $forged = $install['app']->handle(request('POST', 'dev', ['greeting' => 'forged', 'csrf' => 'forged'], ['innesto_dev' => $token], ORIGIN, query: $query));
    $saved = devPost($install, $token, ['greeting' => 'ciao'], $query);
    $page = devPage($install, $token, $query);
    $line = array_values(array_filter(logLines(dataPath($install) . '/roots-log.php'), static fn (array $line): bool => $line['event'] === 'probe.saved'));

    expect($forged->status)->toBe(403)
        ->and($saved->status)->toBe(303)
        ->and($saved->header('Location'))->toBe('/innesto/dev/?module=acme.probe&screen=echo&saved=1')
        ->and($page->body)->toContain('<dd id="greeting">ciao</dd>')
        ->and($line[0]['source'] ?? null)->toBe('acme.probe');
});

test('a throwing Screen gets Roots\' fallback page, is counted, and the Module stays Active', function (string $screen, string $message): void {
    $install = install();
    uploadModules($install, FAKE_MODULES . '/acme.probe');
    $token = confirmedDeveloper($install);
    devPost($install, $token, ['action' => 'activate', 'module' => 'acme.probe']);

    $first = devPage($install, $token, ['module' => 'acme.probe', 'screen' => $screen]);
    devPage($install, $token, ['module' => 'acme.probe', 'screen' => $screen]);
    $section = moduleSection(devPage($install, $token, ['screen' => 'modules'])->body, 'acme.probe');

    expect($first->status)->toBe(500)
        ->and($first->body)->toContain('This Screen failed. The error is in the Roots log.')
        ->and($first->body)->toContain('<a href="?screen=modules">Modules</a>')
        ->and($section)->toContain('Active')
        ->and($section)->toContain("Errors: 2. Last, at 2026-10-27T10:00:00Z: $message")
        ->and(devPage($install, $token, ['module' => 'acme.probe', 'screen' => 'echo'])->status)->toBe(200);
})->with([
    'throw' => ['boom', 'RuntimeException: The probe exploded'],
    'redirect to a client Screen' => ['stray', 'LogicException: Screen stray redirected to phone'],
]);

test('only an Active Module\'s declared developer Screens are reachable', function (string $state, string $screen): void {
    $install = install();
    uploadModules($install, FAKE_MODULES . '/acme.probe');
    $token = confirmedDeveloper($install);
    if ($state !== 'uploaded') {
        devPost($install, $token, ['action' => 'activate', 'module' => 'acme.probe']);
    }
    if ($state === 'inactive') {
        devPost($install, $token, ['action' => 'deactivate', 'module' => 'acme.probe']);
    }

    $page = devPage($install, $token, ['module' => 'acme.probe', 'screen' => $screen]);

    expect($page->status)->toBe(404)
        ->and($page->body)->toContain('This Screen does not exist, or its Module is not Active.');
})->with([
    'Uploaded' => ['uploaded', 'echo'],
    'Inactive' => ['inactive', 'echo'],
    'client Screen' => ['active', 'phone'],
    'undeclared Screen' => ['active', 'secret'],
]);

test('Module Screens need a signed-in Developer', function (): void {
    $install = install();
    uploadModules($install, FAKE_MODULES . '/acme.probe');
    $token = confirmedDeveloper($install);
    devPost($install, $token, ['action' => 'activate', 'module' => 'acme.probe']);

    $page = $install['app']->handle(request('GET', 'dev', query: ['module' => 'acme.probe', 'screen' => 'echo']));

    expect($page->body)->toContain('Sign in')
        ->and($page->body)->not->toContain('id="greeting"');
});

test('Activation reports the Enabled locales a Module does not cover', function (): void {
    $install = install();
    uploadModules($install, ORARIO, FAKE_MODULES . '/acme.probe');
    $token = completeSetup($install);
    postConfig($install, $token, ['locale_en-GB' => 'on'] + SHARED_FORM);

    devPost($install, $token, ['action' => 'activate', 'module' => 'acme.probe']);
    $page = devPage($install, $token, ['screen' => 'modules'])->body;

    expect(moduleSection($page, 'acme.probe'))->toContain('Active')
        ->and(moduleSection($page, 'acme.probe'))->toContain('It has no text in these Enabled locales: en-GB.')
        ->and(moduleSection($page, 'in.hours'))->not->toContain('It has no text');
});

test('only the locales Roots and every Active Module cover can be enabled', function (): void {
    $install = install();
    uploadModules($install, ORARIO, FAKE_MODULES . '/acme.probe');
    $token = confirmedDeveloper($install);
    $config = fn (): string => devPage($install, $token, ['screen' => 'config'])->body;

    $before = $config();
    devPost($install, $token, ['action' => 'activate', 'module' => 'in.hours']);
    $withOrario = $config();
    devPost($install, $token, ['action' => 'activate', 'module' => 'acme.probe']);
    $withProbe = $config();
    $refused = postConfig($install, $token, ['default_locale' => 'en-GB', 'locale_en-GB' => 'on'] + SHARED_FORM);

    expect($before)->toContain('name="locale_en-GB"')
        ->and($withOrario)->toContain('name="locale_en-GB"')
        ->and($withProbe)->not->toContain('name="locale_en-GB"')
        ->and($withProbe)->toContain('name="locale_it"')
        ->and($refused->status)->toBe(422)
        ->and(Innesto\Roots\SharedConfig::fromStore(contentStore($install), 'https://example.com')->enabledLocales)->toBe(['it', 'en']);
});

test('an unreadable Module Store Halts that Module only', function (): void {
    $install = install();
    uploadModules($install, ORARIO, FAKE_MODULES . '/acme.probe');
    $token = confirmedDeveloper($install);
    devPost($install, $token, ['action' => 'activate', 'module' => 'in.hours']);
    devPost($install, $token, ['action' => 'activate', 'module' => 'acme.probe']);
    foreach (glob(dataPath($install) . '/module-in.hours.*') ?: [] as $file) {
        file_put_contents($file, 'not a Store');
    }

    $page = devPage($install, $token, ['screen' => 'modules']);

    expect($page->status)->toBe(200)
        ->and(moduleSection($page->body, 'in.hours'))->toContain('Its data cannot be read')
        ->and(moduleSection($page->body, 'acme.probe'))->not->toContain('Halted')
        ->and(devPage($install, $token, ['module' => 'acme.probe', 'screen' => 'echo'])->status)->toBe(200);
});

test('a Store left by an interrupted activation never Halts an Uploaded Module', function (): void {
    $install = install();
    uploadModules($install, ORARIO);
    $token = confirmedDeveloper($install);
    // The Store of an older version, created before the request died.
    rootsStores($install)->module('in.hours', ['schema' => 7, 'collections' => ['schedules' => ['fields' => ['position' => 'int']]]]);

    $section = moduleSection(devPage($install, $token, ['screen' => 'modules'])->body, 'in.hours');
    $activated = devPost($install, $token, ['action' => 'activate', 'module' => 'in.hours']);

    expect($section)->not->toContain('Halted')
        ->and($activated->status)->toBe(303)
        ->and(moduleStore($install, 'in.hours')->schemaVersion())->toBe(1)
        ->and(moduleStore($install, 'in.hours')->get('schedules', 'main'))->not->toBeNull();
});

test('a stored locale an Active Module does not cover is flagged, and the Default locale is never changed silently', function (): void {
    $install = install();
    uploadModules($install, FAKE_MODULES . '/acme.probe');
    $token = completeSetup($install);
    postConfig($install, $token, ['locale_en-GB' => 'on', 'default_locale' => 'en-GB'] + SHARED_FORM);
    devPost($install, $token, ['action' => 'activate', 'module' => 'acme.probe']);

    $page = devPage($install, $token, ['screen' => 'config'])->body;
    // What a browser submits with nothing chosen in the Default locale.
    $saved = postConfig($install, $token, ['locale_en-GB' => 'on', 'default_locale' => ''] + SHARED_FORM);

    expect($page)->toContain('Not every Active Module covers these locales, so they can no longer be enabled: en-GB.')
        ->and(preg_match('~<select id="default_locale" name="default_locale">\s*<option value="" selected>~', $page))->toBe(1)
        ->and($saved->status)->toBe(422)
        ->and(Innesto\Roots\SharedConfig::fromStore(contentStore($install), 'https://example.com')->defaultLocale)->toBe('en-GB');
});

test('a Screen that answers an empty 500 page on purpose is shown as it is, not as a fault', function (): void {
    $install = install();
    uploadModules($install, FAKE_MODULES . '/acme.probe');
    $token = confirmedDeveloper($install);
    devPost($install, $token, ['action' => 'activate', 'module' => 'acme.probe']);

    $page = devPage($install, $token, ['module' => 'acme.probe', 'screen' => 'empty']);

    expect($page->status)->toBe(500)
        ->and($page->body)->toContain('<a href="?screen=modules">Modules</a>')
        ->and($page->body)->not->toContain('This Screen failed')
        ->and(moduleSection(devPage($install, $token, ['screen' => 'modules'])->body, 'acme.probe'))->not->toContain('Errors:');
});

test('when Roots cannot create a Module\'s Store, Activate is refused as Roots\' error, not the Module\'s', function (): void {
    $install = install();
    uploadModules($install, ORARIO);
    $token = confirmedDeveloper($install);
    $data = dataPath($install);
    // A directory where the Store's file should go: both backends fail to create it.
    foreach (['sqlite', 'php'] as $extension) {
        mkdir("$data/module-in.hours.$extension");
    }

    $refused = devPost($install, $token, ['action' => 'activate', 'module' => 'in.hours']);
    $failed = array_values(array_filter(logLines("$data/roots-log.php"), static fn (array $line): bool => $line['event'] === 'module.store_failed'));

    expect($refused->status)->toBe(422)
        ->and($refused->body)->toContain('Roots could not create the Module&#039;s data')
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['source'] ?? null)->toBe('roots')
        ->and(loggedEvents($install))->not->toContain('module.fault')
        ->and(moduleStoreExists($install, 'in.hours'))->toBeFalse()
        ->and(moduleSection(devPage($install, $token, ['screen' => 'modules'])->body, 'in.hours'))->toContain('Uploaded')
        ->and(moduleSection(devPage($install, $token, ['screen' => 'modules'])->body, 'in.hours'))->not->toContain('Errors:');
});
