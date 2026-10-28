<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use Innesto\Roots\Store\Store;
use UnexpectedValueException;

/**
 * The Roots settings as Roots alone reads them, from its content Store,
 * with the defaults until the Developer changes them. Never passed to
 * Modules.
 *
 * @phpstan-type SettingsDocument array{max_age: int, extra_origins: list<string>}
 */
final class RootsSettings
{
    /** Its document in the content Store's `config` collection. */
    public const ID = 'roots_settings';

    /** The Visitor `max-age` N, in minutes, until the Developer changes it. */
    private const MAX_AGE = 5;

    private const MAX_ORIGINS = 20;

    private const ORIGIN_PATTERN = '~^https?://([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]*[a-z0-9])?(:([1-9][0-9]{0,3}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5]))?$~';

    /**
     * @param int $maxAge the Visitor `max-age` N, in minutes
     * @param list<string> $extraOrigins
     */
    private function __construct(public readonly int $maxAge, public readonly array $extraOrigins)
    {
    }

    public static function fromStore(Store $content): self
    {
        $settings = self::document($content);

        return new self($settings['max_age'], $settings['extra_origins']);
    }

    /**
     * The stored settings, or the defaults. A stored document Roots could not
     * have written fails fast.
     *
     * @return SettingsDocument
     */
    private static function document(Store $content): array
    {
        $stored = $content->get('config', self::ID);
        if ($stored === null) {
            return ['max_age' => self::MAX_AGE, 'extra_origins' => []];
        }
        $document = array_diff_key($stored, ['id' => true]);
        $origins = $document['extra_origins'] ?? null;
        $shaped = count($document) === 2 && is_int($document['max_age'] ?? null)
            && is_array($origins) && array_is_list($origins) && array_filter($origins, is_string(...)) === $origins;
        /** @var SettingsDocument $document as the checks above prove */
        if (!$shaped || self::errors($document) !== []) {
            throw new UnexpectedValueException('The stored Roots settings are not ones Roots writes');
        }

        return $document;
    }

    /**
     * The settings a form asks for, normalised, and the fields at fault.
     *
     * @param array<string, string> $form
     * @return array{SettingsDocument, list<string>}
     */
    public static function fromForm(array $form): array
    {
        $maxAge = trim($form['max_age'] ?? '');
        $origins = [];
        foreach (preg_split('/\R/', strtolower($form['extra_origins'] ?? '')) ?: [] as $line) {
            if (trim($line) !== '') {
                // As browsers send it in the Origin header: without a default port.
                $origins[] = preg_replace(['~^(https://[^/]+):443$~', '~^(http://[^/]+):80$~'], '$1', rtrim(trim($line), '/')) ?? '';
            }
        }
        $document = ['max_age' => preg_match('/^[0-9]{1,2}$/', $maxAge) === 1 ? (int) $maxAge : 0, 'extra_origins' => array_values(array_unique($origins))];

        return [$document, self::errors($document)];
    }

    /**
     * The origins whose pages may fetch Visitor output: the Site URL's
     * origin, always, then the extra ones.
     *
     * @return list<string>
     */
    public function allowedOrigins(SharedConfig $shared): array
    {
        return array_values(array_unique([$shared->siteOrigin(), ...$this->extraOrigins]));
    }

    /**
     * @param SettingsDocument $document
     * @return list<string> the fields at fault
     */
    private static function errors(array $document): array
    {
        $errors = [];
        if ($document['max_age'] < 1 || $document['max_age'] > 60) {
            $errors[] = 'max_age';
        }
        if (count($document['extra_origins']) > self::MAX_ORIGINS || preg_grep(self::ORIGIN_PATTERN, $document['extra_origins'], PREG_GREP_INVERT) !== []) {
            $errors[] = 'extra_origins';
        }

        return $errors;
    }
}
