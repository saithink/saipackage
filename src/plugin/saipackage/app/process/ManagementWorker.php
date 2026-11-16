<?php
namespace plugin\saipackage\app\process;

use Saithink\Saipackage\management\Factory;
use Saithink\Saipackage\management\Tasks;
use Workerman\Timer;
use plugin\saipackage\app\logic\InstallLogic;

final class ManagementWorker
{
    public function onWorkerStart(): void
    {
        if (!Factory::enabled()) return;
        $nextHealth = [];
        Timer::add(2, function () use (&$nextHealth): void {
            try {
                $manager = Factory::manager();
                $changed = (new Tasks($manager))->tick(
                    fn (string $id, array $snapshot, callable $progress) => (new InstallLogic($id))->install(false, $snapshot, $progress),
                    fn (string $id, callable $progress) => (new InstallLogic($id))->uninstall(false, $progress),
                    fn (array $task) => Factory::updates($manager)->prepare($task),
                );
                if ($changed) {
                    InstallLogic::restartServer();
                    return;
                }
                Factory::updates($manager)->tick();
                foreach ($manager->installed() as $id => $item) {
                    if ($item['state'] === 'installed' && !empty($item['manifest']['externalServices']) && time() >= ($nextHealth[$id] ?? 0)) {
                        $nextHealth[$id] = time() + max(10, (int) config('plugin.saipackage.management.health_interval', 60));
                        try {
                            Factory::services($manager)->check($id, $item['manifest'], $item['schema']);
                        } catch (\Throwable) {
                            // Missing configuration is represented by RuntimeGuard; never log secrets.
                        }
                        break; // bounded work per poll; adapters must also enforce their request timeout
                    }
                }
            } catch (\Throwable) {
                // Do not leak credentials from database/client exception messages into logs.
                \support\Log::warning('saipackage management poll failed; inspect setup and task state');
            }
        });
    }
}
