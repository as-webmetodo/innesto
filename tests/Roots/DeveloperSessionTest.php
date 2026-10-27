<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

test('a form post from another origin is refused', function (?string $origin, ?string $referer): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));
    $headers = array_filter(['origin' => $origin, 'referer' => $referer], is_string(...));

    $response = $install['app']->handle(request('POST', 'dev', ['action' => 'setup', 'code' => setupCode($install), 'password' => PASSWORD], headers: $headers));

    expect($response->status)->toBe(403)
        ->and(is_file(dataPath($install) . '/setup-code.php'))->toBeTrue();
})->with([
    'foreign Origin' => ['https://evil.example', null],
    'foreign Referer' => [null, 'https://evil.example/innesto/dev/'],
    'opaque Origin' => ['null', null],
    'neither header' => [null, null],
]);

test('a same-origin Referer is accepted without an Origin header', function (): void {
    $install = install();
    $install['app']->handle(request('GET', 'dev'));

    $response = $install['app']->handle(request('POST', 'dev', ['action' => 'setup', 'code' => setupCode($install), 'password' => PASSWORD], headers: ['referer' => 'https://example.com/innesto/dev/']));

    expect($response->status)->toBe(303);
});

test('signing out needs the session\'s CSRF token', function (): void {
    $install = install();
    $token = completeSetup($install);

    $forged = $install['app']->handle(request('POST', 'dev', ['action' => 'signout', 'csrf' => 'forged'], ['innesto_dev' => $token], ORIGIN));

    expect($forged->status)->toBe(403)
        ->and(signedInPage($install, $token)->body)->toContain('value="signout"');
});

test('signing out ends the session and clears the cookie', function (): void {
    $install = install();
    $token = completeSetup($install);

    $response = $install['app']->handle(request('POST', 'dev', ['action' => 'signout', 'csrf' => csrfToken($install, $token)], ['innesto_dev' => $token], ORIGIN));

    expect($response->status)->toBe(303)
        ->and($response->header('Set-Cookie'))->toBe('innesto_dev=; Path=/innesto/dev/; Max-Age=0; Secure; HttpOnly; SameSite=Strict')
        ->and(signedInPage($install, $token)->body)->toContain('value="signin"');
});

test('the Developer signs back in with the password', function (): void {
    $install = install();
    completeSetup($install);

    $wrong = $install['app']->handle(request('POST', 'dev', ['action' => 'signin', 'password' => 'not the password'], headers: ORIGIN));
    $right = $install['app']->handle(request('POST', 'dev', ['action' => 'signin', 'password' => PASSWORD], headers: ORIGIN));

    expect($wrong->status)->toBe(422)
        ->and($wrong->body)->toContain('The password is not right.')
        ->and($right->status)->toBe(303)
        ->and(signedInPage($install, (string) sessionCookie($right))->body)->toContain('value="signout"')
        ->and(loggedEvents($install))->toBe(['setup_code.issued', 'setup.completed', 'signin.failed']);
});

test('a session expires after 12 hours idle', function (): void {
    $install = install();
    $token = completeSetup($install);

    $install['clock']->advance(12 * 3600);

    expect(signedInPage($install, $token)->body)->toContain('value="signin"');
});

test('each use renews the 12 hours, written at most every 5 minutes', function (): void {
    $install = install();
    $token = completeSetup($install);

    // Within 5 minutes of the last renewal, a use does not renew.
    $install['clock']->advance(4 * 60);
    signedInPage($install, $token);
    $install['clock']->advance(12 * 3600 - 4 * 60);
    expect(signedInPage($install, $token)->body)->toContain('value="signin"');

    $token = signInAgain($install);
    $install['clock']->advance(6 * 60);
    signedInPage($install, $token);
    $install['clock']->advance(12 * 3600 - 60);
    expect(signedInPage($install, $token)->body)->toContain('value="signout"');
});

test('changing the password signs out every Developer session', function (): void {
    $install = install();
    $first = completeSetup($install);
    $second = (string) sessionCookie($install['app']->handle(request('POST', 'dev', ['action' => 'signin', 'password' => PASSWORD], headers: ORIGIN)));

    $response = $install['app']->handle(request('POST', 'dev', [
        'action' => 'password', 'csrf' => csrfToken($install, $first), 'current' => PASSWORD, 'password' => 'a brand new passphrase',
    ], ['innesto_dev' => $first], ORIGIN));

    expect($response->status)->toBe(303)
        ->and(signedInPage($install, $first)->body)->toContain('value="signin"')
        ->and(signedInPage($install, $second)->body)->toContain('value="signin"')
        ->and($install['app']->handle(request('POST', 'dev', ['action' => 'signin', 'password' => PASSWORD], headers: ORIGIN))->status)->toBe(422)
        ->and($install['app']->handle(request('POST', 'dev', ['action' => 'signin', 'password' => 'a brand new passphrase'], headers: ORIGIN))->status)->toBe(303)
        ->and(loggedEvents($install))->toContain('password.changed');
});

test('changing the password needs the current one', function (): void {
    $install = install();
    $token = completeSetup($install);

    $response = $install['app']->handle(request('POST', 'dev', [
        'action' => 'password', 'csrf' => csrfToken($install, $token), 'current' => 'not the password', 'password' => 'a brand new passphrase',
    ], ['innesto_dev' => $token], ORIGIN));

    expect($response->status)->toBe(422)
        ->and(signedInPage($install, $token)->body)->toContain('value="signout"');
});

test('a reset marker issues a fresh Setup code while the old password keeps working', function (): void {
    $install = install();
    completeSetup($install);
    touch(dataPath($install) . '/reset-marker');

    $page = $install['app']->handle(request('GET', 'dev'));

    expect($page->body)->toContain('name="code"')
        ->and($page->body)->toContain('value="signin"')
        ->and(is_file(dataPath($install) . '/reset-marker'))->toBeFalse()
        ->and($install['app']->handle(request('POST', 'dev', ['action' => 'signin', 'password' => PASSWORD], headers: ORIGIN))->status)->toBe(303);

    $install['app']->handle(request('POST', 'dev', ['action' => 'setup', 'code' => setupCode($install), 'password' => 'the recovered passphrase'], headers: ORIGIN));

    expect($install['app']->handle(request('POST', 'dev', ['action' => 'signin', 'password' => PASSWORD], headers: ORIGIN))->status)->toBe(422)
        ->and(loggedEvents($install))->toBe(['setup_code.issued', 'setup.completed', 'setup_code.issued', 'setup.completed', 'signin.failed']);
});

/**
 * Signs in again after an expiry, returning the new token.
 *
 * @param array{app: Innesto\Roots\App} $install
 */
function signInAgain(array $install): string
{
    return (string) sessionCookie($install['app']->handle(request('POST', 'dev', ['action' => 'signin', 'password' => PASSWORD], headers: ORIGIN)));
}
