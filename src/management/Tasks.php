<?php
namespace Saithink\Saipackage\management;

use RuntimeException;

final class Tasks
{
    public function __construct(private Manager $manager)
    {
    }

    public function submit(string $root, string $fingerprint, int $actor): string
    {
        $lock = $this->manager->lock();
        $plan = $this->manager->plan($root);
        if (!hash_equals($plan['fingerprint'], $fingerprint)) throw new ManagementException('Installation plan has changed; review it again', 409);
        foreach ($this->manager->store->all('tasks') as $row) {
            if (in_array($row['value']['state'], ['queued', 'running', 'needs_attention', 'waiting_dependencies'], true)) throw new ManagementException('Finish or resolve the existing installation task first', 409);
        }
        $id = bin2hex(random_bytes(16));
        $this->manager->store->put('tasks', $id, ['state' => 'queued', 'kind' => 'install', 'plan' => $plan, 'actor' => $actor,
            'completed' => [], 'current' => null, 'events' => [], 'createdAt' => time()], 0);
        return $id;
    }

    public function submitUninstall(string $id, int $actor): string
    {
        $lock = $this->manager->lock();
        $this->manager->assertRemovable($id);
        foreach ($this->manager->store->all('tasks') as $row) {
            if (in_array($row['value']['state'], ['queued', 'running', 'needs_attention', 'waiting_dependencies'], true)) throw new ManagementException('Resolve the existing task first', 409);
        }
        $installation = $this->manager->store->get('installations', Manifest::id($id));
        if (!$installation || !in_array($installation['value']['state'], ['installed', 'failed', 'waiting_dependencies'], true)) throw new ManagementException('Plugin is not installed');
        $taskId = bin2hex(random_bytes(16));
        $this->manager->store->put('tasks', $taskId, ['state' => 'queued', 'kind' => 'uninstall', 'plugin' => $id, 'installationRevision' => $installation['revision'],
            'installationFingerprint' => $installation['value']['fingerprint'] ?? null,
            'actor' => $actor, 'completed' => [], 'current' => null, 'events' => [], 'createdAt' => time()], 0);
        return $taskId;
    }

    /** One durable plugin step per poll. Interrupted non-idempotent work requires explicit recovery. */
    public function tick(callable $install, callable $uninstall, ?callable $prepareUpdate = null): bool
    {
        $lock = $this->manager->lock();
        foreach ($this->manager->store->all('tasks') as $id => $row) {
            $task = $row['value'];
            if ($task['state'] === 'running') {
                $task['state'] = 'needs_attention';
                $task['error'] = 'WORKER_INTERRUPTED';
                $this->manager->store->put('tasks', $id, $task, $row['revision']);
                return false;
            }
            if (in_array($task['state'], ['needs_attention', 'waiting_dependencies'], true)) return false;
            if ($task['state'] !== 'queued') continue;
            if ($task['kind'] === 'prepare_update') {
                $task['state'] = 'running';
                $task['current'] = $task['plugin'];
                $task['stage'] = 'download';
                $revision = $this->manager->store->put('tasks', $id, $task, $row['revision']);
                try {
                    if (!$prepareUpdate) throw new ManagementException('UPDATE_PREPARATION_UNAVAILABLE');
                    $task['result'] = $prepareUpdate($task);
                    $task['state'] = 'succeeded';
                    $task['stage'] = 'awaiting_confirmation';
                } catch (\Throwable $e) {
                    $task['state'] = 'needs_attention';
                    $task['error'] = $e instanceof ManagementException && str_starts_with($e->getMessage(), 'UPDATE_') ? $e->getMessage() : 'UPDATE_PREPARATION_FAILED';
                }
                $task['events'][] = ['plugin' => $task['plugin'], 'stage' => $task['stage'], 'state' => $task['state'], 'at' => time()];
                $this->manager->store->put('tasks', $id, $task, $revision);
                return false; // only a ZIP was staged; no plugin code was activated and no restart is needed
            }
            $current = $task['kind'] === 'uninstall' ? $task['plugin'] : array_values(array_diff(array_keys($task['plan']['steps']), $task['completed']))[0] ?? null;
            if (!$current) {
                $task['state'] = 'succeeded';
                $this->manager->store->put('tasks', $id, $task, $row['revision']);
                return true;
            }
            try {
                if ($task['kind'] === 'install') {
                    $expected = $task['plan']['steps'][$current];
                    if (!hash_equals($expected['fingerprint'], $this->manager->fingerprint($this->manager->packagePath($current)))) throw new ManagementException('PACKAGE_CHANGED');
                    // Re-solve before every step; previous steps may have installed shared prerequisites.
                    $resolved = $this->manager->plan($task['plan']['root']);
                    if ($resolved['selected'] != $task['plan']['selected']) throw new ManagementException('DEPENDENCY_PLAN_CHANGED');
                    $this->manager->assertPlanStep($current, $expected);
                } else {
                    $this->manager->assertRemovable($current);
                    if (($this->manager->store->get('installations', $current)['revision'] ?? null) !== $task['installationRevision']) throw new ManagementException('INSTALLATION_CHANGED');
                }
                $task['state'] = 'running';
                $task['current'] = $current;
                $task['stage'] = 'preflight';
                $revision = $this->manager->store->put('tasks', $id, $task, $row['revision']);
                $progress = function (string $stage) use ($id, $current, &$task, &$revision): void {
                    if (!in_array($stage, ['files', 'database', 'seed', 'settings', 'libraries', 'backup'], true)) throw new ManagementException('Unknown installation stage');
                    $task['stage'] = $stage;
                    $task['events'][] = ['plugin' => $current, 'stage' => $stage, 'at' => time()];
                    $task['events'] = array_slice($task['events'], -200);
                    $revision = $this->manager->store->put('tasks', $id, $task, $revision);
                };
                // Do not retain echo output: SQL exceptions and third-party scripts may contain secrets.
                ob_start();
                try {
                    $result = $task['kind'] === 'install' ? $install($current, $expected, $progress) : $uninstall($current, $progress);
                } finally {
                    ob_end_clean();
                }
                if ($task['kind'] === 'install' && (int) ($result['state'] ?? 1) === 4) {
                    $task['state'] = 'waiting_dependencies';
                } else {
                    if ($task['kind'] === 'uninstall' && $result) $task['warnings'] = $result;
                    $task['completed'][] = $current;
                    $task['state'] = $task['kind'] === 'uninstall' || count($task['completed']) === count($task['plan']['steps']) ? 'succeeded' : 'queued';
                }
                $task['events'][] = ['plugin' => $current, 'state' => $task['state'], 'at' => time()];
                $this->manager->store->put('tasks', $id, $task, $revision);
                return true;
            } catch (\Throwable $e) {
                $latest = $this->manager->store->get('tasks', $id);
                $task = $latest['value'];
                $task['state'] = 'needs_attention';
                $task['error'] = isset($revision) ? 'INSTALLATION_STEP_FAILED' : 'PREFLIGHT_FAILED';
                if ($e instanceof ManagementException && str_starts_with($e->getMessage(), 'UPDATE_')) $task['error'] = $e->getMessage();
                $task['current'] = $current;
                $task['events'][] = ['plugin' => $current, 'state' => $task['state'], 'at' => time()];
                $this->manager->store->put('tasks', $id, $task, $latest['revision']);
                return true; // recycle loaded migration classes before an operator retries a corrected package
            }
        }
        return false;
    }

    /** Operator inspects actual files/database first; retry never silently repeats uncertain SQL. */
    public function recover(string $id, string $action, int $expected, int $actor): void
    {
        $lock = $this->manager->lock();
        $row = $this->manager->store->get('tasks', $id);
        if (!$row || $row['revision'] !== $expected) throw new ManagementException('Revision conflict', 409);
        $task = $row['value'];
        if (!in_array($task['state'], ['needs_attention', 'waiting_dependencies'], true)) throw new ManagementException('Task does not require recovery');
        if (!in_array($action, ['retry', 'accept_completed', 'cancel'], true)) throw new ManagementException('Invalid recovery action');
        if ($action === 'retry' && $task['state'] === 'waiting_dependencies') throw new ManagementException('Complete library installation, then accept the completed step');
        if ($action === 'retry' && $task['kind'] === 'uninstall') {
            $installation = $this->manager->store->get('installations', $task['plugin']);
            if (!$installation || !isset($task['installationFingerprint']) || ($installation['value']['fingerprint'] ?? null) !== $task['installationFingerprint']
                || !in_array($installation['value']['state'], ['installed', 'failed', 'uninstalling', 'waiting_dependencies'], true)) throw new ManagementException('Installation changed; cancel and review a new uninstall task', 409);
            // Failure-state writes increment the revision; retry still targets the exact same installation.
            $task['installationRevision'] = $installation['revision'];
        }
        if ($action === 'accept_completed') {
            if ($task['kind'] === 'prepare_update') throw new ManagementException('Retry or cancel the preparation task');
            $item = $this->manager->store->get('installations', $task['current'])['value'] ?? [];
            $expectedState = $task['kind'] === 'uninstall' ? 'uninstalled' : 'installed';
            if (($item['state'] ?? '') !== $expectedState) throw new ManagementException('Installation has not reached the expected state');
            if ($task['kind'] === 'install' && $item['manifest']['version'] !== $task['plan']['steps'][$task['current']]['manifest']['version']) throw new ManagementException('Installed version differs from plan');
            if ($task['kind'] === 'install' && ($item['fingerprint'] ?? null) !== $task['plan']['steps'][$task['current']]['fingerprint']) throw new ManagementException('Installed package differs from plan');
            $task['completed'][] = $task['current'];
        }
        $task['state'] = $action === 'cancel' ? 'cancelled' : (($task['kind'] === 'uninstall' && $action === 'accept_completed') ? 'succeeded' : 'queued');
        $task['events'][] = ['action' => $action, 'actor' => $actor, 'at' => time()];
        unset($task['error']);
        $this->manager->store->put('tasks', $id, $task, $expected);
    }
}
