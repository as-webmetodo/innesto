<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

/**
 * Posts the Health Screen's own Self-check run.
 *
 * @param array{app: Innesto\Roots\App} $install
 * @param array<string, bool|int> $probes changes to PROBES
 */
function runSelfCheck(array $install, string $token, array $probes = []): Innesto\Roots\Http\Response
{
    return devPost($install, $token, ['action' => 'selfcheck', 'probes' => json_encode(array_replace(PROBES, $probes), JSON_THROW_ON_ERROR)]);
}

/** @param array{app: Innesto\Roots\App} $install */
function homeBadge(array $install, string $token): bool
{
    return str_contains(signedInPage($install, $token)->body, 'something needs your attention');
}

test('the Health Screen is the Developer\'s only', function (): void {
    $install = install();
    confirmedDeveloper($install);

    $response = $install['app']->handle(request('GET', 'dev', query: ['screen' => 'health']));

    expect($response->body)->not->toContain('Roots log')
        ->and($response->body)->toContain('name="action" value="signin"');
});

test('the Health Screen shows the latest Self-check, sign-in failures and the Roots log, and offers a new run', function (): void {
    $install = install();
    $token = confirmedDeveloper($install);
    $install['app']->handle(request('POST', 'dev', ['action' => 'signin', 'password' => 'wrong password!'], headers: ORIGIN, ip: '198.51.100.23'));

    $page = devPage($install, $token, ['screen' => 'health']);

    expect($page->status)->toBe(200)
        ->and($page->body)->toContain('Server checks, 2026-10-27T10:00:00Z')
        ->and($page->body)->toContain('HTTP checks from the browser, 2026-10-27T10:00:00Z')
        ->and($page->body)->toContain('<strong>OK:</strong> A guarded PHP file in the Data directory shows nothing over HTTP.')
        ->and($page->body)->toContain('Failed sign-ins in the Roots log: 1.')
        ->and($page->body)->toContain('signin.failed 198.51.100.0')
        ->and($page->body)->toContain('roots shared_config.changed: Shared config changed')
        ->and($page->body)->toContain('name="action" value="selfcheck"')
        ->and($page->body)->toContain('No Module has had an error.');
});

test('a run from the Health Screen is stored with its time; a Fail after install is shown and logged, and Roots keeps serving', function (): void {
    $install = install();
    $token = confirmedDeveloper($install);
    $install['clock']->advance(3600);

    $response = runSelfCheck($install, $token, ['data_php' => true]);
    $page = devPage($install, $token, ['screen' => 'health']);

    expect($response->status)->toBe(303)
        ->and($response->header('Location'))->toBe('/innesto/dev/?screen=health')
        ->and($page->body)->toContain('HTTP checks from the browser, 2026-10-27T11:00:00Z')
        ->and($page->body)->toContain('<strong>Fail:</strong> A guarded PHP file in the Data directory shows its contents over HTTP')
        ->and(loggedEvents($install))->toContain('selfcheck.fail')
        ->and(signedInPage($install, $token)->status)->toBe(200);
});

test('a run without probe results is refused', function (): void {
    $install = install();
    $token = confirmedDeveloper($install);

    $response = devPost($install, $token, ['action' => 'selfcheck', 'probes' => '']);

    expect($response->status)->toBe(422)
        ->and($response->body)->toContain('needs JavaScript');
});

test('the home badge shows for a Warn or a Fail, and for an error until the Health Screen is opened', function (): void {
    $install = install();
    $token = confirmedDeveloper($install);
    $clean = homeBadge($install, $token);

    runSelfCheck($install, $token, ['skew' => 600]);
    $warn = homeBadge($install, $token);
    $install['clock']->advance(300);
    runSelfCheck($install, $token, ['data_php' => true]);
    $install['clock']->advance(300);
    runSelfCheck($install, $token);
    $unseen = homeBadge($install, $token);
    devPage($install, $token, ['screen' => 'health']);

    expect($clean)->toBeFalse()
        ->and($warn)->toBeTrue()
        // The Fail is gone, but the error it logged is not seen yet.
        ->and($unseen)->toBeTrue()
        ->and(homeBadge($install, $token))->toBeFalse();
})->skip(fn (): bool => !extension_loaded('pdo_sqlite'), 'a host without SQLite always has a Warn');

test('a Module error raises the badge until the Health Screen, which lists it, is opened', function (): void {
    $install = install();
    $token = confirmedDeveloper($install);
    devPage($install, $token, ['screen' => 'health']);
    $install['clock']->advance(300);
    rootsStores($install)->runtime->put('faults', ['id' => 'in.hours', 'count' => 2, 'message' => 'RuntimeException: boom', 'time' => '2026-10-27T10:05:00Z']);

    $badge = homeBadge($install, $token);
    $page = devPage($install, $token, ['screen' => 'health']);

    expect($badge)->toBeTrue()
        ->and($page->body)->toContain('in.hours: Errors: 2. Last, at 2026-10-27T10:05:00Z: RuntimeException: boom')
        ->and(homeBadge($install, $token))->toBeFalse();
})->skip(fn (): bool => !extension_loaded('pdo_sqlite'), 'a host without SQLite always has a Warn');

test('the Roots log downloads with both generations, oldest first', function (): void {
    $install = install();
    $token = confirmedDeveloper($install);
    file_put_contents(dataPath($install) . '/roots-log.1.php', "<?php exit; ?>\n{\"event\":\"older.generation\"}\n");

    $response = devPage($install, $token, ['screen' => 'health', 'download' => 'log']);

    expect($response->status)->toBe(200)
        ->and($response->header('Content-Disposition'))->toBe('attachment; filename="roots-log.jsonl"')
        ->and($response->header('Cache-Control'))->toBe('no-store')
        ->and($response->body)->toStartWith("{\"event\":\"older.generation\"}\n")
        ->and($response->body)->toContain('"event":"shared_config.changed"')
        ->and($response->body)->not->toContain('<?php');
});

test('Send test mail refuses anything but one plain address', function (string $to): void {
    $install = install();
    $token = confirmedDeveloper($install);

    $response = devPost($install, $token, ['action' => 'test_mail', 'to' => $to], ['screen' => 'health']);

    expect($response->status)->toBe(422)
        ->and($response->body)->toContain('Enter one plain email address.');
})->with(['', 'not an address', "a@example.com\r\nBcc: b@example.com", 'a@example.com, b@example.com']);
