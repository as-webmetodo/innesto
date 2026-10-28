<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Acme\Probe;

use Innesto\Roots\Context;
use Innesto\Roots\Module as RootsModule;
use Innesto\Roots\ScreenRequest;
use Innesto\Roots\ScreenResult;
use RuntimeException;

final class Module implements RootsModule
{
    public function onActivate(Context $context): void
    {
        $context->saveSettings(['greeting' => 'hello']);
    }

    public function screen(string $name, Context $context, ScreenRequest $request): ScreenResult
    {
        if ($name === 'boom') {
            throw new RuntimeException('The probe exploded');
        }
        if ($name === 'stray') {
            return ScreenResult::redirect('phone');
        }
        if ($request->method === 'POST') {
            $context->saveSettings(['greeting' => $request->form['greeting'] ?? '']);
            $context->log('info', 'probe.saved', 'Greeting saved');

            return ScreenResult::redirect('echo', ['saved' => '1']);
        }

        return ScreenResult::page($context->render('echo', [
            'settings' => $context->settings(),
            'actor' => $context->actor === null ? 'none' : $context->actor->value,
            'business' => $context->shared->businessName,
            'mail' => $context->mail !== null,
            'query' => $request->query,
            'today' => $context->formatter()->dayMonth($context->clock->now()),
        ]));
    }
}
