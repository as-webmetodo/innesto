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
 * @param array<string, string> $query
 */
function request(string $method, string $entry, array $form = [], array $cookies = [], array $headers = [], string $host = 'example.com', bool $https = true, string $ip = '203.0.113.7', array $query = []): Request
{
    return new Request($method, $entry, '/innesto/', $host, $https, $ip, $form, $cookies, $headers, $query);
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

/** What the browser's probes post on a host that keeps the Data directory unreachable. */
const PROBES = ['data_txt' => false, 'data_php' => false, 'htaccess' => true, 'skew' => 0];

/**
 * The setup form as the browser posts it, with the probe results.
 *
 * @param array{roots: string} $install
 * @param array<string, bool|int> $probes changes to PROBES
 * @return array<string, string>
 */
function setupForm(array $install, array $probes = [], string $password = PASSWORD): array
{
    return ['action' => 'setup', 'code' => setupCode($install), 'password' => $password, 'probes' => json_encode(array_replace(PROBES, $probes), JSON_THROW_ON_ERROR)];
}

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
    $response = $install['app']->handle(request('POST', 'dev', setupForm($install, password: $password), headers: ORIGIN));
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

/**
 * Roots' Stores, as later consumers open them.
 *
 * @param array{roots: string, webroot: string} $install
 */
function rootsStores(array $install): Innesto\Roots\RootsStores
{
    return Innesto\Roots\RootsStores::open(Innesto\Roots\DataDirectory::locate($install['roots'], $install['webroot']));
}

/**
 * Roots' content Store, as later consumers open it.
 *
 * @param array{roots: string, webroot: string} $install
 */
function contentStore(array $install): Innesto\Roots\Store\Store
{
    return rootsStores($install)->content;
}

const SHARED_FORM = [
    'action' => 'shared', 'site_url' => 'https://www.example.com/', 'timezone' => 'Europe/Rome',
    'locale_it' => 'on', 'locale_en' => 'on', 'default_locale' => 'it',
    'name' => 'Forno Rossi', 'type' => 'Bakery', 'street_address' => 'Via Roma 1', 'postal_code' => '00184',
    'locality' => 'Roma', 'region' => 'RM', 'country' => 'it', 'phone' => '+39 06 1234 567', 'url' => '', 'image' => 'https://www.example.com/forno.jpg',
];

/**
 * Posts a config form of a signed-in Developer.
 *
 * @param array{app: Innesto\Roots\App} $install
 * @param array<string, string> $form
 */
function postConfig(array $install, string $token, array $form): Innesto\Roots\Http\Response
{
    return $install['app']->handle(request('POST', 'dev', $form + ['csrf' => csrfToken($install, $token)], ['innesto_dev' => $token], ORIGIN));
}

/**
 * Uploads Modules into an install, as the Developer does over FTP: each
 * directory is copied into `<roots>/modules/` under its own name.
 *
 * @param array{roots: string} $install
 */
function uploadModules(array $install, string ...$dirs): void
{
    foreach ($dirs as $dir) {
        $target = $install['roots'] . '/modules/' . basename($dir);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        is_dir($target) || mkdir($target, 0o777, true);
        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);
            $path = $target . substr($file->getPathname(), strlen($dir));
            $file->isDir() ? (is_dir($path) || mkdir($path)) : copy($file->getPathname(), $path);
        }
    }
}

/** Innesto Orario as shipped in this repository. */
const ORARIO = __DIR__ . '/../../modules/in.hours';

/** The fake Modules the Module tests upload. */
const FAKE_MODULES = __DIR__ . '/../fixtures/modules';

/**
 * A Developer past the first run, with the Shared config confirmed: their
 * session token.
 *
 * @param array{app: Innesto\Roots\App, roots: string} $install
 */
function confirmedDeveloper(array $install): string
{
    $token = completeSetup($install);
    expect(postConfig($install, $token, SHARED_FORM)->status)->toBe(303);

    return $token;
}

/**
 * A GET of the Developer area with $query, signed in with $token.
 *
 * @param array{app: Innesto\Roots\App} $install
 * @param array<string, string> $query
 */
function devPage(array $install, string $token, array $query): Innesto\Roots\Http\Response
{
    return $install['app']->handle(request('GET', 'dev', cookies: ['innesto_dev' => $token], query: $query));
}

/**
 * Posts a Developer-area form with the session's CSRF token.
 *
 * @param array{app: Innesto\Roots\App} $install
 * @param array<string, string> $form
 * @param array<string, string> $query
 */
function devPost(array $install, string $token, array $form, array $query = []): Innesto\Roots\Http\Response
{
    return $install['app']->handle(request('POST', 'dev', $form + ['csrf' => csrfToken($install, $token)], ['innesto_dev' => $token], ORIGIN, query: $query));
}

/**
 * Uploads a Module whose Manifest is Orario's with $changes, under folder
 * $folder; a string $changes is written as module.php verbatim, and an
 * empty one leaves the folder without module.php.
 *
 * @param array{roots: string} $install
 * @param array<string, mixed>|string $changes
 */
function uploadManifest(array $install, string $folder, array|string $changes): void
{
    defined('INNESTO_ROOTS') || define('INNESTO_ROOTS', true);
    $dir = $install['roots'] . "/modules/$folder";
    is_dir($dir) || mkdir($dir, 0o777, true);
    $orario = require ORARIO . '/module.php';
    assert(is_array($orario));
    $manifest = is_string($changes) ? $changes : "<?php\n\nreturn " . var_export(array_replace($orario, ['id' => $folder], $changes), true) . ";\n";
    $manifest === '' || file_put_contents("$dir/module.php", $manifest);
}
