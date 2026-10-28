<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use Innesto\Roots\Store\Schema;
use Throwable;
use UnexpectedValueException;

/**
 * A Module's Manifest: `modules/<id>/module.php`, a guarded file returning a
 * plain array, read without loading any of the Module's classes. Any
 * deviation from the shape below is a broken Manifest.
 *
 * @phpstan-type ViewOption array{type: 'string'|'int', required: bool}
 * @phpstan-type View array{v: int, options: array<string, ViewOption>}
 * @phpstan-import-type StoreSchema from Store\Store
 */
final class Manifest
{
    /** The Package id: `in.<name>` or `<vendor>.<name>`. */
    public const ID = '/^[a-z][a-z0-9]*\.[a-z][a-z0-9]*$/';

    /** Names a View option may not use: they are `view.php`'s own parameters. */
    public const RESERVED_OPTIONS = ['module', 'view', 'lang', 'format', 'v'];

    /** The collection Roots adds to every Module Store for the Module's settings. */
    public const SETTINGS = 'settings';

    private const KEYS = ['id', 'name', 'version', 'requires', 'namespace', 'entry', 'views', 'screens', 'tiles', 'store'];

    private const NAME = '/^[a-z][a-z0-9_]*$/';

    private const NAMESPACE = '/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)*$/';

    /**
     * @param list<string> $capabilities required capabilities
     * @param list<string> $optional capabilities used when present
     * @param array<string, View> $views
     * @param array<string, 'developer'|'client'> $screens
     * @param StoreSchema $store the Module's own collections, without Roots' settings collection
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly int $rootsApi,
        public readonly string $php,
        public readonly array $capabilities,
        public readonly array $optional,
        public readonly string $namespace,
        public readonly string $entry,
        public readonly array $views,
        public readonly array $screens,
        public readonly bool $tiles,
        public readonly array $store,
    ) {
    }

    /** @throws UnexpectedValueException naming what is wrong with `<dir>/module.php` */
    public static function read(string $dir): self
    {
        $file = "$dir/module.php";
        if (!is_file($file)) {
            throw new UnexpectedValueException('module.php is missing');
        }
        // Whatever it prints is held back: it would land in the Developer area's page.
        ob_start();
        try {
            $manifest = (static fn (string $__file): mixed => require $__file)($file);
        } catch (Throwable $error) {
            throw new UnexpectedValueException('module.php cannot be read: ' . $error->getMessage(), 0, $error);
        } finally {
            $printed = (string) ob_get_clean();
        }
        if ($printed !== '') {
            throw new UnexpectedValueException('module.php must not print anything');
        }
        if (!is_array($manifest) || !self::hasKeys($manifest, self::KEYS)) {
            throw new UnexpectedValueException('module.php must return an array with exactly the keys ' . implode(', ', self::KEYS));
        }
        $requires = $manifest['requires'];
        $store = $manifest['store'];
        $self = new self(
            self::string($manifest['id'], 'id', self::ID),
            self::string($manifest['name'], 'name', '/^\P{Cc}{1,100}$/u'),
            self::string($manifest['version'], 'version', '/^\d+\.\d+\.\d+$/'),
            is_array($requires) && is_int($requires['roots_api'] ?? null) ? $requires['roots_api'] : throw new UnexpectedValueException('requires.roots_api must be an integer'),
            self::string($requires['php'] ?? null, 'requires.php', '/^\d+\.\d+(\.\d+)?$/'),
            self::names($requires['capabilities'] ?? null, 'requires.capabilities'),
            self::names($requires['optional'] ?? null, 'requires.optional'),
            self::string($manifest['namespace'], 'namespace', self::NAMESPACE),
            self::string($manifest['entry'], 'entry', self::NAMESPACE),
            self::views($manifest['views']),
            self::screens($manifest['screens']),
            is_bool($manifest['tiles']) ? $manifest['tiles'] : throw new UnexpectedValueException('tiles must be true or false'),
            self::store($store),
        );
        if (!str_starts_with($self->entry, $self->namespace . '\\')) {
            throw new UnexpectedValueException('entry must be a class in the Module\'s namespace');
        }

        return $self;
    }

    /** The custom element: the Package id with a hyphen for the dot (`in.hours` → `in-hours`). */
    public function element(): string
    {
        return str_replace('.', '-', $this->id);
    }

    /** @return StoreSchema the Module Store's schema: the Manifest's collections and Roots' settings collection */
    public function storeSchema(): array
    {
        return ['schema' => $this->store['schema'], 'collections' => $this->store['collections'] + [self::SETTINGS => ['fields' => []]]];
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $keys
     */
    private static function hasKeys(array $value, array $keys): bool
    {
        $present = array_map(strval(...), array_keys($value));

        return array_diff($keys, $present) === [] && array_diff($present, $keys) === [];
    }

    private static function string(mixed $value, string $key, string $pattern): string
    {
        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : throw new UnexpectedValueException("$key is missing or malformed");
    }

    /** @return list<string> */
    private static function names(mixed $value, string $key): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new UnexpectedValueException("$key must be a list of names");
        }

        return array_map(static fn (mixed $name): string => self::string($name, $key, self::NAME), $value);
    }

    /** @return array<string, View> */
    private static function views(mixed $views): array
    {
        if (!is_array($views)) {
            throw new UnexpectedValueException('views must be an array');
        }
        $checked = [];
        foreach ($views as $name => $view) {
            $name = self::string($name, 'a View name', self::NAME);
            if (!is_array($view) || !self::hasKeys($view, ['v', 'options']) || !is_int($view['v']) || $view['v'] < 1 || !is_array($view['options'])) {
                throw new UnexpectedValueException("View $name must declare only v (from 1) and options");
            }
            $options = [];
            foreach ($view['options'] as $option => $declaration) {
                $option = self::string($option, "an option of View $name", self::NAME);
                if (in_array($option, self::RESERVED_OPTIONS, true)) {
                    throw new UnexpectedValueException("View $name uses the reserved option name $option");
                }
                if (!is_array($declaration) || !in_array($declaration['type'] ?? null, ['string', 'int'], true) || !is_bool($declaration['required'] ?? null) || !self::hasKeys($declaration, ['type', 'required'])) {
                    throw new UnexpectedValueException("Option $option of View $name must declare type (string or int) and required");
                }
                $options[$option] = ['type' => $declaration['type'], 'required' => $declaration['required']];
            }
            $checked[$name] = ['v' => $view['v'], 'options' => $options];
        }

        return $checked;
    }

    /** @return array<string, 'developer'|'client'> */
    private static function screens(mixed $screens): array
    {
        if (!is_array($screens)) {
            throw new UnexpectedValueException('screens must be an array');
        }
        $checked = [];
        foreach ($screens as $name => $side) {
            $checked[self::string($name, 'a Screen name', self::NAME)] = $side === 'developer' || $side === 'client'
                ? $side
                : throw new UnexpectedValueException("Screen $name must be developer or client");
        }

        return $checked;
    }

    /** @return StoreSchema */
    private static function store(mixed $store): array
    {
        if (!is_array($store) || !self::hasKeys($store, ['schema', 'collections']) || !is_int($store['schema']) || $store['schema'] < 1 || !is_array($store['collections'])) {
            throw new UnexpectedValueException('store must declare only schema (from 1) and collections');
        }
        $collections = [];
        foreach ($store['collections'] as $collection => $declaration) {
            $collection = self::string($collection, 'a collection name', self::NAME);
            if ($collection === self::SETTINGS) {
                throw new UnexpectedValueException('The collection name settings is Roots\' own');
            }
            $fields = is_array($declaration) && self::hasKeys($declaration, ['fields']) && is_array($declaration['fields']) ? $declaration['fields'] : throw new UnexpectedValueException("Collection $collection must declare its fields");
            $checked = [];
            foreach ($fields as $field => $type) {
                $checked[self::string($field, "a field of $collection", self::NAME)] = $type === 'int' || $type === 'string' ? $type : throw new UnexpectedValueException("Field $collection.$field must be int or string");
            }
            $collections[$collection] = ['fields' => $checked];
        }
        // Schema refuses the names SQL could not hold.
        new Schema(['schema' => $store['schema'], 'collections' => $collections]);

        return ['schema' => $store['schema'], 'collections' => $collections];
    }
}
