<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\Actor;
use Innesto\Roots\Context;
use Innesto\Roots\Locale\Catalogue;
use Innesto\Roots\RootsLog;
use Innesto\Roots\SharedConfig;
use Innesto\Roots\Store\FileStore;
use Innesto\Roots\Template;
use Innesto\Tests\FixedClock;

/** A Context in $locale whose Module catalogue is the Locale tests' fixture. */
function fixtureContext(string $locale): Context
{
    $dir = sys_get_temp_dir() . '/innesto-context-' . bin2hex(random_bytes(6));
    mkdir($dir);
    $content = FileStore::open("$dir/content.php", ['schema' => 1, 'collections' => ['config' => ['fields' => []]]]);
    $store = FileStore::open("$dir/module.php", ['schema' => 1, 'collections' => ['settings' => ['fields' => []]]]);
    $catalogue = new Catalogue(dirname(__DIR__) . '/Locale/fixtures');
    $clock = new FixedClock(new DateTimeImmutable('2026-10-27T10:00:00Z'));

    return new Context('acme.probe', $store, SharedConfig::fromStore($content, 'https://example.com'), $clock, Actor::Developer, $locale, $catalogue, new Catalogue(dirname(__DIR__, 2) . '/locales'), new Template(dirname(__DIR__) . '/fixtures/modules/acme.probe/templates', $catalogue, $locale), new RootsLog($dir, $clock, 'req'));
}

test('the Context\'s text comes from the Module\'s catalogue, in its locale or one asked for', function (): void {
    $context = fixtureContext('it');

    expect($context->text('greeting', ['name' => 'Ada']))->toBe('Ciao, Ada')
        ->and($context->text('greeting', ['name' => 'Ada'], 'en'))->toBe('Hello, Ada')
        ->and($context->plural('apples', 1))->toBe('1 mela')
        ->and($context->plural('apples', 3))->toBe('3 mele');
});

test('the Context\'s formatter uses Roots\' names and patterns in the Context\'s locale', function (): void {
    expect(fixtureContext('en-GB')->formatter()->dayMonth(new DateTimeImmutable('2026-11-03 12:00')))->toBe('3 November')
        ->and(fixtureContext('en')->formatter()->dayMonth(new DateTimeImmutable('2026-11-03 12:00')))->toBe('November 3');
});

test('the Context escapes text for HTML', function (): void {
    expect(fixtureContext('en')->escape('<a href="x">Tom & Jerry\'s</a>'))->toBe('&lt;a href=&quot;x&quot;&gt;Tom &amp; Jerry&#039;s&lt;/a&gt;');
});
