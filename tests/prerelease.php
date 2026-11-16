<?php
require '/app/server/vendor/autoload.php';
require '/app/server/support/bootstrap.php';
use Saithink\Saipackage\management\{Factory, Tasks};
use plugin\saipackage\app\logic\InstallLogic;
$manager = Factory::manager(); $lock = $manager->lock();
foreach (['0.9.0-beta.1', '0.9.0'] as $version) {
    $copy = runtime_path() . '/saipackage/prerelease-fixture.zip';
    copy(__DIR__ . '/fixtures/managed_pre-' . $version . '.zip', $copy);
    (new InstallLogic())->uploadFromPath($copy);
    $plan = $manager->plan('managed_pre');
    $id = (new Tasks($manager))->submit('managed_pre', $plan['fingerprint'], 1);
    (new Tasks($manager))->tick(fn ($app, $snapshot, $progress) => (new InstallLogic($app))->install(false, $snapshot, $progress), fn () => null);
    if ($manager->store->get('tasks', $id)['value']['state'] !== 'succeeded') throw new RuntimeException('Prerelease transition failed');
}
echo "PASS SemVer prerelease upgrades to stable without increasing the patch version\n";
