<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use DateTimeZone;
use Innesto\Roots\Http\Request;
use Innesto\Roots\Http\Response;
use Innesto\Roots\Store\Store;
use InvalidArgumentException;

/**
 * `dev/?screen=health` for one signed-in request: the latest Self-check,
 * errors per Module, sign-in failures and throttling, and the Roots log with
 * a download of both generations. Opening it marks every error seen; the
 * home badge asks attention() for a Warn, a Fail or an unseen error.
 *
 * @phpstan-import-type Finding from SelfCheck
 */
final class HealthScreen
{
    private const TAIL = 50;

    private const SIGNIN_EVENTS = ['signin.failed', 'setup.failed', 'signin.throttled'];

    public function __construct(
        private readonly SelfCheck $selfCheck,
        private readonly Store $runtime,
        private readonly FaultFence $fence,
        private readonly RootsLog $log,
        private readonly Clock $clock,
        private readonly Template $template,
        private readonly Request $request,
        private readonly string $csrf,
    ) {
    }

    public function page(): Response
    {
        $response = $this->render(200, null);
        $this->runtime->put('health', ['id' => 'seen', 'time' => $this->now()]);

        return $response;
    }

    /** Both generations of the Roots log, oldest first, as one JSON-lines file. */
    public function download(): Response
    {
        return new Response(200, [
            ['Content-Type', 'text/plain; charset=utf-8'],
            ['Content-Disposition', 'attachment; filename="roots-log.jsonl"'],
            ['Cache-Control', 'no-store'],
        ], $this->log->export());
    }

    /** Stores the browser's probe results from the Health Screen's own run. */
    public function run(): Response
    {
        $probes = SelfCheck::parse($this->request->form['probes'] ?? '');
        if ($probes === null) {
            return $this->render(422, 'dev.error.probes');
        }
        $this->selfCheck->runBrowser($probes);

        return Response::redirect($this->request->basePath . 'dev/?screen=health');
    }

    /** Sends a test mail to the typed address, only when asked. */
    public function testMail(): Response
    {
        if (!Mail::available()) {
            return $this->render(422, 'dev.health.mail.unavailable');
        }
        try {
            $sent = (new Mail())->send(trim($this->request->form['to'] ?? ''), 'Innesto test mail', "This test mail was sent from the Innesto Developer area.\n");
        } catch (InvalidArgumentException) {
            return $this->render(422, 'dev.health.mail.address');
        }
        if (!$sent) {
            return $this->render(422, 'dev.health.mail.refused');
        }

        return Response::redirect($this->request->basePath . 'dev/?screen=health&mail=sent');
    }

    /** Whether the home needs a badge: a Warn or a Fail, or an error newer than the last visit here. */
    public function attention(): bool
    {
        if (array_diff(array_column($this->findings(), 'grade'), ['ok']) !== []) {
            return true;
        }
        $seen = $this->runtime->get('health', 'seen')['time'] ?? '';
        $seen = is_string($seen) ? $seen : '';
        foreach ($this->fence->faults() as $fault) {
            if ($fault['time'] > $seen) {
                return true;
            }
        }
        foreach ($this->log->entries() as $entry) {
            if (($entry['level'] ?? null) === 'error' && is_string($entry['time'] ?? null) && $entry['time'] > $seen) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Finding> the latest server and browser findings */
    private function findings(): array
    {
        $latest = $this->selfCheck->latest();

        return [...$latest['server']['findings'] ?? [], ...$latest['browser']['findings'] ?? []];
    }

    private function render(int $status, ?string $error): Response
    {
        $entries = $this->log->entries();
        $signins = array_values(array_filter($entries, static fn (array $entry): bool => in_array($entry['event'] ?? null, self::SIGNIN_EVENTS, true)));

        return Pages::html($this->template, $status, 'health', [
            'csrf' => $this->csrf,
            'error' => $error,
            'mailSent' => ($this->request->query['mail'] ?? '') === 'sent',
            'latest' => $this->selfCheck->latest(),
            'sqlite' => $this->selfCheck->sqlite(),
            'mail' => Mail::available(),
            'faults' => $this->fence->faults(),
            'signins' => array_slice($signins, -self::TAIL),
            'signinFailures' => count(array_filter($signins, static fn (array $entry): bool => $entry['event'] !== 'signin.throttled')),
            'tail' => array_reverse(array_slice($entries, -self::TAIL)),
            'probes' => $this->selfCheck->probes(),
        ]);
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
