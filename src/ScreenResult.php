<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

/** What a Screen answers: a page Roots puts inside its shell, or a redirect to one of the Module's Screens. */
final class ScreenResult
{
    /** @param array<string, string> $params */
    private function __construct(public readonly int $status, public readonly string $html, public readonly ?string $screen, public readonly array $params)
    {
    }

    /** $html is the Screen's content, already escaped by its template. */
    public static function page(string $html, int $status = 200): self
    {
        return new self($status, $html, null, []);
    }

    /**
     * After a successful post: a redirect to Screen $screen of the same Module.
     *
     * @param array<string, string> $params
     */
    public static function redirect(string $screen, array $params = []): self
    {
        return new self(303, '', $screen, $params);
    }
}
