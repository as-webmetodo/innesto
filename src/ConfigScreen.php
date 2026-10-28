<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use DateTimeZone;
use Innesto\Roots\Http\Request;
use Innesto\Roots\Http\Response;
use Innesto\Roots\Locale\LocaleResolver;
use Innesto\Roots\Store\Store;

/**
 * `dev/?screen=config`: the Shared config and the Roots settings, one form
 * each. A valid save writes the content Store (bumping its revision when
 * something changed), logs the changed fields and redirects; an invalid one
 * re-renders with `422` and the fields at fault.
 *
 * @phpstan-import-type SharedDocument from SharedConfig
 * @phpstan-type SettingsDocument array{max_age: int, extra_origins: list<string>}
 */
final class ConfigScreen
{
    /** The Visitor `max-age` N, in minutes, until the Developer changes it. */
    public const MAX_AGE = 5;

    private const MAX_TEXT = 200;

    private const MAX_ORIGINS = 20;

    private const ORIGIN = '~^https?://([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]*[a-z0-9])?(:([1-9][0-9]{0,3}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5]))?$~';

    public function __construct(private readonly Store $content, private readonly RootsLog $log, private readonly Template $template)
    {
    }

    public function confirmed(): bool
    {
        return $this->content->get('config', 'shared') !== null;
    }

    public function page(Request $request, string $csrf): Response
    {
        return $this->render($request, $csrf, 200, [], SharedConfig::document($this->content, $request->origin()), $this->settings());
    }

    public function saveShared(Request $request, string $csrf): Response
    {
        [$shared, $errors] = self::sharedFromForm($request->form);
        if ($errors !== []) {
            return $this->render($request, $csrf, 422, $errors, $shared, $this->settings());
        }
        $this->save('shared', $shared, 'shared_config.changed', 'Shared config changed', $request);

        return Response::redirect($request->basePath . 'dev/');
    }

    public function saveSettings(Request $request, string $csrf): Response
    {
        [$settings, $errors] = self::settingsFromForm($request->form);
        if ($errors !== []) {
            return $this->render($request, $csrf, 422, $errors, SharedConfig::document($this->content, $request->origin()), $settings);
        }
        $this->save('roots', $settings, 'roots_settings.changed', 'Roots settings changed', $request);

        return Response::redirect($request->basePath . 'dev/');
    }

    /** @param array<string, mixed> $document */
    private function save(string $id, array $document, string $event, string $message, Request $request): void
    {
        $old = $this->content->get('config', $id) ?? [];
        $changed = array_keys(array_filter($document, static fn (mixed $value, string $field): bool => ($old[$field] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
        if ($changed === []) {
            return;
        }
        $this->content->put('config', ['id' => $id] + $document);
        $this->log->write('info', 'roots', $event, $message, ['fields' => $changed, 'ip' => $request->ip]);
    }

    /** @return SettingsDocument */
    private function settings(): array
    {
        $stored = $this->content->get('config', 'roots');
        if ($stored !== null) {
            /** @var SettingsDocument $document written only by saveSettings(), after validation */
            $document = array_diff_key($stored, ['id' => true]);

            return $document;
        }

        return ['max_age' => self::MAX_AGE, 'extra_origins' => []];
    }

    /**
     * @param list<string> $errors the fields at fault
     * @param SharedDocument $shared
     * @param SettingsDocument $settings
     */
    private function render(Request $request, string $csrf, int $status, array $errors, array $shared, array $settings): Response
    {
        $config = SharedConfig::fromStore($this->content, $request->origin());

        return Pages::html($this->template, $status, 'config', [
            'csrf' => $csrf,
            'errors' => $errors,
            'shared' => $shared,
            'settings' => $settings,
            'confirmed' => $this->confirmed(),
            'incompleteProfile' => $config->incompleteBusinessProfile(),
            'defaultSiteUrl' => $config->siteUrlIsDefault(),
            'siteOrigin' => self::origin($config->siteUrl),
            'types' => SharedConfig::TYPES,
            'locales' => LocaleResolver::SHIPPED,
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    /**
     * @param array<string, string> $form
     * @return array{SharedDocument, list<string>} the document and the fields at fault
     */
    private static function sharedFromForm(array $form): array
    {
        $errors = [];
        $text = static function (string $field) use ($form, &$errors): string {
            $value = trim($form[$field] ?? '');
            $length = preg_match_all('/./su', $value);
            if ($length === false || $length > self::MAX_TEXT || preg_match('/\p{Cc}/u', $value) === 1) {
                $errors[] = $field;
            }

            return $value;
        };
        $shared = [
            'name' => $text('name'),
            'type' => $form['type'] ?? '',
            'street_address' => $text('street_address'),
            'postal_code' => $text('postal_code'),
            'locality' => $text('locality'),
            'region' => $text('region'),
            'country' => strtoupper(trim($form['country'] ?? '')),
            'phone' => trim($form['phone'] ?? ''),
            'url' => trim($form['url'] ?? ''),
            'image' => trim($form['image'] ?? ''),
            'default_locale' => $form['default_locale'] ?? '',
            'enabled_locales' => array_values(array_filter(LocaleResolver::SHIPPED, static fn (string $locale): bool => isset($form["locale_$locale"]))),
            'timezone' => $form['timezone'] ?? '',
            'site_url' => trim($form['site_url'] ?? ''),
        ];
        $checks = [
            'type' => in_array($shared['type'], SharedConfig::TYPES, true),
            'country' => preg_match('/^([A-Z]{2})?$/', $shared['country']) === 1,
            'phone' => preg_match('~^(\+?[0-9][0-9 ()./-]{2,30})?$~', $shared['phone']) === 1,
            'url' => $shared['url'] === '' || self::webUrl($shared['url']),
            'image' => $shared['image'] === '' || self::webUrl($shared['image']),
            'default_locale' => in_array($shared['default_locale'], $shared['enabled_locales'], true),
            'timezone' => in_array($shared['timezone'], DateTimeZone::listIdentifiers(), true),
            'site_url' => self::webUrl($shared['site_url']),
        ];

        return [$shared, [...$errors, ...array_keys(array_filter($checks, static fn (bool $valid): bool => !$valid))]];
    }

    /**
     * @param array<string, string> $form
     * @return array{SettingsDocument, list<string>} the document and the fields at fault
     */
    private static function settingsFromForm(array $form): array
    {
        $maxAge = trim($form['max_age'] ?? '');
        $origins = [];
        foreach (preg_split('/\R/', strtolower($form['extra_origins'] ?? '')) ?: [] as $line) {
            if (trim($line) !== '') {
                // As browsers send it in the Origin header: without a default port.
                $origins[] = preg_replace(['~^(https://[^/]+):443$~', '~^(http://[^/]+):80$~'], '$1', rtrim(trim($line), '/')) ?? '';
            }
        }
        $origins = array_values(array_unique($origins));
        $errors = [];
        if (preg_match('/^[0-9]{1,2}$/', $maxAge) !== 1 || (int) $maxAge < 1 || (int) $maxAge > 60) {
            $errors[] = 'max_age';
        }
        if (count($origins) > self::MAX_ORIGINS || preg_grep(self::ORIGIN, $origins, PREG_GREP_INVERT) !== []) {
            $errors[] = 'extra_origins';
        }

        return [['max_age' => (int) $maxAge, 'extra_origins' => $origins], $errors];
    }

    /**
     * An absolute http(s) URL with a host, in printable ASCII (an IDN host
     * goes in punycode). `filter` is not an always-enabled extension.
     */
    private static function webUrl(string $url): bool
    {
        $parts = preg_match('~^https?://[\x21-\x7e]{1,2000}$~i', $url) === 1 ? parse_url($url) : false;

        return is_array($parts) && ($parts['host'] ?? '') !== '';
    }

    /** The scheme, host and port of an absolute URL, in lower case. */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        return strtolower(($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ":{$parts['port']}" : ''));
    }
}
