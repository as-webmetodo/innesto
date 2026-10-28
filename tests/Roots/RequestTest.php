<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\Http\Request;

/**
 * The Request a stub entry point builds from these superglobals.
 *
 * @param array<string, mixed> $server
 * @param array<string, mixed> $post
 * @param array<string, mixed> $cookies
 */
function fromGlobals(string $entry, array $server, array $post = [], array $cookies = []): Request
{
    [$savedServer, $savedPost, $savedCookies] = [$_SERVER, $_POST, $_COOKIE];
    [$_SERVER, $_POST, $_COOKIE] = [$server, $post, $cookies];
    try {
        return Request::fromGlobals($entry);
    } finally {
        [$_SERVER, $_POST, $_COOKIE] = [$savedServer, $savedPost, $savedCookies];
    }
}

test('a stub entry point reads the request from the superglobals', function (): void {
    $request = fromGlobals('dev', [
        'REQUEST_METHOD' => 'post',
        'SCRIPT_NAME' => '/innesto/dev/index.php',
        'HTTP_HOST' => 'example.com',
        'HTTPS' => 'on',
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_ORIGIN' => 'https://example.com',
        'HTTP_ACCEPT_LANGUAGE' => 'it',
    ], ['action' => 'signin', 'list' => ['not', 'a', 'string']], ['innesto_dev' => 'token']);

    expect($request->method)->toBe('POST')
        ->and($request->entry)->toBe('dev')
        ->and($request->basePath)->toBe('/innesto/')
        ->and($request->host)->toBe('example.com')
        ->and($request->https)->toBeTrue()
        ->and($request->ip)->toBe('203.0.113.7')
        ->and($request->form)->toBe(['action' => 'signin'])
        ->and($request->cookies)->toBe(['innesto_dev' => 'token'])
        ->and($request->header('Origin'))->toBe('https://example.com')
        ->and($request->header('accept-language'))->toBe('it')
        ->and($request->header('referer'))->toBeNull();
});

test('Roots installed at the webroot has the base path /', function (): void {
    expect(fromGlobals('index', ['SCRIPT_NAME' => '/index.php'])->basePath)->toBe('/')
        ->and(fromGlobals('dev', ['SCRIPT_NAME' => '/dev/index.php'])->basePath)->toBe('/');
});

test('HTTPS off or absent is plain HTTP', function (?string $https): void {
    expect(fromGlobals('dev', $https === null ? [] : ['HTTPS' => $https])->https)->toBeFalse();
})->with([
    'absent' => [null],
    'off' => ['off'],
    'empty' => [''],
]);

test('HTTPS is also read from the request scheme and port 443', function (string $key, string|int $value): void {
    expect(fromGlobals('dev', [$key => $value])->https)->toBeTrue();
})->with([
    'REQUEST_SCHEME' => ['REQUEST_SCHEME', 'https'],
    'SERVER_PORT as a string' => ['SERVER_PORT', '443'],
    'SERVER_PORT as an int' => ['SERVER_PORT', 443],
]);

test('forwarded headers any client can send never make a request HTTPS', function (string $key, string $value): void {
    expect(fromGlobals('dev', [$key => $value, 'REQUEST_SCHEME' => 'http', 'SERVER_PORT' => '80'])->https)->toBeFalse();
})->with([
    'X-Forwarded-Proto' => ['HTTP_X_FORWARDED_PROTO', 'https'],
    'X-Forwarded-SSL' => ['HTTP_X_FORWARDED_SSL', 'on'],
]);
