<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use Innesto\Roots\Locale\Catalogue;
use Innesto\Roots\Locale\LocaleResolver;
use Innesto\Roots\Store\Store;
use LogicException;
use Throwable;
use UnexpectedValueException;

/**
 * The Modules in `<roots>/modules/`, discovered on every Developer-area
 * load, and their lifecycle. A Module's state (Uploaded, Active, Inactive)
 * lives in the content Store; Halted is worked out on each load from the
 * Manifest, the requirements and the Store schema version, so it lasts
 * exactly as long as its cause. Only Active Modules are loaded, through a
 * PSR-4 autoloader from the Manifest namespace.
 *
 * A Halt is a catalogue key and its parameters.
 *
 * @phpstan-type Halt array{0: string, 1: array<string, string|int>}
 */
final class Modules
{
    /** The Module API this Roots offers; a Manifest asks for it in `requires.roots_api`. */
    public const ROOTS_API = 1;

    public const UPLOADED = 'uploaded';

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    /** @var array<string, string> each loaded Module's namespace prefix and source directory */
    private static array $autoload = [];

    /** @var array<string, array{manifest: ?Manifest, state: string, halt: ?Halt}>|null this request's discovery, until a state changes */
    private ?array $discovered = null;

    public function __construct(
        private readonly string $dir,
        private readonly RootsStores $stores,
        private readonly FaultFence $fence,
        private readonly RootsLog $log,
        private readonly Clock $clock,
        /** Roots' catalogues: the formatter's names and patterns */
        private readonly Catalogue $rootsCatalogue,
        /** Roots' origin, whose root is the default Site URL */
        private readonly string $origin,
    ) {
    }

    /**
     * Every folder in `modules/`, by Package id. Discovery never fails as a
     * whole: whatever is wrong with one Module Halts that one only.
     *
     * @return array<string, array{manifest: ?Manifest, state: string, halt: ?Halt}>
     */
    public function all(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }
        $modules = [];
        $folders = is_dir($this->dir) ? scandir($this->dir) : [];
        foreach ($folders === false ? [] : $folders as $folder) {
            if ($folder[0] !== '.' && is_dir("$this->dir/$folder")) {
                $modules[$folder] = $this->discover($folder);
            }
        }
        ksort($modules);

        return $this->discovered = $modules;
    }

    /**
     * Activates Module $id from Uploaded (creating its Store and running
     * onActivate in one transaction of it) or from Inactive (with its Store).
     * If anything throws, the new Store is removed and the Module stays Uploaded.
     *
     * @param string $locale the Developer area's locale, for onActivate's Context
     * @return Halt|null why it was refused, or null when it is Active
     */
    public function activate(string $id, string $locale): ?array
    {
        $module = $this->all()[$id] ?? null;
        if ($module === null || $module['state'] === self::ACTIVE) {
            return ['dev.modules.error.state', []];
        }
        $manifest = $module['manifest'];
        if ($module['halt'] !== null || $manifest === null) {
            return $module['halt'] ?? ['dev.modules.error.state', []];
        }
        if ($module['state'] === self::UPLOADED) {
            // A Store left by an activation that failed before recording its state.
            $this->stores->deleteModule($id);
            $activated = $this->fence->call($id, function () use ($id, $manifest, $locale): bool {
                $store = $this->stores->module($id, $manifest->storeSchema());
                $entry = $this->entry($manifest);
                $context = $this->context($manifest, $store, Actor::Developer, $locale, null);
                $store->transaction(static fn () => $entry->onActivate($context));

                return true;
            }, false);
            if (!$activated) {
                $this->stores->deleteModule($id);

                return ['dev.modules.error.activate', ['message' => $this->fence->fault($id)['message'] ?? '']];
            }
        }
        $this->stores->content->put('modules', ['id' => $id, 'state' => self::ACTIVE]);
        $this->discovered = null;
        $this->log->write('info', 'roots', 'module.activated', 'Module activated', ['module' => $id, 'from' => $module['state']]);

        return null;
    }

    /** Active → Inactive: nothing of it runs, its Store is kept. Halted Modules may be deactivated too. */
    public function deactivate(string $id): bool
    {
        if (($this->all()[$id]['state'] ?? null) !== self::ACTIVE) {
            return false;
        }
        $this->stores->content->put('modules', ['id' => $id, 'state' => self::INACTIVE]);
        $this->discovered = null;
        $this->log->write('info', 'roots', 'module.deactivated', 'Module deactivated', ['module' => $id]);

        return true;
    }

    /** Inactive → Uploaded: the whole Store is removed. */
    public function deleteData(string $id): bool
    {
        if (($this->all()[$id]['state'] ?? null) !== self::INACTIVE) {
            return false;
        }
        $this->stores->deleteModule($id);
        $this->stores->content->delete('modules', $id);
        $this->discovered = null;
        $this->log->write('info', 'roots', 'module.data_deleted', 'Module data deleted', ['module' => $id]);

        return true;
    }

    /** @return list<string> the locales Roots and Module $id both ship catalogues for */
    public function covered(string $id): array
    {
        return array_values(array_filter(LocaleResolver::SHIPPED, fn (string $locale): bool => is_file("$this->dir/$id/locales/$locale.php")));
    }

    /** @return list<string> the locales Roots and every Active Module cover: the ones the Developer may enable */
    public function enableable(): array
    {
        $locales = LocaleResolver::SHIPPED;
        foreach ($this->all() as $id => $module) {
            if ($module['state'] === self::ACTIVE) {
                $locales = array_values(array_intersect($locales, $this->covered($id)));
            }
        }

        return $locales;
    }

    /** @return array{manifest: ?Manifest, state: string, halt: ?Halt} */
    private function discover(string $id): array
    {
        $stored = $this->stores->content->get('modules', $id)['state'] ?? null;
        $state = $stored === self::ACTIVE || $stored === self::INACTIVE ? $stored : self::UPLOADED;
        if (preg_match(Manifest::ID, $id) !== 1) {
            return ['manifest' => null, 'state' => $state, 'halt' => ['dev.modules.halt.folder', []]];
        }
        try {
            $manifest = Manifest::read("$this->dir/$id");
        } catch (UnexpectedValueException $error) {
            return ['manifest' => null, 'state' => $state, 'halt' => ['dev.modules.halt.manifest', ['detail' => $error->getMessage()]]];
        }

        return ['manifest' => $manifest, 'state' => $state, 'halt' => $this->halt($id, $manifest, $state)];
    }

    /** @return Halt|null */
    private function halt(string $id, Manifest $manifest, string $state): ?array
    {
        if ($manifest->id !== $id) {
            return ['dev.modules.halt.id', ['id' => $manifest->id]];
        }
        $unmet = [];
        $manifest->rootsApi === self::ROOTS_API || $unmet[] = "Roots API $manifest->rootsApi";
        version_compare(PHP_VERSION, $manifest->php, '>=') || $unmet[] = "PHP $manifest->php";
        foreach ($manifest->capabilities as $capability) {
            ($capability === 'mail' && Mail::available()) || $unmet[] = $capability;
        }
        if ($unmet !== []) {
            return ['dev.modules.halt.requires', ['unmet' => implode(', ', $unmet)]];
        }
        // An Uploaded Module's Store can only be left by a failed activation, which removes it.
        if ($state !== self::UPLOADED && $this->stores->moduleExists($id)) {
            try {
                $version = $this->stores->module($id, $manifest->storeSchema())->schemaVersion();
            } catch (Throwable $error) {
                return ['dev.modules.halt.store', ['detail' => $error->getMessage()]];
            }
            if ($version !== $manifest->store['schema']) {
                return ['dev.modules.halt.schema', ['store' => $version, 'manifest' => $manifest->store['schema']]];
            }
        }

        return null;
    }

    /**
     * Runs Screen $name of Active Module $id inside the fault fence. A
     * redirect must name one of the Module's developer Screens.
     *
     * @return ScreenResult|null null when the Module is not Active, is Halted
     *     or has no such developer Screen; a fault gives an empty page with `500`
     */
    public function screen(string $id, string $name, ScreenRequest $request, string $locale, string $csrf): ?ScreenResult
    {
        $manifest = $this->runnable($id);
        if ($manifest === null || ($manifest->screens[$name] ?? null) !== 'developer') {
            return null;
        }

        return $this->fence->call($id, function () use ($manifest, $name, $request, $locale, $csrf): ScreenResult {
            $store = $this->stores->module($manifest->id, $manifest->storeSchema());
            $result = $this->entry($manifest)->screen($name, $this->context($manifest, $store, Actor::Developer, $locale, $csrf), $request);
            if ($result->screen !== null && ($manifest->screens[$result->screen] ?? null) !== 'developer') {
                throw new LogicException("Screen $name redirected to $result->screen, which is not a developer Screen of $manifest->id");
            }

            return $result;
        }, ScreenResult::page('', 500));
    }

    /** The Manifest of Module $id when it is Active and not Halted, else null. */
    public function runnable(string $id): ?Manifest
    {
        $module = $this->all()[$id] ?? null;

        return $module !== null && $module['state'] === self::ACTIVE && $module['halt'] === null ? $module['manifest'] : null;
    }

    /** The Context of one call into $manifest's Module, with its $store: one connection, so a transaction sees its own writes. */
    private function context(Manifest $manifest, Store $store, ?Actor $actor, string $locale, ?string $csrf): Context
    {
        $dir = "$this->dir/$manifest->id";
        $catalogue = new Catalogue("$dir/locales");

        return new Context(
            $manifest->id,
            $store,
            SharedConfig::fromStore($this->stores->content, $this->origin),
            $this->clock,
            $actor,
            $locale,
            $catalogue,
            $this->rootsCatalogue,
            new Template("$dir/templates", $catalogue, $locale),
            $this->log,
            in_array('mail', [...$manifest->capabilities, ...$manifest->optional], true) && Mail::available() ? new Mail() : null,
            $csrf,
        );
    }

    /** A new entry object of $manifest's Module, its namespace now autoloaded. */
    private function entry(Manifest $manifest): Module
    {
        if (self::$autoload === []) {
            spl_autoload_register(static function (string $class): void {
                foreach (self::$autoload as $prefix => $src) {
                    if (str_starts_with($class, $prefix)) {
                        $file = $src . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                        is_file($file) && require $file;

                        return;
                    }
                }
            });
        }
        self::$autoload[$manifest->namespace . '\\'] = "$this->dir/$manifest->id/src";
        $entry = $manifest->entry;
        if (!class_exists($entry) || !is_subclass_of($entry, Module::class)) {
            throw new LogicException("The entry class $entry is missing or does not implement Innesto\\Roots\\Module");
        }

        return new $entry();
    }
}
