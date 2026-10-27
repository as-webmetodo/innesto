<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\App;
use Innesto\Roots\Http\Request;
use Innesto\Roots\Http\Response;
use Innesto\Tests\FixedClock;

/**
 * A fresh install: `<webroot>/innesto/` inside a temporary filesystem, with a
 * writable directory above the webroot.
 *
 * @return array{app: App, clock: FixedClock, roots: string, webroot: string}
 */
function install(): array
{
    $base = sys_get_temp_dir() . '/innesto-app-' . bin2hex(random_bytes(6));
    $webroot = "$base/public_html";
    mkdir("$webroot/innesto", 0o777, true);
    $clock = new FixedClock(new DateTimeImmutable('2026-10-27T10:00:00Z'));

    return ['app' => new App("$webroot/innesto", $webroot, $clock), 'clock' => $clock, 'roots' => "$webroot/innesto", 'webroot' => $webroot];
}

/**
 * @param array<string, string> $form
 * @param array<string, string> $cookies
 * @param array<string, string> $headers
 */
function request(string $method, string $entry, array $form = [], array $cookies = [], array $headers = [], string $host = 'example.com', bool $https = true, string $ip = '203.0.113.7'): Request
{
    return new Request($method, $entry, '/innesto/', $host, $https, $ip, $form, $cookies, $headers);
}

/** @return list<array<string, mixed>> the JSON lines after the guard */
function logLines(string $file): array
{
    $contents = (string) file_get_contents($file);
    expect($contents)->toStartWith("<?php exit; ?>\n");
    $lines = array_filter(explode("\n", substr($contents, strlen("<?php exit; ?>\n"))));

    $decoded = [];
    foreach ($lines as $line) {
        $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        expect($entry)->toBeArray();
        /** @var array<string, mixed> $entry */
        $decoded[] = $entry;
    }

    return $decoded;
}

const ORIGIN = ['origin' => 'https://example.com'];

const PASSWORD = 'correct horse battery';

/** @param array{roots: string} $install */
function dataPath(array $install): string
{
    $path = require $install['roots'] . '/data-path.php';

    return is_string($path) ? $path : throw new UnexpectedValueException('data-path.php returns no path');
}

/**
 * The Setup code as the Developer reads it over FTP.
 *
 * @param array{roots: string} $install
 */
function setupCode(array $install): string
{
    $file = dataPath($install) . '/setup-code.php';
    expect(file_get_contents($file))->toStartWith("<?php exit; ?>\n");
    expect(preg_match('/Setup code: (\S+)/', (string) file_get_contents($file), $match))->toBe(1);

    return $match[1] ?? '';
}

/** The session token a response sets, or '' when it clears the cookie. */
function sessionCookie(Response $response): ?string
{
    foreach ($response->headers('Set-Cookie') as $cookie) {
        if (preg_match('/^innesto_dev=([^;]*)/', $cookie, $match) === 1) {
            return $match[1];
        }
    }

    return null;
}

/**
 * Walks the first run and returns the Developer's session token.
 *
 * @param array{app: App, roots: string} $install
 */
function completeSetup(array $install, string $password = PASSWORD): string
{
    $install['app']->handle(request('GET', 'dev'));
    $response = $install['app']->handle(request('POST', 'dev', ['action' => 'setup', 'code' => setupCode($install), 'password' => $password], headers: ORIGIN));
    expect($response->status)->toBe(303);

    return (string) sessionCookie($response);
}

/** @param array{app: App} $install */
function signedInPage(array $install, string $token): Response
{
    return $install['app']->handle(request('GET', 'dev', cookies: ['innesto_dev' => $token]));
}

/**
 * The CSRF token in the forms of a signed-in page.
 *
 * @param array{app: App} $install
 */
function csrfToken(array $install, string $token): string
{
    expect(preg_match('/name="csrf" value="([^"]+)"/', signedInPage($install, $token)->body, $match))->toBe(1);

    return $match[1] ?? '';
}

/**
 * The events in the Roots log of an install.
 *
 * @param array{roots: string} $install
 * @return list<mixed>
 */
function loggedEvents(array $install): array
{
    return array_column(logLines(dataPath($install) . '/roots-log.php'), 'event');
}
