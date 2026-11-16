<?php
/** Run inside a disposable official SaiAdmin host after core seeds and saipackage:setup. */
require '/app/server/vendor/autoload.php';
require '/app/server/support/bootstrap.php';

use Saithink\Saipackage\management\{Factory, Tasks, RuntimeGuard};
use plugin\saipackage\app\logic\InstallLogic;
use think\facade\Db;

$manager = Factory::manager();
$lock = $manager->lock(); // prevent the real worker from racing this logic-level test
$tasks = new Tasks($manager);
$guard = new RuntimeGuard($manager->store, $manager->settings);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
};
$upload = static function (string $name): void {
    $temporary = tempnam(sys_get_temp_dir(), 'fixture-');
    unlink($temporary);
    $copy = $temporary . '.zip';
    copy('/app/server/tests/fixtures/' . $name . '.zip', $copy);
    (new InstallLogic())->uploadFromPath($copy);
};
$tick = static fn () => $tasks->tick(fn ($id, $snapshot, $progress) => (new InstallLogic($id))->install(false, $snapshot, $progress), fn ($id, $progress) => (new InstallLogic($id))->uninstall(false, $progress));
$submit = static function (string $id) use ($manager, $tasks): string {
    $plan = $manager->plan($id);
    return $tasks->submit($id, $plan['fingerprint'], 1);
};
$expectFailure = static function (callable $action, string $message) use ($assert): void {
    try { $action(); } catch (Throwable) { $assert(true, $message); return; }
    $assert(false, $message);
};
$action = $argv[1] ?? 'install';
if ($action === 'direct') {
    $upload('managed_dep-1.0.0');
    $upload('managed_ocr-1.1.0');
    $id = $submit('managed_ocr'); $tick(); $tick();
    $assert($manager->store->get('tasks', $id)['value']['state'] === 'succeeded', 'latest version installs directly on a fresh host');
    $assert(isset(Db::connect()->getFields('managed_ocr_items')['note']), 'latest schema includes upgrade column without an old install');
    $assert($manager->settings->raw('managed_ocr')['revision'] === 0, 'fresh host has no retained settings');
    $assert($guard->status('managed_ocr')['status'] === 'settings_missing', 'fresh latest install awaits user configuration');
} elseif ($action === 'install' || $action === 'postinstall') {
  if ($action === 'install') {
    $upload('managed_ocr-1.0.0');
    $expectFailure(fn () => $manager->plan('managed_ocr'), 'missing dependency blocked');
    $upload('managed_dep-1.0.0');
    $plan = $manager->plan('managed_ocr');
    $assert(array_keys($plan['steps']) === ['managed_dep', 'managed_ocr'], 'dependency installation order');
    $id = $tasks->submit('managed_ocr', $plan['fingerprint'], 1);
    $tick(); $tick();
    $assert($manager->store->get('tasks', $id)['value']['state'] === 'succeeded', 'ZIP install task succeeded');
  }
    $assert($guard->status('managed_ocr')['status'] === 'settings_missing', 'external service absent does not fail install');
    $assert($guard->status('managed_dep')['ready'], 'dependency available');
    $expectFailure(fn () => $manager->assertRemovable('managed_dep'), 'reverse dependency uninstall protection');
    $expectFailure(fn () => $manager->enable('managed_dep', false), 'reverse dependency disable protection');
    $expectFailure(fn () => $upload('managed_ocr-1.0.0'), 'same version ZIP refused');
    Db::table('managed_ocr_items')->insert(['marker' => 'retained-across-upgrade']);
    $schema = $manager->store->get('installations', 'managed_ocr')['value']['schema'];
    $manager->settings->save('managed_ocr', $schema, 1, ['ocr' => ['endpoint' => 'http://127.0.0.1:18999']], ['ocr.apiKey' => ['action' => 'replace', 'value' => 'fixture-secret']], 0, 1);
    $assert($guard->status('managed_ocr')['status'] === 'settings_unverified', 'saved settings require verification');
} elseif ($action === 'upgrade') {
    $upload('managed_ocr-1.1.0');
    $assert($manager->store->get('installations', 'managed_ocr')['value']['manifest']['version'] === '1.0.0', 'upload preserves installed version');
    $id = $submit('managed_ocr'); $tick();
    $assert($manager->store->get('tasks', $id)['value']['state'] === 'succeeded', 'upgrade task succeeded');
    $assert(Db::table('managed_ocr_items')->where('marker', 'retained-across-upgrade')->count() === 1, 'upgrade preserves business data');
    $assert(!file_exists(base_path() . '/plugin/managed_ocr/obsolete.txt'), 'upgrade removes obsolete file');
    $assert($manager->settings->raw('managed_ocr')['revision'] === 1, 'upgrade preserves settings');
} elseif ($action === 'uninstall') {
    $settingsRevision = $manager->settings->raw('managed_ocr')['revision'];
    $id = $tasks->submitUninstall('managed_ocr', 1); $tick();
    $assert($manager->store->get('tasks', $id)['value']['state'] === 'succeeded', 'uninstall task succeeded');
    $assert($manager->settings->raw('managed_ocr')['revision'] === $settingsRevision, 'uninstall retains settings');
    $upload('managed_ocr-1.1.0'); $id = $submit('managed_ocr'); $tick();
    $assert($manager->store->get('tasks', $id)['value']['state'] === 'succeeded', 'uninstall then reinstall');
    $assert($manager->settings->raw('managed_ocr')['revision'] === $settingsRevision, 'reinstall reuses settings');
} elseif ($action === 'failure') {
    $upload('managed_fail-1.1.0'); $id = $submit('managed_fail'); $tick();
    $row = $manager->store->get('tasks', $id);
    $assert($row['value']['state'] === 'needs_attention', 'failed migration needs inspection');
    $assert(!$guard->status('managed_fail')['ready'], 'partial install blocked by runtime guard');
    $tasks->recover($id, 'cancel', $row['revision'], 1);
    $upload('managed_fail-1.1.1'); $id = $submit('managed_fail');
    $assert($manager->store->get('tasks', $id)['value']['state'] === 'queued', 'corrected package queued for a fresh worker');
} elseif ($action === 'recover_failure') {
    $id = '';
    foreach ($manager->store->all('tasks') as $key => $row) {
        if (($row['value']['plan']['root'] ?? '') === 'managed_fail' && $row['value']['state'] !== 'cancelled') {
            $id = $key;
            if ($row['value']['state'] === 'needs_attention') $tasks->recover($id, 'retry', $row['revision'], 1);
        }
    }
    $tick();
    $assert($manager->store->get('tasks', $id)['value']['state'] === 'succeeded', 'corrected package recovers failed install');
}
