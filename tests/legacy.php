<?php
require '/app/server/vendor/autoload.php';
require '/app/server/support/bootstrap.php';
use Saithink\Saipackage\management\{Factory, Tasks, RuntimeGuard};
use plugin\saipackage\app\logic\InstallLogic;

$manager = Factory::manager();
$lock = $manager->lock();
$source = __DIR__ . '/fixtures/labphinx-1.0.0.zip';
if (!is_file($source)) throw new RuntimeException('Supply the legacy labphinx fixture from saiadmin6-plugin/build_example.py');
$copy = runtime_path() . '/saipackage/legacy-fixture.zip';
copy($source, $copy);
(new InstallLogic())->uploadFromPath($copy);
$plan = $manager->plan('labphinx');
$task = (new Tasks($manager))->submit('labphinx', $plan['fingerprint'], 1);
(new Tasks($manager))->tick(fn ($id, $snapshot, $progress) => (new InstallLogic($id))->install(false, $snapshot, $progress), fn () => null);
if ($manager->store->get('tasks', $task)['value']['state'] !== 'succeeded') throw new RuntimeException('Legacy fixture did not install');
if (!(new RuntimeGuard($manager->store, $manager->settings))->status('labphinx')['ready']) throw new RuntimeException('Legacy fixture is not ready');
echo "PASS legacy ZIP without manifest/schema installs and remains available\n";
