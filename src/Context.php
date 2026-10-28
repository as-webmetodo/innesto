<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use Innesto\Roots\Locale\Catalogue;
use Innesto\Roots\Locale\Formatter;
use Innesto\Roots\Store\Store;

/**
 * The whole of Roots a Module may reach, for one call: its own Store and
 * settings, the read-only Shared config, i18n, its templates, a logger
 * tagged with its Package id, the clock, the Actor, and mail when it
 * requires the `mail` capability. Tests build one from their own parts.
 */
final class Context
{
    /** The settings document's id in the settings collection. */
    private const SETTINGS = 'settings';

    /**
     * @param string $locale the locale of this call's output
     * @param Catalogue $catalogue the Module's own catalogues
     * @param Catalogue $dates Roots' catalogues, which hold the formatter's names and patterns
     * @param Template $templates the Module's templates, in $locale with $catalogue
     * @param string|null $csrf the session's CSRF token, given to Screen templates as `$csrf`
     */
    public function __construct(
        public readonly string $id,
        public readonly Store $store,
        public readonly SharedConfig $shared,
        public readonly Clock $clock,
        public readonly ?Actor $actor,
        public readonly string $locale,
        public readonly Catalogue $catalogue,
        public readonly Catalogue $dates,
        private readonly Template $templates,
        private readonly RootsLog $log,
        public readonly ?Mail $mail = null,
        private readonly ?string $csrf = null,
    ) {
    }

    /** @return array<string, mixed> the Module's settings, empty until it saves some */
    public function settings(): array
    {
        return array_diff_key($this->store->get(Manifest::SETTINGS, self::SETTINGS) ?? [], ['id' => true]);
    }

    /** @param array<string, mixed> $settings */
    public function saveSettings(array $settings): void
    {
        $this->store->put(Manifest::SETTINGS, ['id' => self::SETTINGS] + $settings);
    }

    /**
     * @param array<string, string|int> $params
     * @param string|null $locale another locale than this call's, such as the Default locale for stored data
     */
    public function text(string $key, array $params = [], ?string $locale = null): string
    {
        return $this->catalogue->text($locale ?? $this->locale, $key, $params);
    }

    /** @param array<string, string|int> $params */
    public function plural(string $key, int $count, array $params = []): string
    {
        return $this->catalogue->plural($this->locale, $key, $count, $params);
    }

    public function formatter(): Formatter
    {
        return new Formatter($this->dates, $this->locale);
    }

    /**
     * Renders the Module's template `templates/<name>.php`, which receives
     * $vars plus `$e`, `$t`, `$locale` and, on a Screen, `$csrf`.
     *
     * @param array<string, mixed> $vars
     */
    public function render(string $name, array $vars = []): string
    {
        return $this->templates->render($name, $vars + ($this->csrf === null ? [] : ['csrf' => $this->csrf]));
    }

    public function escape(string $text): string
    {
        return Template::escape($text);
    }

    /**
     * Writes a Roots log line tagged with the Module's Package id. Changes to
     * Module data are logged at `info`.
     *
     * @param 'info'|'warning'|'error' $level
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $event, string $message, array $context = []): void
    {
        $this->log->write($level, $this->id, $event, $message, $context);
    }
}
