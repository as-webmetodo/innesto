<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Hours\Module;
use Innesto\Roots\Actor;
use Innesto\Roots\Context;
use Innesto\Roots\Locale\Catalogue;
use Innesto\Roots\Manifest;
use Innesto\Roots\RootsLog;
use Innesto\Roots\SharedConfig;
use Innesto\Roots\Store\FileStore;
use Innesto\Roots\Template;
use Innesto\Tests\FixedClock;

test('onActivate seeds the "Main" Schedule, closed every day, named in the Default locale', function (): void {
    $dir = sys_get_temp_dir() . '/innesto-hours-' . bin2hex(random_bytes(6));
    mkdir($dir);
    $hours = dirname(__DIR__);
    $roots = dirname(__DIR__, 3);
    $content = FileStore::open("$dir/content.php", ['schema' => 1, 'collections' => ['config' => ['fields' => []]]]);
    $content->put('config', ['id' => SharedConfig::ID, 'enabled_locales' => ['en', 'it'], 'default_locale' => 'en', 'timezone' => 'America/New_York'] + array_diff_key(SharedConfig::document($content, 'https://example.com'), ['enabled_locales' => 1, 'default_locale' => 1, 'timezone' => 1]));
    $store = FileStore::open("$dir/hours.php", Manifest::read($hours)->storeSchema());
    $catalogue = new Catalogue("$hours/locales");
    $clock = new FixedClock(new DateTimeImmutable('2026-10-27T10:00:00Z'));
    $context = new Context('in.hours', $store, SharedConfig::fromStore($content, 'https://example.com'), $clock, Actor::Developer, 'it', $catalogue, new Catalogue("$roots/locales"), new Template("$hours/templates", $catalogue, 'it'), new RootsLog($dir, $clock, 'req'));

    (new Module())->onActivate($context);

    expect($store->list('schedules'))->toBe([[
        'id' => 'main',
        'name' => ['en' => 'Main'],
        'timezone' => 'America/New_York',
        'position' => 0,
        'weekly' => ['mon' => [], 'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => []],
        'seasons' => [],
        'location' => null,
    ]])
        ->and(array_column(logLines("$dir/roots-log.php"), 'source'))->toBe(['in.hours']);
});

test('the Manifest declares the Views, Screens, tiles and Store of the spec', function (): void {
    $manifest = Manifest::read(dirname(__DIR__));

    expect($manifest->element())->toBe('in-hours')
        ->and(array_keys($manifest->views))->toBe(['week', 'status', 'jsonld'])
        ->and($manifest->screens)->toBe(['schedules' => 'developer', 'schedule' => 'developer', 'home' => 'client', 'regular' => 'client', 'exception' => 'client'])
        ->and($manifest->tiles)->toBeTrue()
        ->and($manifest->storeSchema())->toBe(['schema' => 1, 'collections' => [
            'schedules' => ['fields' => ['position' => 'int']],
            'exceptions' => ['fields' => ['schedule' => 'string']],
            'settings' => ['fields' => []],
        ]]);
});
