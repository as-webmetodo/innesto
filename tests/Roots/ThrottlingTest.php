<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\Http\Response;

/** @param array{app: Innesto\Roots\App} $install */
function wrongPassword(array $install, string $ip = '203.0.113.7'): Response
{
    return $install['app']->handle(request('POST', 'dev', ['action' => 'signin', 'password' => 'not the password'], headers: ORIGIN, ip: $ip));
}

/** @param array{app: Innesto\Roots\App} $install */
function wrongSetupCode(array $install, string $ip = '203.0.113.7'): Response
{
    return $install['app']->handle(request('POST', 'dev', ['action' => 'setup', 'code' => 'AAAA-AAAA-AAAA', 'password' => PASSWORD], headers: ORIGIN, ip: $ip));
}

test('after 5 free failures an IP waits 1 s, doubling per failure', function (): void {
    $install = install();
    completeSetup($install);

    foreach (range(1, 5) as $_) {
        expect(wrongPassword($install)->status)->toBe(422);
    }
    $refused = wrongPassword($install);
    expect($refused->status)->toBe(429)
        ->and($refused->header('Retry-After'))->toBe('1')
        ->and($refused->header('Cache-Control'))->toBe('no-store');

    $install['clock']->advance(1);
    expect(wrongPassword($install)->status)->toBe(422)
        ->and(wrongPassword($install)->header('Retry-After'))->toBe('2')
        ->and(loggedEvents($install))->toContain('signin.throttled');
});

test('the wait stops growing at 15 minutes and never locks the account', function (): void {
    $install = install();
    completeSetup($install);

    foreach (range(1, 20) as $_) {
        $response = wrongPassword($install);
        $install['clock']->advance((int) ($response->header('Retry-After') ?? 0));
        wrongPassword($install);
    }

    expect(wrongPassword($install)->header('Retry-After'))->toBe('900');
    $install['clock']->advance(900);
    expect($install['app']->handle(request('POST', 'dev', ['action' => 'signin', 'password' => PASSWORD], headers: ORIGIN))->status)->toBe(303);
});

test('a refused attempt is not even checked', function (): void {
    $install = install();
    completeSetup($install);
    foreach (range(1, 5) as $_) {
        wrongPassword($install);
    }

    $response = $install['app']->handle(request('POST', 'dev', ['action' => 'signin', 'password' => PASSWORD], headers: ORIGIN));

    expect($response->status)->toBe(429)
        ->and(sessionCookie($response))->toBeNull();
});

test('a successful sign-in clears the IP\'s failures', function (): void {
    $install = install();
    completeSetup($install);
    foreach (range(1, 4) as $_) {
        wrongPassword($install);
    }
    $install['app']->handle(request('POST', 'dev', ['action' => 'signin', 'password' => PASSWORD], headers: ORIGIN));

    foreach (range(1, 5) as $_) {
        expect(wrongPassword($install)->status)->toBe(422);
    }
});

test('other IPs are not slowed by one IP\'s failures', function (): void {
    $install = install();
    completeSetup($install);
    foreach (range(1, 6) as $_) {
        wrongPassword($install);
    }

    expect(wrongPassword($install, '198.51.100.1')->status)->toBe(422);
});

test('Setup code entry is throttled like sign-in', function (): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));

    foreach (range(1, 5) as $_) {
        expect(wrongSetupCode($install)->status)->toBe(422);
    }

    expect(wrongSetupCode($install)->status)->toBe(429);
});

test('past 100 failures an hour only IPs that failed in that hour are refused', function (): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));
    foreach (range(1, 100) as $i) {
        expect(wrongSetupCode($install, "192.0.2.$i")->status)->toBe(422);
    }

    $failedBefore = wrongSetupCode($install, '192.0.2.1');
    expect($failedBefore->status)->toBe(429)
        ->and($failedBefore->header('Retry-After'))->toBe('3600');

    $clean = $install['app']->handle(request('POST', 'dev', setupForm($install, password: PASSWORD), headers: ORIGIN, ip: '198.51.100.9'));
    expect($clean->status)->toBe(303);
});

test('the global cap lifts as its failures age past the hour', function (): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));
    foreach (range(1, 100) as $i) {
        wrongSetupCode($install, "192.0.2.$i");
    }

    $install['clock']->advance(3600);

    expect(wrongSetupCode($install, '192.0.2.1')->status)->toBe(422);
});

test('wrong current passwords on a password change are throttled like sign-in', function (): void {
    $install = install();
    $token = completeSetup($install);
    $csrf = csrfToken($install, $token);
    $change = fn (): Response => $install['app']->handle(request('POST', 'dev', [
        'action' => 'password', 'csrf' => $csrf, 'current' => 'not the password', 'password' => 'a brand new passphrase',
    ], ['innesto_dev' => $token], ORIGIN));

    foreach (range(1, 5) as $_) {
        expect($change()->status)->toBe(422);
    }

    expect($change()->status)->toBe(429);
});
