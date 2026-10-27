<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use Innesto\Roots\Http\Response;

/** Developer-area HTML: inside Roots' layout, never cached, never framed. */
final class Pages
{
    /** @param array<string, mixed> $vars */
    public static function html(Template $template, int $status, string $page, array $vars = []): Response
    {
        $body = $template->render('dev/layout', ['content' => $template->render("dev/$page", $vars)]);

        return new Response($status, [
            ['Content-Type', 'text/html; charset=utf-8'],
            ['Cache-Control', 'no-store'],
            ['Content-Security-Policy', "frame-ancestors 'none'"],
        ], $body);
    }
}
