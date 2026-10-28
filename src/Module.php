<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

/**
 * What a Module's entry class implements. Roots creates it with no
 * arguments and passes the Context to every call; each call runs inside the
 * fault fence.
 */
interface Module
{
    /**
     * Runs once, when the Developer first activates the Module, inside a
     * transaction of its new Store: a throw rolls everything back.
     */
    public function onActivate(Context $context): void;

    /** Screen $name, declared in the Manifest, for $request. */
    public function screen(string $name, Context $context, ScreenRequest $request): ScreenResult;
}
