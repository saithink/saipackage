<?php
namespace plugin\saipackage\app\controller;

use plugin\saiadmin\app\middleware\CheckLogin;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\exception\ApiException;
use Saithink\Saipackage\management\Factory;
use Saithink\Saipackage\management\Manifest;
use Saithink\Saipackage\management\Tasks;
use support\annotation\Middleware;
use support\Request;
use support\Response;

// Deliberately omit SystemLog: settings/probe request bodies can contain credentials.
#[Middleware(CheckLogin::class)]
final class ManagementController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        if ($this->adminId !== 1 && $this->adminId !== '1') throw new ApiException('仅超级管理员能够操作', 403);
        if (!Factory::enabled()) throw new ApiException('Plugin management is not enabled', 503);
    }

    private function id(Request $request): string
    {
        return Manifest::id((string) $request->input('appName', ''));
    }

    private function item(string $id): array
    {
        $item = Factory::manager()->store->get('installations', $id)['value'] ?? null;
        if (!$item) throw new ApiException('Plugin is not installed', 404);
        // A failed upgrade may already have migrated settings. Edit the target contract, never downgrade it.
        if (isset($item['pending']) && in_array($item['state'], ['installing', 'upgrading', 'failed', 'waiting_dependencies'], true)) {
            $item = array_replace($item, $item['pending']);
        }
        return $item;
    }

    public function catalog(Request $request): Response
    {
        return $this->success(Factory::manager()->catalog());
    }

    public function plan(Request $request): Response
    {
        $manager = Factory::manager();
        $lock = $manager->lock();
        $plan = $manager->plan($this->id($request));
        return $this->success(array_intersect_key($plan, array_flip(['root', 'selected', 'changes', 'fingerprint'])));
    }

    public function submit(Request $request): Response
    {
        $id = (new Tasks(Factory::manager()))->submit($this->id($request), (string) $request->post('fingerprint', ''), (int) $this->adminId);
        return $this->success(['taskId' => $id]);
    }

    public function tasks(Request $request): Response
    {
        $manager = Factory::manager();
        $tasks = $manager->store->all('tasks');
        foreach ($tasks as &$row) {
            if (isset($row['value']['plan'])) $row['value']['plan'] = array_intersect_key($row['value']['plan'], array_flip(['root', 'changes']));
            $task = $row['value'];
            if (($task['kind'] ?? '') === 'prepare_update' && $task['state'] === 'succeeded' && !isset($task['confirmedTaskId'])) {
                $current = $manager->store->get('installations', $task['plugin'])['value']['manifest']['version'] ?? '';
                $target = $task['result']['changes'][$task['plugin']]['to'] ?? '';
                if ($current && $target && \Saithink\Saipackage\management\Manifest::matches($current, '>=' . $target)) $row['value']['stage'] = 'obsolete';
            }
        }
        unset($row);
        return $this->success($tasks);
    }

    public function recover(Request $request): Response
    {
        (new Tasks(Factory::manager()))->recover((string) $request->post('taskId'), (string) $request->post('action'), (int) $request->post('revision'), (int) $this->adminId);
        return $this->success('ok');
    }

    public function settings(Request $request): Response
    {
        $id = $this->id($request);
        $item = $this->item($id);
        return $this->success(['schema' => $item['schema'], 'settingsVersion' => $item['manifest']['settingsVersion'] ?? 1] + Factory::manager()->settings->view($id, $item['schema']));
    }

    public function saveSettings(Request $request): Response
    {
        $manager = Factory::manager();
        $lock = $manager->lock();
        $id = $this->id($request);
        $item = $this->item($id);
        $revision = $manager->settings->save($id, $item['schema'], (int) ($item['manifest']['settingsVersion'] ?? 1),
            (array) $request->post('values', []), (array) $request->post('secretActions', []), (int) $request->post('revision', -1), (int) $this->adminId);
        return $this->success(['revision' => $revision]);
    }

    public function clearSettings(Request $request): Response
    {
        $manager = Factory::manager();
        $lock = $manager->lock();
        $id = $this->id($request);
        $item = $this->item($id);
        if ($item['state'] !== 'uninstalled' && $item['enabled']) throw new ApiException('Disable the plugin before clearing its settings');
        $manager->settings->clear($id, (int) $request->post('revision', -1));
        return $this->success('ok');
    }

    public function probe(Request $request): Response
    {
        $manager = Factory::manager();
        $id = $this->id($request);
        $item = $this->item($id);
        $draft = $request->post('draft', false) === true;
        $values = $draft ? $manager->settings->prepare($id, $item['schema'], (array) $request->post('values', []), (array) $request->post('secretActions', [])) : null;
        return $this->success(Factory::services($manager)->check($id, $item['manifest'], $item['schema'], $values));
    }

    public function enable(Request $request): Response
    {
        $enabled = $request->post('enabled');
        if (!is_bool($enabled)) throw new ApiException('enabled must be boolean');
        Factory::manager()->enable($this->id($request), $enabled);
        return $this->success('ok');
    }

    public function uninstallPlan(Request $request): Response
    {
        $id = $this->id($request);
        $manager = Factory::manager();
        $manager->assertRemovable($id);
        return $this->success(['appName' => $id, 'revision' => $manager->store->get('installations', $id)['revision'] ?? null,
            'effects' => ['database' => 'Plugin uninstall migrations/SQL may delete business data', 'settings' => 'retained', 'docker' => 'untouched']]);
    }

    public function docs(Request $request): Response
    {
        $manager = Factory::manager();
        $id = $this->id($request);
        $item = $manager->store->get('installations', $id) ?? $manager->store->get('packages', $id);
        if (!$item) throw new ApiException('Plugin package not found', 404);
        return $this->success($item['value']['docs']);
    }

    public function updates(Request $request): Response
    {
        $manager = Factory::manager();
        return $this->success(Factory::updates($manager)->catalog());
    }

    public function checkUpdates(Request $request): Response
    {
        $manager = Factory::manager();
        $id = $request->post('appName') ? $this->id($request) : null;
        if ($id !== null) $this->item($id);
        Factory::updates($manager)->requestCheck($id);
        return $this->success(['state' => 'pending']);
    }

    public function prepareUpdate(Request $request): Response
    {
        $manager = Factory::manager();
        $id = Factory::updates($manager)->requestPreparation($this->id($request), (string) $request->post('releaseFingerprint', ''), $this->adminId);
        return $this->success(['taskId' => $id]);
    }

    public function updatePlan(Request $request): Response
    {
        $manager = Factory::manager();
        $lock = $manager->lock();
        return $this->success(Factory::updates($manager)->preparedPlan((string) $request->post('taskId'), Factory::services($manager)));
    }

    public function confirmUpdate(Request $request): Response
    {
        $manager = Factory::manager();
        $taskId = Factory::updates($manager)->confirm((string) $request->post('taskId'), (string) $request->post('fingerprint'), $this->adminId, Factory::services($manager));
        return $this->success(['taskId' => $taskId]);
    }
}
