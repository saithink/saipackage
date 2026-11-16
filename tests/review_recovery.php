<?php
/** Isolated MySQL/PostgreSQL only. Run each phase in a fresh PHP process. */
require '/app/server/vendor/autoload.php';
require '/app/server/support/bootstrap.php';
use Saithink\Saipackage\management\{Factory, Tasks};
use plugin\saipackage\app\logic\InstallLogic;
use think\facade\Db;
$manager = Factory::manager(); $lock = $manager->lock(); $tasks = new Tasks($manager);
$assert = static function (bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; };
$upload = static function (string $name): void { $path = tempnam(sys_get_temp_dir(), 'review-') . '.zip'; copy(__DIR__ . '/fixtures/' . $name . '.zip', $path); (new InstallLogic())->uploadFromPath($path); };
$submit = static fn ($id) => $tasks->submit($id, $manager->plan($id)['fingerprint'], 1);
$last = static function (string $plugin) use ($manager): array {
    $found = [];
    foreach ($manager->store->all('tasks') as $id => $row) if (($row['value']['plan']['root'] ?? $row['value']['plugin'] ?? '') === $plugin && !in_array($row['value']['state'], ['succeeded','cancelled'], true)) $found = [$id, $row];
    if (!$found) throw new RuntimeException('Missing review task'); return $found;
};
$tick = static function (string $failStage = '') use ($tasks): bool {
    return $tasks->tick(static function ($id, $snapshot, $progress) use ($failStage) {
        return (new InstallLogic($id))->install(false, $snapshot, static function ($stage) use ($progress, $failStage) {
            $progress($stage); if ($stage === $failStage) throw new RuntimeException('fixture crash after DDL');
        });
    }, fn ($id, $progress) => (new InstallLogic($id))->uninstall(false, $progress));
};
$phase = $argv[1] ?? 'install';
if ($phase === 'install') {
    foreach (['managed_review','managed_config'] as $plugin) {
        $upload($plugin . '-1.0.0'); $id = $submit($plugin); $tick();
        $assert($manager->store->get('tasks', $id)['value']['state'] === 'succeeded', "$plugin installs from ZIP on clean host");
    }
    $schema = $manager->installed()['managed_config']['schema'];
    $manager->settings->save('managed_config', $schema, 2, ['endpoint'=>'http://fixture'], ['apiKey'=>['action'=>'replace','value'=>'review-secret']], 0, 1);
    try { $manager->store->transaction(fn () => $manager->store->put('packages','managed_config',[],0)); throw new RuntimeException('duplicate write was accepted'); }
    catch (\Saithink\Saipackage\management\ManagementException $e) { $assert($e->getCode()===409, 'transactional duplicate insert reports revision conflict on either database'); }
} elseif ($phase === 'fail_upgrade') {
    $upload('managed_review-1.1.0'); $id = $submit('managed_review'); $tick('settings');
    $row = $manager->store->get('tasks', $id);
    $assert($row['value']['state'] === 'needs_attention' && $row['value']['stage'] === 'settings', 'failure after DDL remains inspectable');
    $assert(!empty((new InstallLogic('managed_review'))->getInfo()['update']), 'failed upgrade preserves update marker');
    $assert(Db::table('managed_review_items')->where('marker','seed-once')->count() === 1, 'failure did not reseed');
} elseif ($phase === 'retry_upgrade') {
    [$id, $row] = $last('managed_review'); $tasks->recover($id, 'retry', $row['revision'], 1); $tick();
    $assert($manager->store->get('tasks', $id)['value']['state'] === 'succeeded', 'inspected failed upgrade retries successfully');
    $assert(Db::table('managed_review_items')->where('marker','seed-once')->count() === 1, 'retry never runs first-install seed again');
    $assert(!is_dir(InstallLogic::frontendDir() . '/src/views/plugin/managed_review'), 'removed frontend subtree does not survive upgrade');
} elseif ($phase === 'finalize_interrupted') {
    $snapshot = $manager->installed()['managed_review'];
    $manager->begin('managed_review', $snapshot); $manager->mark('managed_review','failed');
    $id = $submit('managed_review'); $tick();
    $task = $manager->store->get('tasks',$id)['value'];
    $assert($task['state'] === 'succeeded', 'retry finalizes info.ini=installed after registry-write interruption');
    $stages = array_column($task['events'], 'stage');
    $assert(!in_array('database',$stages,true) && !in_array('files',$stages,true), 'finalization never repeats SQL or file replacement');
    $assert(Db::table('managed_review_items')->where('marker','seed-once')->count() === 1, 'finalization preserves original seed count');
} elseif ($phase === 'fail_uninstall') {
    touch(runtime_path() . '/review-rollback-fail'); $id = $tasks->submitUninstall('managed_review',1); $tick();
    $assert($manager->store->get('tasks',$id)['value']['state'] === 'needs_attention', 'rollback failure is recorded');
    $assert($manager->installed()['managed_review']['state'] === 'failed', 'rollback failure blocks business');
    unlink(runtime_path() . '/review-rollback-fail');
} elseif ($phase === 'retry_uninstall') {
    [$id, $row] = $last('managed_review'); $tasks->recover($id,'retry',$row['revision'],1); $tick();
    $assert($manager->store->get('tasks',$id)['value']['state'] === 'succeeded', 'rollback retry accepts own state-revision changes');
    $assert($manager->store->get('installations','managed_review')['value']['state'] === 'uninstalled', 'uninstall retry finishes cleanup');
} elseif ($phase === 'config_downgrade') {
    $upload('managed_config-1.1.0'); $id=$submit('managed_config'); $tick();
    $row=$manager->store->get('tasks',$id);
    $assert($row['value']['state'] === 'needs_attention', 'settings version downgrade rejected');
    $assert(trim(file_get_contents(base_path().'/plugin/managed_config/release.txt')) === '1.0.0', 'downgrade rejection precedes file replacement');
    $assert(!isset(Db::connect()->getFields('managed_config_items')['note']), 'downgrade rejection precedes DDL');
    $tasks->recover($id,'cancel',$row['revision'],1);
} elseif ($phase === 'config_migration_fail') {
    $upload('managed_config-1.2.0'); touch(runtime_path().'/review-migration-fail'); $id=$submit('managed_config'); $tick();
    $assert($manager->store->get('tasks',$id)['value']['state'] === 'needs_attention', 'migration exception is recoverable');
    $assert($manager->settings->raw('managed_config')['version'] === 2, 'failed preview preserves old encrypted settings');
    $assert(trim(file_get_contents(base_path().'/plugin/managed_config/release.txt')) === '1.0.0', 'failed migration preview precedes files');
    $assert(!isset(Db::connect()->getFields('managed_config_items')['note']), 'failed migration preview precedes DDL');
    unlink(runtime_path().'/review-migration-fail');
} elseif ($phase === 'config_migration_commit_fail') {
    [$id,$row]=$last('managed_config'); $tasks->recover($id,'retry',$row['revision'],1); $tick('libraries');
    $assert($manager->store->get('tasks',$id)['value']['state'] === 'needs_attention', 'failure after settings commit remains inspectable');
    $assert($manager->settings->raw('managed_config')['version'] === 3, 'target settings committed before library failure');
    // This phase now acts as a separate HTTP operator; do not retain the CLI mutation lock.
    $lock = null;
    $token=\Tinywan\Jwt\JwtToken::generateToken(['access_exp'=>600,'id'=>1,'username'=>'admin','type'=>'pc','plat'=>'saiadmin'])['access_token'];
    $curl=curl_init('http://127.0.0.1:8787/tool/install/management/settings?appName=managed_config');
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token]]);
    $view=json_decode(curl_exec($curl),true);
    $assert($view['code']===200 && $view['data']['settingsVersion']===3 && isset($view['data']['schema']['properties']['address']), 'failed upgrade configuration API uses target contract');
    $assert(!str_contains(json_encode($view),'review-secret'), 'failed upgrade configuration API redacts secret');
    curl_setopt_array($curl,[CURLOPT_URL=>'http://127.0.0.1:8787/tool/install/management/saveSettings',CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['appName'=>'managed_config','revision'=>$view['data']['revision'],'values'=>$view['data']['values'],'secretActions'=>[]])]);
    $saved=json_decode(curl_exec($curl),true);
    $assert($saved['code']===200 && $manager->settings->raw('managed_config')['version']===3, 'operator can edit failed target settings without downgrading version');
    $lock = $manager->lock();
} elseif ($phase === 'config_migration_retry') {
    [$id,$row]=$last('managed_config'); $tasks->recover($id,'retry',$row['revision'],1); $tick();
    $assert($manager->store->get('tasks',$id)['value']['state'] === 'succeeded', 'fixed configuration migration retries successfully');
    $raw=$manager->settings->raw('managed_config');
    $assert($raw['version']===3 && $raw['values']['address']==='http://fixture' && $raw['values']['apiKey']==='review-secret', 'renamed field and encrypted secret survive upgrade');
    $assert(!str_contains(json_encode($manager->settings->view('managed_config',$manager->installed()['managed_config']['schema'])), 'review-secret'), 'migrated secret remains redacted');
} elseif ($phase === 'missing_staging_upgrade') {
    \Saithink\Saipackage\service\Filesystem::delDir($manager->packagePath('managed_config'));
    $upload('managed_config-1.3.0');
    $assert(!empty((new InstallLogic('managed_config'))->getInfo()['update']), 'installed registry identifies upgrade even when staging was lost');
    $id=$submit('managed_config'); $tick();
    $assert($manager->store->get('tasks',$id)['value']['state'] === 'succeeded', 'upgrade recovers missing staging record');
    $assert(Db::table('managed_config_items')->where('marker','seed-once')->count() === 1, 'missing staging does not cause a first-install seed');
} elseif ($phase === 'legacy_sql_upgrade') {
    if (\plugin\saiadmin\utils\DbType::isPgsql()) { echo "SKIP SQL-only fixture on PostgreSQL\n"; exit; }
    $upload('managed_sql-1.0.0'); $id=$submit('managed_sql'); $tick();
    $assert($manager->store->get('tasks',$id)['value']['state']==='succeeded','SQL-only first install succeeds');
    $upload('managed_sql-1.1.0'); $id=$submit('managed_sql'); $tick();
    $assert($manager->store->get('tasks',$id)['value']['state']==='succeeded','SQL-only upgrade never repeats CREATE TABLE from install.sql');
    $assert(Db::table('managed_sql_items')->count()===2,'SQL-only upgrade preserves old row and applies update.sql once');
}
