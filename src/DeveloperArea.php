<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use Innesto\Roots\Auth\SetupCode;
use Innesto\Roots\Auth\Sessions;
use Innesto\Roots\Auth\Throttle;
use Innesto\Roots\Http\Request;
use Innesto\Roots\Http\Response;
use Innesto\Roots\Store\Store;

/**
 * `dev/`: first run, sign-in, sign-out, password change and the config
 * Screen, which a signed-in Developer sees until the Shared config is
 * confirmed. Every POST passes the Origin/Referer check here; actions of a
 * signed-in Developer also need the session's CSRF token. Successful posts
 * redirect (PRG).
 */
final class DeveloperArea
{
    private const COOKIE = 'innesto_dev';

    private const IDLE = 12 * 3600;

    private const MIN_PASSWORD = 12;

    private readonly SetupCode $setupCode;

    private readonly string $resetMarker;

    private readonly Sessions $sessions;

    private readonly Throttle $throttle;

    private readonly ConfigScreen $config;

    public function __construct(
        private readonly Store $content,
        Store $runtime,
        string $dataDir,
        private readonly RootsLog $log,
        Clock $clock,
        private readonly Template $template,
    ) {
        $this->setupCode = new SetupCode($dataDir);
        $this->resetMarker = "$dataDir/reset-marker";
        $this->sessions = new Sessions($runtime, $clock, 'dev', self::IDLE);
        $this->throttle = new Throttle($runtime, $clock, 'dev');
        $this->config = new ConfigScreen($content, $log, $template);
    }

    public function handle(Request $request): Response
    {
        $this->issueSetupCodeIfNeeded();
        $token = $request->cookies[self::COOKIE] ?? null;
        $session = $this->sessions->find($token);

        if ($request->method !== 'POST') {
            return match (true) {
                $session === null => $this->signedOutPage(200),
                ($request->query['screen'] ?? '') === 'config' || !$this->config->confirmed() => $this->config->page($request, $session['csrf']),
                default => $this->homePage($session['csrf'], 200),
            };
        }
        if (!self::sameOrigin($request)) {
            $this->log->write('warning', 'roots', 'csrf.refused', 'A Developer-area form came from another origin', ['ip' => $request->ip]);

            return $this->forbidden();
        }
        $action = $request->form['action'] ?? '';
        if ($action === 'setup' || $action === 'signin') {
            $throttled = $this->throttled($request, 'Sign-in refused: too many failures', $this->signedOutPage(...));
            if ($throttled !== null) {
                return $throttled;
            }

            return $action === 'setup' ? $this->setup($request) : $this->signIn($request);
        }
        if ($session === null || $token === null || !hash_equals($session['csrf'], $request->form['csrf'] ?? '')) {
            $this->log->write('warning', 'roots', 'csrf.refused', 'A Developer-area form had no valid CSRF token', ['ip' => $request->ip]);

            return $this->forbidden();
        }

        return match ($action) {
            'signout' => $this->signOut($request, $token),
            'password' => $this->changePassword($request, $session['csrf']),
            'shared' => $this->config->saveShared($request, $session['csrf']),
            'settings' => $this->config->saveSettings($request, $session['csrf']),
            default => Response::redirect($request->basePath . 'dev/'),
        };
    }

    /**
     * On the first run, or when the Developer uploaded a reset marker: the
     * old password stays valid until the new Setup code is used.
     */
    private function issueSetupCodeIfNeeded(): void
    {
        $reset = is_file($this->resetMarker) && unlink($this->resetMarker);
        if ($reset || ($this->passwordHash() === null && !$this->setupCode->pending())) {
            $this->setupCode->issue();
            $this->log->write('info', 'roots', 'setup_code.issued', 'Setup code written to the Data directory', ['reset' => $reset]);
        }
    }

    private function setup(Request $request): Response
    {
        if (!$this->setupCode->pending()) {
            return Response::redirect($request->basePath . 'dev/');
        }
        if (!$this->setupCode->matches($request->form['code'] ?? '')) {
            $this->throttle->fail($request->ip);
            $this->log->write('warning', 'roots', 'setup.failed', 'Wrong Setup code', ['ip' => $request->ip]);

            return $this->signedOutPage(422, 'dev.error.code');
        }
        $password = $request->form['password'] ?? '';
        if (!self::acceptablePassword($password)) {
            return $this->signedOutPage(422, 'dev.error.password_short');
        }
        $this->setPassword($password);
        $this->setupCode->consume();
        $this->log->write('info', 'roots', 'setup.completed', 'Setup code used; Developer password set', ['ip' => $request->ip]);

        return $this->signedIn($request);
    }

    private function signIn(Request $request): Response
    {
        $hash = $this->passwordHash();
        if ($hash === null || !password_verify($request->form['password'] ?? '', $hash)) {
            $this->throttle->fail($request->ip);
            $this->log->write('warning', 'roots', 'signin.failed', 'Wrong Developer password', ['ip' => $request->ip]);

            return $this->signedOutPage(422, 'dev.error.password');
        }

        return $this->signedIn($request);
    }

    private function signOut(Request $request, string $token): Response
    {
        $this->sessions->end($token);

        return Response::redirect($request->basePath . 'dev/')->withHeader('Set-Cookie', $this->cookie($request, ''));
    }

    private function changePassword(Request $request, string $csrf): Response
    {
        $throttled = $this->throttled($request, 'Password change refused: too many failures', fn (int $status, string $error, array $errorParams): Response => $this->homePage($csrf, $status, $error, $errorParams));
        if ($throttled !== null) {
            return $throttled;
        }
        $hash = $this->passwordHash();
        if ($hash === null || !password_verify($request->form['current'] ?? '', $hash)) {
            $this->throttle->fail($request->ip);
            $this->log->write('warning', 'roots', 'signin.failed', 'Wrong current Developer password on a password change', ['ip' => $request->ip]);

            return $this->homePage($csrf, 422, 'dev.error.password');
        }
        $this->throttle->succeed($request->ip);
        $password = $request->form['password'] ?? '';
        if (!self::acceptablePassword($password)) {
            return $this->homePage($csrf, 422, 'dev.error.password_short');
        }
        $this->setPassword($password);
        $this->log->write('info', 'roots', 'password.changed', 'Developer password changed; every Developer session signed out', ['ip' => $request->ip]);

        return Response::redirect($request->basePath . 'dev/')->withHeader('Set-Cookie', $this->cookie($request, ''));
    }

    private function signedIn(Request $request): Response
    {
        $this->throttle->succeed($request->ip);

        return Response::redirect($request->basePath . 'dev/')->withHeader('Set-Cookie', $this->cookie($request, $this->sessions->start()));
    }

    /**
     * The 429 page with Retry-After while $request's IP must wait, else null.
     *
     * @param callable(int, string, array<string, string|int>): Response $page
     */
    private function throttled(Request $request, string $message, callable $page): ?Response
    {
        $wait = $this->throttle->retryAfter($request->ip);
        if ($wait === null) {
            return null;
        }
        $this->log->write('warning', 'roots', 'signin.throttled', $message, ['ip' => $request->ip, 'retry_after' => $wait]);

        return $page(429, 'dev.error.throttled', ['seconds' => $wait])->withHeader('Retry-After', (string) $wait);
    }

    /** The new Developer password; every Developer session ends. */
    private function setPassword(string $password): void
    {
        $this->content->put('credentials', ['id' => 'developer', 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
        $this->sessions->endAll();
    }

    private function forbidden(): Response
    {
        return Pages::html($this->template, 403, 'message', ['title' => 'dev.title', 'text' => 'dev.error.forbidden']);
    }

    /** @param array<string, string|int> $errorParams */
    private function signedOutPage(int $status, ?string $error = null, array $errorParams = []): Response
    {
        $vars = ['error' => $error, 'errorParams' => $errorParams, 'canSignIn' => $this->passwordHash() !== null];

        return Pages::html($this->template, $status, $this->setupCode->pending() ? 'setup' : 'signin', $vars);
    }

    /** @param array<string, string|int> $errorParams */
    private function homePage(string $csrf, int $status, ?string $error = null, array $errorParams = []): Response
    {
        return Pages::html($this->template, $status, 'home', ['csrf' => $csrf, 'error' => $error, 'errorParams' => $errorParams]);
    }

    private function passwordHash(): ?string
    {
        $hash = $this->content->get('credentials', 'developer')['hash'] ?? null;

        return is_string($hash) ? $hash : null;
    }

    /**
     * The session cookie, or with an empty $token the header that clears it.
     * Only the localhost override reaches here without HTTPS; browsers refuse
     * a Secure cookie there, so it goes without.
     */
    private function cookie(Request $request, string $token): string
    {
        return self::COOKIE . "=$token; Path={$request->basePath}dev/" . ($token === '' ? '; Max-Age=0' : '')
            . ($request->https ? '; Secure' : '') . '; HttpOnly; SameSite=Strict';
    }

    /** At least 12 characters of valid UTF-8, without the NUL byte bcrypt refuses. */
    private static function acceptablePassword(string $password): bool
    {
        $characters = preg_match_all('/./su', $password);

        return is_int($characters) && $characters >= self::MIN_PASSWORD && !str_contains($password, "\0");
    }

    /** The Origin header, else the Referer, must be Roots' own origin. */
    private static function sameOrigin(Request $request): bool
    {
        $origin = $request->header('origin');
        if ($origin !== null) {
            return $origin === $request->origin();
        }
        $referer = $request->header('referer');

        return $referer !== null && str_starts_with($referer, $request->origin() . '/');
    }
}
