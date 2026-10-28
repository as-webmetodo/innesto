<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

test('the Roots URL redirects to the Developer area', function (): void {
    $response = install()['app']->handle(request('GET', 'index'));

    expect($response->status)->toBe(302)
        ->and($response->header('Location'))->toBe('/innesto/dev/');
});

test('the Developer area refuses plain HTTP', function (): void {
    $response = install()['app']->handle(request('GET', 'dev', https: false));

    expect($response->status)->toBe(403)
        ->and($response->body)->toContain('HTTPS')
        // The browser-run probe explains a proxy that reports HTTPS only through X-Forwarded-*.
        ->and($response->body)->toContain('<p id="proxied" hidden>')
        ->and($response->body)->toContain("location.protocol === 'https:'");
});

test('plain HTTP is allowed on a loopback host', function (string $host): void {
    $response = install()['app']->handle(request('GET', 'dev', host: $host, https: false));

    expect($response->status)->toBe(200);
})->with(['localhost:8080', '127.0.0.1', '[::1]:8080']);

test('the first visit issues a Setup code in the Data directory and asks for it', function (): void {
    $install = install();

    $response = $install['app']->handle(request('GET', 'dev'));

    expect($response->status)->toBe(200)
        ->and($response->body)->toContain('name="code"')
        ->and(setupCode($install))->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/')
        ->and(loggedEvents($install))->toBe(['setup_code.issued']);
});

test('the Setup code stays the same until it is used', function (): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));
    $code = setupCode($install);

    $install['app']->handle(request('GET', 'dev'));

    expect(setupCode($install))->toBe($code);
});

test('the Setup code and a new password sign the Developer in', function (): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));

    $response = $install['app']->handle(request('POST', 'dev', ['code' => strtolower(setupCode($install))] + setupForm($install), headers: ORIGIN));

    expect($response->status)->toBe(303)
        ->and($response->header('Location'))->toBe('/innesto/dev/')
        ->and(is_file(dataPath($install) . '/setup-code.php'))->toBeFalse()
        ->and(loggedEvents($install))->toBe(['setup_code.issued', 'setup.completed']);
    expect(signedInPage($install, (string) sessionCookie($response))->body)->toContain('name="action" value="signout"');
});

test('the session cookie is HttpOnly, Secure, SameSite=Strict and scoped to dev/', function (): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));

    $response = $install['app']->handle(request('POST', 'dev', setupForm($install, password: PASSWORD), headers: ORIGIN));

    expect($response->header('Set-Cookie'))->toMatch('~^innesto_dev=[0-9a-f]{64}; Path=/innesto/dev/; Secure; HttpOnly; SameSite=Strict$~');
});

test('a wrong Setup code is refused and the code stays', function (): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));
    $code = setupCode($install);

    $response = $install['app']->handle(request('POST', 'dev', ['action' => 'setup', 'code' => 'AAAA-AAAA-AAAA', 'password' => PASSWORD], headers: ORIGIN));

    expect($response->status)->toBe(422)
        ->and($response->body)->toContain('The Setup code is not right.')
        ->and(sessionCookie($response))->toBeNull()
        ->and(setupCode($install))->toBe($code)
        ->and(loggedEvents($install))->toBe(['setup_code.issued', 'setup.failed']);
});

test('a Developer password needs at least 12 characters', function (string $password, int $status): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));

    $response = $install['app']->handle(request('POST', 'dev', setupForm($install, password: $password), headers: ORIGIN));

    expect($response->status)->toBe($status);
})->with([
    'eleven characters' => ['abcdefghijk', 422],
    'twelve multibyte characters' => ['àèìòùàèìòùàè', 303],
    'a NUL byte, which bcrypt refuses' => ["abcdef\0ghijklm", 422],
]);

test('Developer-area HTML is never cached or framed', function (): void {
    $response = install()['app']->handle(request('GET', 'dev'));

    expect($response->header('Cache-Control'))->toBe('no-store')
        ->and($response->header('Content-Security-Policy'))->toBe("frame-ancestors 'none'")
        ->and($response->header('Content-Type'))->toBe('text/html; charset=utf-8');
});

test('when <roots> is not writable, the page shows the data-path.php to upload', function (): void {
    $install = install();
    chmod($install['roots'], 0o555);

    $response = $install['app']->handle(request('GET', 'dev'));
    chmod($install['roots'], 0o755);

    expect($response->status)->toBe(200)
        ->and($response->body)->toContain('data-path.php')
        ->and($response->body)->toContain(htmlspecialchars("defined('INNESTO_ROOTS') || exit;"));
});

test('Roots\' Stores start on guarded files and move to SQLite on the request after setup, if it is available', function (): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));
    $data = dataPath($install);
    $before = [is_file("$data/roots-content.php"), is_file("$data/roots-runtime.php")];

    $token = completeSetup($install);
    $afterSetup = [is_file("$data/roots-content.php"), is_file("$data/roots-to-sqlite.php")];
    $page = signedInPage($install, $token);

    expect($before)->toBe([true, true])
        // The setup request itself never moves them: its own objects hold the file Stores.
        ->and($afterSetup)->toBe([true, true])
        ->and(is_file("$data/roots-content.sqlite"))->toBeTrue()
        ->and(is_file("$data/roots-runtime.sqlite"))->toBeTrue()
        ->and([...glob("$data/roots-content.php*") ?: [], ...glob("$data/roots-runtime.php*") ?: [], ...glob("$data/roots-to-sqlite.php") ?: []])->toBe([])
        // The password and the session moved with the Stores.
        ->and($page->body)->toContain('value="signout"');
})->skip(fn (): bool => !extension_loaded('pdo_sqlite'), 'needs SQLite');

test('a write after setup\'s last step, such as a fault counted at shutdown, moves to SQLite too', function (): void {
    $install = install();
    $token = completeSetup($install);
    // What the setup request's own FaultFence would write after the response.
    $late = Innesto\Roots\Store\FileStore::open(dataPath($install) . '/roots-runtime.php', ['schema' => 1, 'collections' => ['faults' => ['fields' => []]]]);
    $late->put('faults', ['id' => 'in.hours', 'count' => 1, 'message' => 'late', 'time' => '2026-10-27T10:00:00Z']);

    signedInPage($install, $token);

    expect(is_file(dataPath($install) . '/roots-runtime.php'))->toBeFalse()
        ->and(rootsStores($install)->runtime->get('faults', 'in.hours')['message'] ?? null)->toBe('late');
})->skip(fn (): bool => !extension_loaded('pdo_sqlite'), 'needs SQLite');

test('a move to SQLite cut short is redone from the files on the next request', function (): void {
    $install = install();
    $token = completeSetup($install);
    $data = dataPath($install);
    // A request died after the runtime Store's move and during the content Store's.
    file_put_contents("$data/roots-runtime.sqlite", 'half');
    file_put_contents("$data/roots-content.sqlite.new", 'half');

    $page = signedInPage($install, $token);

    expect($page->body)->toContain('value="signout"')
        ->and(rootsStores($install)->sqlite)->toBeTrue()
        ->and([...glob("$data/*.sqlite.new") ?: [], ...glob("$data/roots-content.php*") ?: [], ...glob("$data/roots-runtime.php*") ?: [], ...glob("$data/roots-to-sqlite.php") ?: []])->toBe([]);
})->skip(fn (): bool => !extension_loaded('pdo_sqlite'), 'needs SQLite');

test('a Data directory that is no longer writable stops Roots with a page that says how to fix it', function (): void {
    $install = install();
    completeSetup($install);
    $data = dataPath($install);
    chmod($data, 0o555);

    try {
        $response = $install['app']->handle(request('GET', 'dev'));
    } finally {
        chmod($data, 0o755);
    }

    expect($response->status)->toBe(500)
        ->and($response->body)->toContain('Roots cannot create its Data directory');
});

test('Roots\' Stores use guarded files in a Data directory inside the webroot', function (): void {
    $install = install();
    // The Developer moved the Data directory into the webroot by editing data-path.php.
    file_put_contents($install['roots'] . '/data-path.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($install['roots'] . '/data-moved', true) . ";\n");

    $install['app']->handle(request('GET', 'dev'));

    expect(is_file($install['roots'] . '/data-moved/roots-content.php'))->toBeTrue()
        ->and(is_file($install['roots'] . '/data-moved/.htaccess'))->toBeTrue();
});

test('under the localhost override the cookie leaves out Secure, which browsers refuse over HTTP', function (): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev', host: 'localhost:8080', https: false));

    $response = $install['app']->handle(request('POST', 'dev', setupForm($install, password: PASSWORD), headers: ['origin' => 'http://localhost:8080'], host: 'localhost:8080', https: false));

    expect($response->header('Set-Cookie'))->toMatch('~^innesto_dev=[0-9a-f]{64}; Path=/innesto/dev/; HttpOnly; SameSite=Strict$~');
});
