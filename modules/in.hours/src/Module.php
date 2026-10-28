<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Hours;

use Innesto\Roots\Context;
use Innesto\Roots\Module as RootsModule;
use Innesto\Roots\ScreenRequest;
use Innesto\Roots\ScreenResult;
use LogicException;

/** Innesto Orario's entry class. */
final class Module implements RootsModule
{
    /** The Schedule key of the Schedule seeded at activation. */
    public const MAIN = 'main';

    /** Seeds the "Main" Schedule: closed every day, in the Install timezone, named in the Default locale. */
    public function onActivate(Context $context): void
    {
        $default = $context->shared->defaultLocale;
        $context->store->put('schedules', [
            'id' => self::MAIN,
            'name' => [$default => $context->text('schedule.main', [], $default)],
            'timezone' => $context->shared->installTimezone,
            'position' => 0,
            'weekly' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], []),
            'seasons' => [],
            'location' => null,
        ]);
        $context->log('info', 'schedule.created', 'Schedule "main" seeded at activation', ['schedule' => self::MAIN]);
    }

    public function screen(string $name, Context $context, ScreenRequest $request): ScreenResult
    {
        throw new LogicException("Innesto Orario's Screens are not built yet (#33): $name");
    }
}
