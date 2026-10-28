<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

/**
 * A request for a Module's Screen, after Roots' authentication gate and,
 * for a POST, its Origin and CSRF checks.
 */
final class ScreenRequest
{
    /**
     * @param 'GET'|'POST' $method
     * @param array<string, string> $query the URL's parameters, but for Roots' `module` and `screen`
     * @param array<string, string> $form the posted fields, but for Roots' `csrf`
     */
    public function __construct(public readonly string $method, public readonly array $query, public readonly array $form)
    {
    }
}
