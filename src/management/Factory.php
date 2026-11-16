<?php
namespace Saithink\Saipackage\management;

use think\facade\Db;

final class Factory
{
    public static function enabled(): bool
    {
        return (bool) config('plugin.saipackage.management.enabled', false);
    }

    public static function manager(): Manager
    {
        $connection = Db::connect();
        $connection->query('SELECT 1'); // ThinkORM creates its PDO lazily.
        $store = new Store($connection->getPdo(), (string) config('plugin.saipackage.management.table_prefix', ''));
        $settings = new Settings($store, (string) config('plugin.saipackage.management.settings_key', ''));
        return new Manager($store, $settings, runtime_path() . '/saipackage', [
            'php' => PHP_VERSION, 'saiadmin' => (string) config('plugin.saiadmin.app.version', ''),
            'saipackage' => (string) config('plugin.saipackage.app.version', ''),
        ], (array) config('plugin.saipackage.management.settings_migrations', []));
    }

    public static function services(Manager $manager): Services
    {
        $probes = ['json-health' => JsonHealthProbe::class] + (array) config('plugin.saipackage.management.probes', []);
        foreach ($manager->installed() as $id => $item) {
            foreach ((array) config('plugin.' . $id . '.saipackage.probes', []) as $key => $probe) {
                $probes[$id . ':' . $key] = $probe;
            }
        }
        return new Services($manager->store, $manager->settings, $probes);
    }

    public static function updates(Manager $manager): Updates
    {
        return new Updates($manager, (array) config('plugin.saipackage.management.updates', []));
    }
}
