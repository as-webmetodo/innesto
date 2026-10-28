<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

test('setup without the browser\'s probe results is refused: the Self-check needs JavaScript', function (): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));
    $code = setupCode($install);

    $response = $install['app']->handle(request('POST', 'dev', ['action' => 'setup', 'code' => $code, 'password' => PASSWORD], headers: ORIGIN));

    expect($response->status)->toBe(422)
        ->and($response->body)->toContain('needs JavaScript')
        ->and(sessionCookie($response))->toBeNull()
        ->and(setupCode($install))->toBe($code);
});

test('the setup page writes the probe files and carries the probes, with URLs relative to Roots\' own', function (): void {
    $install = install();

    $page = $install['app']->handle(request('GET', 'dev'));
    $data = dataPath($install);

    expect($page->body)->toContain('"txt":"/innesto/../../' . basename($data) . '/probe.txt"')
        ->and($page->body)->toContain('"htaccess":"/innesto/templates/probe.txt"')
        ->and($page->body)->toContain('name="probes"')
        ->and(file_get_contents("$data/probe.txt"))->toContain(Innesto\Roots\SelfCheck::MARKER)
        ->and(file_get_contents("$data/probe.php"))->toStartWith("<?php exit; ?>\n")
        ->and(file_get_contents(__DIR__ . '/../../templates/probe.txt'))->toContain(Innesto\Roots\SelfCheck::MARKER);
});

test('a guarded PHP probe that shows its contents is a Fail: setup refuses to finish and logs it', function (): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));
    $code = setupCode($install);

    $response = $install['app']->handle(request('POST', 'dev', setupForm($install, ['data_php' => true]), headers: ORIGIN));

    expect($response->status)->toBe(422)
        ->and($response->body)->toContain('found a Fail')
        ->and($response->body)->toContain('shows its contents over HTTP')
        ->and(sessionCookie($response))->toBeNull()
        ->and(setupCode($install))->toBe($code)
        ->and(loggedEvents($install))->toBe(['setup_code.issued', 'selfcheck.fail']);
});

test('malformed probe results are refused', function (string $probes): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));

    $response = $install['app']->handle(request('POST', 'dev', ['probes' => $probes] + setupForm($install), headers: ORIGIN));

    expect($response->status)->toBe(422)
        ->and($response->body)->toContain('needs JavaScript');
})->with([
    'not JSON' => ['{'],
    'a missing probe' => ['{"data_txt":false,"data_php":false,"skew":0}'],
    'an extra key' => ['{"data_txt":false,"data_php":false,"htaccess":true,"skew":0,"https":true}'],
    'a string for a boolean' => ['{"data_txt":"false","data_php":false,"htaccess":true,"skew":0}'],
    'a float skew' => ['{"data_txt":false,"data_php":false,"htaccess":true,"skew":1.5}'],
]);

test('a reachable Data directory is a Warn and keeps guarded files, with the reason shown', function (): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));

    $response = $install['app']->handle(request('POST', 'dev', setupForm($install, ['data_txt' => true, 'htaccess' => false]), headers: ORIGIN));
    postConfig($install, (string) sessionCookie($response), SHARED_FORM);
    $health = devPage($install, (string) sessionCookie($response), ['screen' => 'health'])->body;

    expect($response->status)->toBe(303)
        ->and(is_file(dataPath($install) . '/roots-content.php'))->toBeTrue()
        ->and(is_file(dataPath($install) . '/roots-content.sqlite'))->toBeFalse()
        ->and($health)->toContain('guarded files: the Data directory can be reached over HTTP.')
        ->and($health)->toContain('<li data-grade="warn"><strong>Warn:</strong> Files in the Data directory can be reached over HTTP')
        ->and($health)->toContain('<li data-grade="warn"><strong>Warn:</strong> The host ignores .htaccess');
});

test('the Developer may keep guarded files at setup, and the backend then stays fixed', function (): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));

    $response = $install['app']->handle(request('POST', 'dev', ['files' => 'on'] + setupForm($install), headers: ORIGIN));
    $token = (string) sessionCookie($response);
    postConfig($install, $token, SHARED_FORM);

    expect(is_file(dataPath($install) . '/roots-content.php'))->toBeTrue()
        ->and(devPage($install, $token, ['screen' => 'health'])->body)->toContain('guarded files, as chosen at setup.');
});

test('a password reset runs the Self-check again but never changes the backend', function (): void {
    $install = install();
    confirmedDeveloper($install);
    $backend = is_file(dataPath($install) . '/roots-content.sqlite') ? 'sqlite' : 'php';
    touch(dataPath($install) . '/reset-marker');
    $install['app']->handle(request('GET', 'dev'));

    $response = $install['app']->handle(request('POST', 'dev', ['files' => 'on'] + setupForm($install, ['htaccess' => false]), headers: ORIGIN));

    expect($response->status)->toBe(303)
        ->and(is_file(dataPath($install) . "/roots-content.$backend"))->toBeTrue()
        ->and(devPage($install, (string) sessionCookie($response), ['screen' => 'health'])->body)->toContain('<strong>Warn:</strong> The host ignores .htaccess');
});

test('on SQLite, a reachable Data directory is a Fail, so a password reset refuses to finish', function (): void {
    $install = install();
    confirmedDeveloper($install);
    touch(dataPath($install) . '/reset-marker');
    $install['app']->handle(request('GET', 'dev'));

    $response = $install['app']->handle(request('POST', 'dev', setupForm($install, ['data_txt' => true]), headers: ORIGIN));

    expect($response->status)->toBe(422)
        ->and($response->body)->toContain('anyone can download it');
})->skip(fn (): bool => !extension_loaded('pdo_sqlite'), 'needs SQLite');

test('on an addon domain, a Data directory in public_html is a Warn and keeps guarded files', function (): void {
    // public_html/ is the main domain's webroot; this site's is public_html/addon.example/.
    $base = sys_get_temp_dir() . '/innesto-addon-' . bin2hex(random_bytes(6));
    $webroot = "$base/public_html/addon.example";
    mkdir("$webroot/innesto", 0o777, true);
    $install = ['app' => new Innesto\Roots\App("$webroot/innesto", $webroot, new Innesto\Tests\FixedClock(new DateTimeImmutable('2026-10-27T10:00:00Z'))), 'roots' => "$webroot/innesto", 'webroot' => $webroot];

    $token = confirmedDeveloper($install);
    $health = devPage($install, $token, ['screen' => 'health'])->body;

    expect(dirname(dataPath($install)))->toBe("$base/public_html")
        ->and(is_file(dataPath($install) . '/.htaccess'))->toBeTrue()
        ->and(is_file(dataPath($install) . '/roots-content.php'))->toBeTrue()
        ->and($health)->toContain('<li data-grade="warn"><strong>Warn:</strong> The Data directory is in a folder named like a webroot')
        ->and($health)->toContain('guarded files: the Data directory may be served by another site.');
});

/** Facts of a host where everything is fine; each case changes some. */
const GOOD_HOST = [
    'php' => '8.3.12', 'sqlite' => '3.45.1', 'mail' => true, 'max_execution_time' => '30', 'memory_limit' => '64M',
    'upload_max_filesize' => '2M', 'post_max_size' => '8M', 'data_dir' => 'outside', 'https' => true, 'loopback' => false,
    'profile_complete' => true, 'site_url_default' => false,
];

test('server findings are graded by the thresholds of §3.11', function (array $facts, string $id, string $grade): void {
    // @phpstan-ignore argument.type (each case only changes fields of GOOD_HOST)
    $findings = Innesto\Roots\SelfCheck::serverFindings(array_replace(GOOD_HOST, $facts));
    $graded = array_column($findings, 'grade', 'id');

    expect($graded[$id] ?? null)->toBe($grade)
        ->and(array_diff_key(array_filter($graded, static fn (string $g): bool => $g !== 'ok'), [$id => true]))->toBe([]);
})->with([
    'a good host' => [[], 'php', 'ok'],
    'PHP 8.2' => [['php' => '8.2.20'], 'php', 'fail'],
    'no pdo_sqlite' => [['sqlite' => null], 'sqlite', 'warn'],
    'SQLite 3.26' => [['sqlite' => '3.26.0'], 'sqlite', 'warn'],
    'SQLite 3.27' => [['sqlite' => '3.27.0'], 'sqlite', 'ok'],
    'no mail()' => [['mail' => false], 'mail', 'warn'],
    'Data directory in the webroot' => [['data_dir' => 'webroot'], 'data_dir', 'warn'],
    'Data directory in another webroot' => [['data_dir' => 'other_webroot'], 'data_dir_other', 'warn'],
    'no HTTPS' => [['https' => false], 'https', 'fail'],
    'no HTTPS on localhost' => [['https' => false, 'loopback' => true], 'https_localhost', 'ok'],
    '29 s' => [['max_execution_time' => '29'], 'max_execution_time', 'warn'],
    'no time limit' => [['max_execution_time' => '0'], 'max_execution_time', 'ok'],
    '63M' => [['memory_limit' => '63M'], 'memory_limit', 'warn'],
    '1G' => [['memory_limit' => '1G'], 'memory_limit', 'ok'],
    'no memory limit' => [['memory_limit' => '-1'], 'memory_limit', 'ok'],
    'uploads of 1M' => [['upload_max_filesize' => '1M'], 'upload_size', 'warn'],
    'posts of 1536K' => [['post_max_size' => '1536K'], 'upload_size', 'warn'],
    'an incomplete Business profile' => [['profile_complete' => false], 'profile', 'warn'],
    'the default Site URL' => [['site_url_default' => true], 'site_url', 'warn'],
]);

test('browser findings: clock skew over 2 minutes is a Warn, and a reachable SQLite Data directory a Fail', function (array $results, bool $sqlite, string $id, string $grade): void {
    // @phpstan-ignore argument.type (each case only changes fields of PROBES)
    $findings = Innesto\Roots\SelfCheck::browserFindings(array_replace(PROBES, $results), $sqlite);

    expect(array_column($findings, 'grade', 'id')[$id] ?? null)->toBe($grade);
})->with([
    '120 s ahead' => [['skew' => 120], false, 'clock', 'ok'],
    '121 s behind' => [['skew' => -121], false, 'clock', 'warn'],
    'reachable, on files' => [['data_txt' => true], false, 'data_txt', 'warn'],
    'reachable, on SQLite' => [['data_txt' => true], true, 'data_txt', 'fail'],
    '.htaccess ignored' => [['htaccess' => false], false, 'htaccess', 'warn'],
    'guarded PHP shown' => [['data_php' => true], false, 'data_php', 'fail'],
]);
