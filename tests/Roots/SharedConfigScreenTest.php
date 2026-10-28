<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

test('until the Shared config is confirmed, the Developer area opens on it, pre-filled with defaults', function (): void {
    $install = install();
    $token = completeSetup($install);

    $page = signedInPage($install, $token);

    expect($page->status)->toBe(200)
        ->and($page->body)->toContain('name="action" value="shared"')
        ->and($page->body)->toContain('name="site_url" value="https://example.com/"')
        ->and($page->body)->toContain('<option value="UTC" selected>')
        ->and($page->body)->toContain('<option value="LocalBusiness" selected>')
        ->and($page->body)->toContain('Intl.DateTimeFormat()')
        ->and($page->body)->toContain('value="signout"');
});

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

test('confirming the Shared config saves it, logs the change and bumps the content Store revision', function (): void {
    $install = install();
    $token = completeSetup($install);
    $revision = contentStore($install)->revision();

    $response = postConfig($install, $token, SHARED_FORM);
    $config = Innesto\Roots\SharedConfig::fromStore(contentStore($install), 'https://example.com');

    expect($response->status)->toBe(303)
        ->and($response->header('Location'))->toBe('/innesto/dev/')
        ->and(contentStore($install)->revision())->toBeGreaterThan($revision)
        ->and(loggedEvents($install))->toBe(['setup_code.issued', 'setup.completed', 'shared_config.changed'])
        ->and(signedInPage($install, $token)->body)->toContain('href="?screen=config"')
        ->and($config->businessName)->toBe('Forno Rossi')
        ->and($config->businessType)->toBe('Bakery')
        ->and($config->country)->toBe('IT')
        ->and($config->businessUrl)->toBe('https://www.example.com/')
        ->and($config->defaultLocale)->toBe('it')
        ->and($config->enabledLocales)->toBe(['it', 'en'])
        ->and($config->installTimezone)->toBe('Europe/Rome')
        ->and($config->siteUrl)->toBe('https://www.example.com/');
});

test('saving the same Shared config again changes nothing', function (): void {
    $install = install();
    $token = completeSetup($install);
    postConfig($install, $token, SHARED_FORM);
    $revision = contentStore($install)->revision();

    $response = postConfig($install, $token, SHARED_FORM);

    expect($response->status)->toBe(303)
        ->and(contentStore($install)->revision())->toBe($revision)
        ->and(loggedEvents($install))->toBe(['setup_code.issued', 'setup.completed', 'shared_config.changed']);
});

test('an invalid Shared config is refused with 422, naming the fields at fault', function (string $field, string $value): void {
    $install = install();
    $token = completeSetup($install);
    $revision = contentStore($install)->revision();

    $response = postConfig($install, $token, [$field => $value] + SHARED_FORM);

    expect($response->status)->toBe(422)
        ->and(preg_match_all('/<li><a href="#([a-z_]+)"/', $response->body, $match))->toBe(1)
        ->and($match[1])->toBe([$field])
        ->and($response->body)->toContain('name="name" value="' . ($field === 'name' ? '' : 'Forno Rossi'))
        ->and(contentStore($install)->revision())->toBe($revision)
        ->and(signedInPage($install, $token)->body)->toContain('name="action" value="shared"');
})->with([
    'unknown type' => ['type', 'Organization'],
    'unknown timezone' => ['timezone', 'Mars/Olympus'],
    'Default locale not enabled' => ['default_locale', 'en-GB'],
    'locale Roots does not cover' => ['default_locale', 'fr'],
    'relative Site URL' => ['site_url', '/'],
    'non-web Site URL' => ['site_url', 'javascript:alert(1)'],
    'non-web image' => ['image', 'ftp://example.com/a.png'],
    'space in a URL' => ['url', 'https://example.com/a b'],
    'URL without a host' => ['url', 'https:///path'],
    'country name' => ['country', 'Italy'],
    'letters in the phone' => ['phone', 'call us'],
    'control character' => ['name', "Forno\x07Rossi"],
    'too long' => ['name', str_repeat('a', 201)],
]);

test('the Business profile URL defaults to the Site URL', function (): void {
    $install = install();
    $token = completeSetup($install);

    postConfig($install, $token, ['url' => 'https://forno.example/'] + SHARED_FORM);

    expect(Innesto\Roots\SharedConfig::fromStore(contentStore($install), 'https://example.com')->businessUrl)->toBe('https://forno.example/');
});

test('the Roots settings default to a 5-minute max-age and save within 1 to 60 minutes', function (): void {
    $install = install();
    $token = completeSetup($install);
    postConfig($install, $token, SHARED_FORM);

    $before = $install['app']->handle(request('GET', 'dev', cookies: ['innesto_dev' => $token], query: ['screen' => 'config']));
    $saved = postConfig($install, $token, ['action' => 'settings', 'max_age' => '15', 'extra_origins' => "HTTPS://Preview.example.net:443/\r\n\r\nhttp://localhost:4321"]);
    $after = $install['app']->handle(request('GET', 'dev', cookies: ['innesto_dev' => $token], query: ['screen' => 'config']));

    expect($before->body)->toContain('id="max_age" name="max_age" min="1" max="60" value="5"')
        ->and($before->body)->toContain('https://www.example.com, is always allowed')
        ->and($saved->status)->toBe(303)
        ->and($after->body)->toContain('id="max_age" name="max_age" min="1" max="60" value="15"')
        ->and($after->body)->toContain("<textarea id=\"extra_origins\" name=\"extra_origins\">https://preview.example.net\nhttp://localhost:4321</textarea>")
        ->and(loggedEvents($install))->toBe(['setup_code.issued', 'setup.completed', 'shared_config.changed', 'roots_settings.changed']);
});

test('invalid Roots settings are refused with 422', function (string $field, string $value): void {
    $install = install();
    $token = completeSetup($install);

    $response = postConfig($install, $token, [$field => $value] + ['action' => 'settings', 'max_age' => '5', 'extra_origins' => '']);

    expect($response->status)->toBe(422)
        ->and(preg_match_all('/<li><a href="#([a-z_]+)"/', $response->body, $match))->toBe(1)
        ->and($match[1])->toBe([$field])
        ->and(loggedEvents($install))->toBe(['setup_code.issued', 'setup.completed']);
})->with([
    'max-age 0' => ['max_age', '0'],
    'max-age 61' => ['max_age', '61'],
    'max-age not a number' => ['max_age', '5m'],
    'origin with a path' => ['extra_origins', 'https://example.net/page'],
    'not an origin' => ['extra_origins', 'example.net'],
    'port out of range' => ['extra_origins', 'https://example.net:65536'],
]);

test('config forms need the session\'s CSRF token', function (): void {
    $install = install();
    $token = completeSetup($install);

    $response = $install['app']->handle(request('POST', 'dev', ['csrf' => 'forged'] + SHARED_FORM, ['innesto_dev' => $token], ORIGIN));

    expect($response->status)->toBe(403)
        ->and(signedInPage($install, $token)->body)->toContain('name="action" value="shared"');
});

test('the config Screen flags an incomplete Business profile and a default Site URL', function (): void {
    $install = install();
    $token = completeSetup($install);
    $screen = fn () => $install['app']->handle(request('GET', 'dev', cookies: ['innesto_dev' => $token], query: ['screen' => 'config']))->body;

    $before = $screen();
    postConfig($install, $token, SHARED_FORM);
    $after = $screen();

    expect($before)->toContain('The Business profile has no name or address')
        ->and($before)->toContain('The Site URL is still the default')
        ->and($after)->not->toContain('The Business profile has no name or address')
        ->and($after)->not->toContain('The Site URL is still the default');
});
