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

    public function __construct(
        private readonly string $roots,
        private readonly string $documentRoot,
        private readonly Clock $clock,
        private readonly ErrorHandler $errors = new ErrorHandler(),
    ) {
    }

    /** Serves the current request from a stub entry point (`index` or `dev`). */
    public static function run(string $entry): void
    {
        ini_set('display_errors', '0');
        defined('INNESTO_ROOTS') || define('INNESTO_ROOTS', true);
        $errors = ErrorHandler::install();
        $documentRoot = is_string($_SERVER['DOCUMENT_ROOT'] ?? null) ? $_SERVER['DOCUMENT_ROOT'] : '';
        try {
            $response = (new self(dirname(__DIR__), $documentRoot, new SystemClock(), $errors))->handle(Request::fromGlobals($entry));
        } catch (Throwable $error) {
            $errors->uncaught($error);
            $response = new Response(500, [['Cache-Control', 'no-store']]);
        }
        $response->send();
    }

    public function handle(Request $request): Response
    {
        if ($request->entry === 'index') {
            return Response::redirect($request->basePath . 'dev/', 302);
        }
        $catalogue = new Catalogue(self::FILES . '/locales');
        $template = new Template(self::FILES . '/templates', $catalogue, LocaleResolver::forDeveloperAreaAndClientPwa($request->header('accept-language')));
        if (!$request->https && !$request->loopback()) {
            return Pages::html($template, 403, 'https');
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
        $fence = new FaultFence($stores->runtime, $log, $this->clock);
        $this->errors->attach($log, $fence);
        $modules = new Modules("$this->roots/modules", $stores, $fence, $log, $this->clock, $catalogue, $request->origin());

        $selfCheck = new SelfCheck($stores, $data, $log, $this->clock, $request);

        return (new DeveloperArea($stores->content, $stores->runtime, $data->path, $log, $this->clock, $template, $modules, $fence, $selfCheck))->handle($request);
    }
}
