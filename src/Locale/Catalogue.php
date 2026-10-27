<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots\Locale;

use Closure;
use InvalidArgumentException;
use OutOfBoundsException;

/**
 * One owner's translation catalogues: `<dir>/<locale>.php` files returning
 * plain arrays with symbolic keys. A key is looked up by region, then
 * language, then the owner's `en`, which must be complete; never in the
 * install's Default locale. The `plural` key holds the locale's CLDR rule.
 */
final class Catalogue
{
    /** @var array<string, array<string, mixed>> catalogues loaded so far, by locale */
    private array $loaded = [];

    public function __construct(private readonly string $dir)
    {
    }

    /** @param array<string, string|int> $params replace `{name}` placeholders */
    public function text(string $locale, string $key, array $params = []): string
    {
        [, $value] = $this->lookup($locale, $key);

        return self::fill(is_string($value) ? $value : throw new OutOfBoundsException("$key is not a string"), $params);
    }

    /**
     * Picks the plural form for $count, as decided by the rule of the
     * catalogue that holds the forms; `{count}` is filled in.
     *
     * @param array<string, string|int> $params
     */
    public function plural(string $locale, string $key, int $count, array $params = []): string
    {
        [$found, $forms] = $this->lookup($locale, $key);
        $form = is_array($forms) ? $forms[$this->pluralCategory($found, $count)] ?? null : null;

        return self::fill(is_string($form) ? $form : throw new OutOfBoundsException("$key has no form for $count in $found"), ['count' => $count] + $params);
    }

    /** The CLDR plural category of $count in $locale (e.g. `one`, `other`). */
    private function pluralCategory(string $locale, int $count): string
    {
        [, $rule] = $this->lookup($locale, 'plural');
        $category = $rule instanceof Closure ? $rule($count) : null;

        return is_string($category) ? $category : throw new OutOfBoundsException("The plural rule of $locale is not a function returning a category");
    }

    /**
     * A key's raw value (a string, a list of names, a rule...) for the formatter.
     */
    public function value(string $locale, string $key): mixed
    {
        return $this->lookup($locale, $key)[1];
    }

    /** @return array{string, mixed} the locale whose catalogue holds $key, and its value */
    private function lookup(string $locale, string $key): array
    {
        $canonical = LocaleTag::canonical($locale) ?? throw new InvalidArgumentException("Malformed locale tag: $locale");
        foreach (array_unique([...LocaleTag::withLanguage($canonical), 'en']) as $candidate) {
            $catalogue = $this->loaded[$candidate] ??= $this->load($candidate);
            if (array_key_exists($key, $catalogue)) {
                return [$candidate, $catalogue[$key]];
            }
        }
        throw new OutOfBoundsException("Missing catalogue key $key for $locale in $this->dir");
    }

    /** @return array<string, mixed> */
    private function load(string $locale): array
    {
        $file = "$this->dir/$locale.php";
        if (!is_file($file)) {
            return [];
        }
        $catalogue = require $file;
        if (!is_array($catalogue)) {
            throw new InvalidArgumentException("$file does not return an array");
        }

        /** @var array<string, mixed> $catalogue symbolic keys, by the catalogue format */
        return $catalogue;
    }

    /** @param array<string, string|int> $params */
    private static function fill(string $text, array $params): string
    {
        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }

        return strtr($text, $replacements);
    }
}
