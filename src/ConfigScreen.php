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
 * `dev/?screen=config` for one signed-in request: the Shared config and the
 * Roots settings, one form each. A valid save writes the content Store
 * (bumping its revision when something changed), logs the changed fields
 * and redirects; an invalid one re-renders with `422` and the fields at fault.
 *
 * @phpstan-import-type SharedDocument from SharedConfig
 */
final class ConfigScreen
{
    public function __construct(
        private readonly Store $content,
        private readonly RootsLog $log,
        private readonly Template $template,
        private readonly Request $request,
        private readonly string $csrf,
    ) {
    }

    public function confirmed(): bool
    {
        return $this->content->get('config', SharedConfig::ID) !== null;
    }

    public function page(): Response
    {
        return $this->render(200, [], SharedConfig::document($this->content, $this->request->origin()), null);
    }

    public function saveShared(): Response
    {
        [$shared, $errors] = SharedConfig::fromForm($this->request->form);
        if ($errors !== []) {
            return $this->render(422, $errors, $shared, null);
        }
        $this->save(SharedConfig::ID, $shared, 'shared_config.changed', 'Shared config changed');

        return Response::redirect($this->request->basePath . 'dev/');
    }

    public function saveSettings(): Response
    {
        [$settings, $errors] = RootsSettings::fromForm($this->request->form);
        if ($errors !== []) {
            // Shown as typed, so the Developer sees what was refused.
            return $this->render(422, $errors, SharedConfig::document($this->content, $this->request->origin()), [
                'max_age' => $this->request->form['max_age'] ?? '',
                'extra_origins' => $this->request->form['extra_origins'] ?? '',
            ]);
        }
        $this->save(RootsSettings::ID, $settings, 'roots_settings.changed', 'Roots settings changed');

        return Response::redirect($this->request->basePath . 'dev/');
    }

    /** @param array<string, mixed> $document */
    private function save(string $id, array $document, string $event, string $message): void
    {
        $old = $this->content->get('config', $id) ?? [];
        $changed = array_keys(array_filter($document, static fn (mixed $value, string $field): bool => ($old[$field] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
        if ($changed === []) {
            return;
        }
        $this->content->put('config', ['id' => $id] + $document);
        $this->log->write('info', 'roots', $event, $message, ['fields' => $changed, 'ip' => $this->request->ip]);
    }

    /**
     * @param list<string> $errors the fields at fault
     * @param SharedDocument $shared
     * @param array{max_age: string, extra_origins: string}|null $settings the settings form's values, or null for the stored ones
     */
    private function render(int $status, array $errors, array $shared, ?array $settings): Response
    {
        $config = SharedConfig::fromStore($this->content, $this->request->origin());
        // The current zone stays selectable when it is only a backward-compatible name.
        $timezones = DateTimeZone::listIdentifiers();
        if (!in_array($shared['timezone'], $timezones, true) && in_array($shared['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            $timezones[] = $shared['timezone'];
        }
        if ($settings === null) {
            $stored = RootsSettings::fromStore($this->content);
            $settings = ['max_age' => (string) $stored->maxAge, 'extra_origins' => implode("\n", $stored->extraOrigins)];
        }

        return Pages::html($this->template, $status, 'config', [
            'csrf' => $this->csrf,
            'errors' => $errors,
            'shared' => $shared,
            'settings' => $settings,
            'confirmed' => $this->confirmed(),
            'incompleteProfile' => $config->incompleteBusinessProfile(),
            'defaultSiteUrl' => $config->siteUrlIsDefault(),
            'siteOrigin' => $config->siteOrigin(),
            'types' => SharedConfig::TYPES,
            'locales' => LocaleResolver::SHIPPED,
            'timezones' => $timezones,
        ]);
    }
}
