<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Acme\Failing;

use Innesto\Roots\Context;
use Innesto\Roots\Module as RootsModule;
use Innesto\Roots\ScreenRequest;
use Innesto\Roots\ScreenResult;
use LogicException;
use RuntimeException;

final class Module implements RootsModule
{
    public function onActivate(Context $context): void
    {
        $context->store->put('notes', ['id' => 'half-done']);

        throw new RuntimeException('Seeding failed');
    }

    public function screen(string $name, Context $context, ScreenRequest $request): ScreenResult
    {
        throw new LogicException('No Screens');
    }
}
