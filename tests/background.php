<?php
require '/app/server/vendor/autoload.php';
require '/app/server/support/bootstrap.php';
use Saithink\Saipackage\management\{Factory, Tasks};
use plugin\saipackage\app\logic\InstallLogic;

$manager = Factory::manager();
if (($argv[1] ?? '') === 'enqueue') {
    $lock = $manager->lock();
    $copy = runtime_path() . '/saipackage/background-fixture.zip';
    copy(__DIR__ . '/fixtures/managed_async-1.0.0.zip', $copy);
    (new InstallLogic())->uploadFromPath($copy);
    $plan = $manager->plan('managed_async');
    $id = (new Tasks($manager))->submit('managed_async', $plan['fingerprint'], 1);
    file_put_contents(__DIR__ . '/background-task-id', $id);
    echo "PASS background task queued without running it in the request\n";
} else {
    $id = file_get_contents(__DIR__ . '/background-task-id');
    $deadline = microtime(true) + 20;
    do {
        $row = $manager->store->get('tasks', $id);
        if ($row['value']['state'] === 'succeeded') {
            $stages = array_column($row['value']['events'], 'stage');
            if (!in_array('database', $stages, true)) throw new RuntimeException('Missing persisted database stage');
            echo "PASS real worker completed task and persisted stage logs\n";
            exit(0);
        }
        if ($row['value']['state'] === 'needs_attention') throw new RuntimeException('Background task failed');
        usleep(200000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Background worker did not finish in 20 seconds');
}
