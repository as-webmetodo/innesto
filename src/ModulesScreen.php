<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use Innesto\Roots\Http\Request;
use Innesto\Roots\Http\Response;

/**
 * `dev/?screen=modules` and the Modules' own Screens for one signed-in
 * request: the Modules list with each one's state, Halted reason, error count
 * and locale gaps, the lifecycle actions, and `dev/?module=<id>&screen=<name>`
 * inside Roots' shell. A refused action re-renders the list with `422`.
 */
final class ModulesScreen
{
    public function __construct(
        private readonly Modules $modules,
        private readonly FaultFence $fence,
        private readonly SharedConfig $shared,
        private readonly Template $template,
        private readonly Request $request,
        private readonly string $csrf,
    ) {
    }

    /** @param array{0: string, 1: array<string, string|int>}|null $error a catalogue key and its parameters */
    public function page(int $status = 200, ?array $error = null): Response
    {
        $rows = [];
        foreach ($this->modules->all() as $id => $module) {
            $rows[] = [
                'id' => $id,
                'name' => $module['manifest'] === null ? $id : $module['manifest']->name,
                'state' => $module['state'],
                'halt' => $module['halt'],
                'fault' => $this->fence->fault($id),
                'gap' => $module['manifest'] === null ? [] : array_values(array_diff($this->shared->enabledLocales, $this->modules->covered($id))),
                'screens' => $module['state'] === Modules::ACTIVE && $module['halt'] === null && $module['manifest'] !== null
                    ? array_keys(array_filter($module['manifest']->screens, static fn (string $side): bool => $side === 'developer'))
                    : [],
            ];
        }

        return Pages::html($this->template, $status, 'modules', ['csrf' => $this->csrf, 'modules' => $rows, 'error' => $error]);
    }

    public function activate(): Response
    {
        $id = $this->request->form['module'] ?? '';
        $refused = $this->modules->activate($id, $this->template->locale);

        return $refused === null ? $this->backToList() : $this->page(422, $refused);
    }

    public function deactivate(): Response
    {
        return $this->modules->deactivate($this->request->form['module'] ?? '') ? $this->backToList() : $this->page(422, ['dev.modules.error.state', []]);
    }

    /** Only with the confirmation box ticked. */
    public function deleteData(): Response
    {
        if (!isset($this->request->form['confirm'])) {
            return $this->page(422, ['dev.modules.error.confirm', []]);
        }

        return $this->modules->deleteData($this->request->form['module'] ?? '') ? $this->backToList() : $this->page(422, ['dev.modules.error.state', []]);
    }

    /** A developer Screen of an Active Module, for a GET or an already checked POST. */
    public function screen(): Response
    {
        $id = $this->request->query['module'] ?? '';
        $name = $this->request->query['screen'] ?? '';
        $method = $this->request->method === 'POST' ? 'POST' : 'GET';
        $manifest = $this->modules->developerScreen($id, $name);
        if ($manifest === null) {
            return Pages::html($this->template, 404, 'message', ['title' => 'dev.title', 'text' => 'dev.modules.error.no_screen']);
        }
        $request = new ScreenRequest($method, array_diff_key($this->request->query, ['module' => true, 'screen' => true]), array_diff_key($this->request->form, ['csrf' => true]));
        $result = $this->modules->screen($manifest, $name, $request, $this->template->locale, $this->csrf);
        if ($result?->screen !== null) {
            return Response::redirect($this->request->basePath . 'dev/?' . http_build_query(['module' => $id, 'screen' => $result->screen] + $result->params));
        }

        return Pages::html($this->template, $result === null ? 500 : $result->status, 'screen', [
            'module' => $manifest->name,
            'id' => $id,
            'screens' => array_keys(array_filter($manifest->screens, static fn (string $side): bool => $side === 'developer')),
            'current' => $name,
            // A fault leaves nothing to show: Roots says so instead.
            'content' => $result?->html,
        ]);
    }

    private function backToList(): Response
    {
        return Response::redirect($this->request->basePath . 'dev/?screen=modules');
    }
}
