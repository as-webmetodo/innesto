<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use Innesto\Roots\Locale\Catalogue;
use Throwable;

/**
 * Renders Roots' plain-PHP templates. Each template receives its variables
 * plus `$e` (HTML escaping) and `$t` (catalogue text in the page's locale).
 */
final class Template
{
    public function __construct(private readonly string $dir, private readonly Catalogue $catalogue, public readonly string $locale)
    {
    }

    /** @param array<string, mixed> $vars */
    public function render(string $name, array $vars = []): string
    {
        $vars += [
            'e' => self::escape(...),
            't' => $this->text(...),
            'locale' => $this->locale,
        ];

        return (static function (string $__file, array $__vars): string {
            extract($__vars);
            ob_start();
            try {
                require $__file;
            } catch (Throwable $error) {
                ob_end_clean();

                throw $error;
            }

            return (string) ob_get_clean();
        })("$this->dir/$name.php", $vars);
    }

    /** HTML escaping, for text and attribute values alike. */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Catalogue text in the page's locale.
     *
     * @param array<string, string|int> $params
     */
    public function text(string $key, array $params = []): string
    {
        return $this->catalogue->text($this->locale, $key, $params);
    }
}
