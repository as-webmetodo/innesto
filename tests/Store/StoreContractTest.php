<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\Store\FileStore;
use Innesto\Roots\Store\SqliteStore;
use Innesto\Roots\Store\Store;

const SCHEMA = [
    'schema' => 3,
    'collections' => [
        'schedules' => ['fields' => ['position' => 'int']],
        'exceptions' => ['fields' => ['schedule' => 'string']],
    ],
];

/**
 * Opens (creating it on first use) the Store at $path with the given backend.
 *
 * @param array{schema: int, collections: array<string, array{fields: array<string, 'int'|'string'>}>} $schema
 */
function openStore(string $backend, string $path, array $schema = SCHEMA): Store
{
    return $backend === 'sqlite'
        ? SqliteStore::open("$path.sqlite", $schema)
        : FileStore::open("$path.php", $schema);
}

function storePath(): string
{
    $dir = sys_get_temp_dir() . '/innesto-store-' . bin2hex(random_bytes(6));
    mkdir($dir);

    return "$dir/in.hours";
}

dataset('backends', ['sqlite', 'files']);

test('a put document is returned by get', function (string $backend): void {
    $store = openStore($backend, storePath());

    $store->put('schedules', ['id' => 'main', 'position' => 1, 'name' => ['it' => 'Negozio']]);

    expect($store->get('schedules', 'main'))->toBe(['id' => 'main', 'position' => 1, 'name' => ['it' => 'Negozio']])
        ->and($store->get('schedules', 'other'))->toBeNull();
})->with('backends');

test('put replaces a document and delete removes it', function (string $backend): void {
    $store = openStore($backend, storePath());
    $store->put('schedules', ['id' => 'main', 'position' => 1]);

    $store->put('schedules', ['id' => 'main', 'position' => 2]);
    expect($store->get('schedules', 'main'))->toBe(['id' => 'main', 'position' => 2]);

    $store->delete('schedules', 'main');
    expect($store->get('schedules', 'main'))->toBeNull()
        ->and($store->list('schedules'))->toBe([]);
})->with('backends');

test('collections are separate', function (string $backend): void {
    $store = openStore($backend, storePath());
    $store->put('schedules', ['id' => 'x', 'position' => 1]);

    expect($store->get('exceptions', 'x'))->toBeNull();
})->with('backends');

test('list applies equality and range filters on declared fields', function (string $backend): void {
    $store = openStore($backend, storePath());
    foreach ([1, 2, 3, 4] as $position) {
        $store->put('schedules', ['id' => "s$position", 'position' => $position]);
    }
    $store->put('exceptions', ['id' => 'a', 'schedule' => 'main']);
    $store->put('exceptions', ['id' => 'b', 'schedule' => 'bar']);

    $ids = fn (array $documents): array => array_column($documents, 'id');

    expect($ids($store->list('schedules', [['position', '=', 2]])))->toBe(['s2'])
        ->and($ids($store->list('schedules', [['position', '<', 2]])))->toBe(['s1'])
        ->and($ids($store->list('schedules', [['position', '<=', 2]])))->toBe(['s1', 's2'])
        ->and($ids($store->list('schedules', [['position', '>', 3]])))->toBe(['s4'])
        ->and($ids($store->list('schedules', [['position', '>=', 2], ['position', '<', 4]])))->toBe(['s2', 's3'])
        ->and($ids($store->list('exceptions', [['schedule', '=', 'main']])))->toBe(['a']);
})->with('backends');

test('list orders by a declared field then by id, and limits', function (string $backend): void {
    $store = openStore($backend, storePath());
    $store->put('schedules', ['id' => 'c', 'position' => 2]);
    $store->put('schedules', ['id' => 'b', 'position' => 1]);
    $store->put('schedules', ['id' => 'a', 'position' => 2]);

    $ids = fn (array $documents): array => array_column($documents, 'id');

    expect($ids($store->list('schedules')))->toBe(['a', 'b', 'c'])
        ->and($ids($store->list('schedules', orderBy: 'position')))->toBe(['b', 'a', 'c'])
        ->and($ids($store->list('schedules', orderBy: 'position', descending: true)))->toBe(['a', 'c', 'b'])
        ->and($ids($store->list('schedules', orderBy: 'position', limit: 2)))->toBe(['b', 'a'])
        ->and($store->list('schedules', limit: 0))->toBe([]);
})->with('backends');

test('strings filter and order bytewise, never as numbers', function (string $backend): void {
    $store = openStore($backend, storePath());
    foreach (['9', '10', 'b', 'B'] as $value) {
        $store->put('exceptions', ['id' => "id$value", 'schedule' => $value]);
    }

    expect(array_column($store->list('exceptions', orderBy: 'schedule'), 'schedule'))->toBe(['10', '9', 'B', 'b'])
        ->and(array_column($store->list('exceptions', [['schedule', '<', '9']]), 'schedule'))->toBe(['10']);
})->with('backends');

test('put rejects a document whose declared field is null, missing or mistyped', function (string $backend, array $document): void {
    /** @var array<string, mixed> $document */
    $store = openStore($backend, storePath());

    expect(fn () => $store->put('schedules', $document))->toThrow(InvalidArgumentException::class)
        ->and($store->list('schedules'))->toBe([]);
})->with('backends')->with([
    'null' => [['id' => 'main', 'position' => null]],
    'missing' => [['id' => 'main']],
    'mistyped' => [['id' => 'main', 'position' => '1']],
    'without id' => [['position' => 1]],
    'empty id' => [['id' => '', 'position' => 1]],
]);

test('list rejects undeclared fields and unknown operators', function (string $backend): void {
    $store = openStore($backend, storePath());

    expect(fn () => $store->list('schedules', [['name', '=', 'x']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->list('schedules', [['position', '= 1 OR 1 =', 1]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->list('schedules', orderBy: 'name'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->list('unknown'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->list('schedules', limit: -1))->toThrow(InvalidArgumentException::class);
})->with('backends');

test('a schema with a name unfit for SQL is rejected before anything is written', function (string $backend, array $collections): void {
    /** @var array<string, array{fields: array<string, 'int'|'string'>}> $collections */
    $path = storePath();

    expect(fn () => openStore($backend, $path, ['schema' => 1, 'collections' => $collections]))->toThrow(InvalidArgumentException::class)
        ->and(glob(dirname($path) . '/*'))->toBe([]);
})->with('backends')->with([
    'collection' => [['bad"name' => ['fields' => []]]],
    'field' => [['notes' => ['fields' => ['Bad Field' => 'int']]]],
]);

test('a transaction commits every write together', function (string $backend): void {
    $path = storePath();
    $store = openStore($backend, $path);

    $result = $store->transaction(function () use ($store): string {
        $store->put('schedules', ['id' => 'a', 'position' => 1]);
        $store->put('schedules', ['id' => 'b', 'position' => 2]);
        expect($store->get('schedules', 'a'))->not->toBeNull();

        return 'done';
    });

    expect($result)->toBe('done')
        ->and(array_column(openStore($backend, $path)->list('schedules'), 'id'))->toBe(['a', 'b']);
})->with('backends');

test('a transaction that throws writes nothing', function (string $backend): void {
    $path = storePath();
    $store = openStore($backend, $path);
    $store->put('schedules', ['id' => 'a', 'position' => 1]);

    expect(fn () => $store->transaction(function () use ($store): void {
        $store->put('schedules', ['id' => 'b', 'position' => 2]);
        $store->delete('schedules', 'a');
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect(array_column($store->list('schedules'), 'id'))->toBe(['a'])
        ->and(array_column(openStore($backend, $path)->list('schedules'), 'id'))->toBe(['a'])
        ->and($store->revision())->toBe(1);
})->with('backends');

test('a nested transaction joins the outer one', function (string $backend): void {
    $store = openStore($backend, storePath());

    expect(fn () => $store->transaction(function () use ($store): void {
        $store->transaction(fn () => $store->put('schedules', ['id' => 'a', 'position' => 1]));
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect($store->list('schedules'))->toBe([])
        ->and($store->revision())->toBe(0);
})->with('backends');

test('the revision is bumped once per committed write transaction', function (string $backend): void {
    $path = storePath();
    $store = openStore($backend, $path);
    expect($store->revision())->toBe(0);

    $store->put('schedules', ['id' => 'a', 'position' => 1]);
    $store->transaction(function () use ($store): void {
        $store->put('schedules', ['id' => 'b', 'position' => 2]);
        $store->delete('schedules', 'a');
    });
    $store->transaction(fn () => $store->get('schedules', 'b'));

    expect($store->revision())->toBe(2)
        ->and(openStore($backend, $path)->revision())->toBe(2);
})->with('backends');

test('a write that changes nothing does not bump the revision', function (string $backend): void {
    $store = openStore($backend, storePath());
    $store->put('schedules', ['id' => 'a', 'position' => 1, 'name' => ['it' => 'Negozio']]);

    $store->put('schedules', ['id' => 'a', 'position' => 1, 'name' => ['it' => 'Negozio']]);
    $store->delete('schedules', 'missing');
    $store->transaction(fn () => $store->put('schedules', ['id' => 'a', 'position' => 1, 'name' => ['it' => 'Negozio']]));

    expect($store->revision())->toBe(1);
})->with('backends');

test('the Store keeps the schema version it was created with', function (string $backend): void {
    $path = storePath();
    expect(openStore($backend, $path)->schemaVersion())->toBe(3);

    $reopened = openStore($backend, $path, ['schema' => 4] + SCHEMA);

    expect($reopened->schemaVersion())->toBe(3);
})->with('backends');

test('a reopened Store keeps the collections and fields it was created with', function (string $backend): void {
    $path = storePath();
    openStore($backend, $path)->put('schedules', ['id' => 'a', 'position' => 1]);

    // Same version, changed declaration: nothing migrates, so the created one stays.
    $reopened = openStore($backend, $path, ['schema' => 3, 'collections' => [
        'schedules' => ['fields' => ['position' => 'int', 'name' => 'string']],
        'notes' => ['fields' => []],
    ]]);
    $reopened->put('schedules', ['id' => 'b', 'position' => 2]);

    expect(array_column($reopened->list('schedules'), 'id'))->toBe(['a', 'b'])
        ->and($reopened->get('exceptions', 'x'))->toBeNull()
        ->and(fn () => $reopened->list('notes'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $reopened->list('schedules', orderBy: 'name'))->toThrow(InvalidArgumentException::class);
})->with('backends');

test('a Store file without its guard is refused', function (): void {
    $path = storePath();
    file_put_contents("$path.php", '{"schema":1,"declared":{},"revision":0,"collections":{}}');

    expect(fn () => openStore('files', $path))->toThrow(RuntimeException::class);
});

test('the guarded Store file prints nothing when requested directly', function (): void {
    $path = storePath();
    $store = openStore('files', $path);
    $store->put('exceptions', ['id' => 'x', 'schedule' => 'main', 'note' => '<?php echo "leak"; ?> <?= "leak" ?>']);

    $output = shell_exec(PHP_BINARY . ' ' . escapeshellarg("$path.php"));

    expect($output)->toBeIn([null, ''])
        ->and($store->get('exceptions', 'x')['note'] ?? null)->toBe('<?php echo "leak"; ?> <?= "leak" ?>');
});
