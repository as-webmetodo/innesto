<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use Innesto\Roots\Http\Request;
use Innesto\Roots\Http\Response;
use Innesto\Roots\Locale\Catalogue;
use Innesto\Roots\Locale\LocaleResolver;
use RuntimeException;
use Throwable;

/** Roots' front controller: every stub entry point hands its request here. */
final class App
{
    /** Roots' own files: templates and catalogues sit beside `src/`. */
    private const FILES = __DIR__ . '/..';

    public function __construct(private readonly string $roots, private readonly string $documentRoot, private readonly Clock $clock)
    {
    }

    /** Serves the current request from a stub entry point (`index` or `dev`). */
    public static function run(string $entry): void
    {
        ini_set('display_errors', '0');
        defined('INNESTO_ROOTS') || define('INNESTO_ROOTS', true);
        $documentRoot = is_string($_SERVER['DOCUMENT_ROOT'] ?? null) ? $_SERVER['DOCUMENT_ROOT'] : '';
        try {
            $response = (new self(dirname(__DIR__), $documentRoot, new SystemClock()))->handle(Request::fromGlobals($entry));
        } catch (Throwable $error) {
            error_log('Innesto Roots: ' . $error);
            $response = new Response(500, [['Cache-Control', 'no-store']]);
        }
        $response->send();
    }

    public function handle(Request $request): Response
    {
        if ($request->entry === 'index') {
            return Response::redirect($request->basePath . 'dev/', 302);
        }
        $template = new Template(self::FILES . '/templates', new Catalogue(self::FILES . '/locales'), LocaleResolver::forDeveloperAreaAndClientPwa($request->header('accept-language')));
        if (!$request->https && !self::isLoopback($request->host)) {
            return Pages::html($template, 403, 'message', ['title' => 'dev.title', 'text' => 'dev.error.https']);
        }
        try {
            $data = DataDirectory::locate($this->roots, $this->documentRoot);
        } catch (RuntimeException) {
            return Pages::html($template, 500, 'message', ['title' => 'dev.title', 'text' => 'dev.error.data_dir']);
        }
        if ($data->upload !== null) {
            return Pages::html($template, 200, 'upload', ['contents' => $data->upload]);
        }
        $stores = RootsStores::open($data);
        $log = new RootsLog($data->path, $this->clock, bin2hex(random_bytes(8)));

        return (new DeveloperArea($stores->content, $stores->runtime, $data->path, $log, $this->clock, $template))->handle($request);
    }

    /** The explicit exception to HTTPS: a host name that only reaches this machine. */
    private static function isLoopback(string $host): bool
    {
        $name = preg_replace('/:\d+$/', '', $host);

        return in_array($name, ['localhost', '127.0.0.1', '[::1]'], true);
    }
}
