<?php
namespace Saithink\Saipackage\management;

use RuntimeException;
use Saithink\Saipackage\service\Server;

final class Manager
{
    public function __construct(public readonly Store $store, public readonly Settings $settings, private string $packageRoot, private array $host = [], private array $settingsMigrations = [])
    {
    }

    public function previewSettings(array $snapshot): ?array
    {
        $id = $snapshot['manifest']['id'];
        $previous = $this->store->get('installations', $id)['value']['schema'] ?? [];
        $migration = $this->settingsMigrations[$id] ?? null;
        return $this->settings->prepareMigration($id, $snapshot['schema'], (int) ($snapshot['manifest']['settingsVersion'] ?? 1), is_callable($migration) ? $migration : null, $previous);
    }

    public function lock(): MutationLock
    {
        return new MutationLock($this->packageRoot);
    }

    public function updateDirectory(): string
    {
        $path = rtrim($this->packageRoot, '/\\') . '/.updates';
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new ManagementException('Cannot create update cache directory');
        return $path;
    }

    public function resolveManifest(array $manifest): array
    {
        $packages = [];
        foreach ($this->store->all('packages') as $id => $row) $packages[$id] = $row['value'] + ['source' => 'package'];
        $packages[$manifest['id']] = ['manifest' => $manifest, 'source' => 'package'];
        return (new DependencyResolver())->resolve($manifest['id'], $this->installed(), $packages, $this->host);
    }

    public function environmentFingerprint(): string
    {
        $state = [];
        foreach ($this->store->all('installations') as $id => $row) $state['installed'][$id] = $row['revision'];
        foreach ($this->store->all('packages') as $id => $row) $state['packages'][$id] = $row['value']['fingerprint'];
        return hash('sha256', json_encode([$this->host, $state], JSON_THROW_ON_ERROR));
    }

    public function installed(): array
    {
        $result = [];
        foreach ($this->store->all('installations') as $id => $row) {
            if ($row['value']['state'] !== 'uninstalled') $result[$id] = array_replace($row['value'], ['source' => 'installed']);
        }
        return $result;
    }

    public function packagePath(string $id): string
    {
        return rtrim($this->packageRoot, '/\\') . '/' . Manifest::id($id) . '/';
    }

    public function snapshot(string $directory, array $info): array
    {
        $manifest = Manifest::read($directory, $info);
        $schema = Manifest::schema($directory);
        $this->settings->fields($schema);
        $docs = [];
        foreach (['deployment.md', 'compose.example.yaml'] as $name) {
            $path = $directory . '/docs/' . $name;
            if (is_file($path) && !is_link($path) && filesize($path) <= 262144) $docs[$name] = file_get_contents($path);
        }
        return ['manifest' => $manifest, 'schema' => $schema, 'docs' => $docs, 'uninstallSql' => is_file($directory . '/uninstall.sql') ? file_get_contents($directory . '/uninstall.sql') : null, 'fingerprint' => $this->fingerprint($directory)];
    }

    public function fingerprint(string $directory): string
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) throw new ManagementException('Package symlinks are unsupported');
            if ($file->isFile()) {
                $relative = substr($file->getPathname(), strlen(rtrim($directory, '/\\')) + 1);
                // info.ini contains installer-owned mutable state. Hash its release identity only.
                $files[$relative] = $relative === 'info.ini'
                    ? hash('sha256', json_encode(array_intersect_key(Server::getIni(rtrim($directory, '/\\') . '/'), array_flip(['app', 'version', 'support', 'title', 'author', 'about']))))
                    : hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);
        return hash('sha256', json_encode($files));
    }

    /** Run once during setup and before replacing a legacy installation's staging directory. */
    public function importLegacy(): void
    {
        foreach (Server::installedList(rtrim($this->packageRoot, '/\\') . '/') as $info) {
            $id = Manifest::id($info['app']);
            if ($this->store->get('installations', $id) || (int) ($info['state'] ?? 0) !== 1) continue;
            $snapshot = $this->snapshot($this->packagePath($id), $info);
            $this->recordInstalled($id, $snapshot, true);
        }
    }

    public function registerPackage(string $id): array
    {
        $path = $this->packagePath($id);
        $snapshot = $this->snapshot($path, Server::getIni($path));
        $old = $this->store->get('packages', $id);
        $this->store->put('packages', $id, $snapshot + ['source' => 'package'], $old['revision'] ?? 0);
        return $snapshot;
    }

    public function plan(string $id): array
    {
        $packages = [];
        foreach (Server::installedList(rtrim($this->packageRoot, '/\\') . '/') as $info) {
            $packageId = Manifest::id($info['app']);
            $installation = $this->store->get('installations', $packageId)['value'] ?? [];
            $needsFinalization = (int) ($info['state'] ?? 0) === 1 && ($installation['state'] ?? 'installed') !== 'installed' && isset($installation['pending']);
            if (in_array((int) ($info['state'] ?? 0), [2, 3, 4], true) || $needsFinalization) {
                $packages[$packageId] = $this->registerPackage($packageId) + ['source' => 'package'];
            }
        }
        $plan = (new DependencyResolver())->resolve($id, $this->installed(), $packages, $this->host);
        $plan['changes'] = [];
        foreach ($plan['steps'] as $plugin => $item) {
            $previous = $this->store->get('installations', $plugin)['value'] ?? null;
            if ($previous && $previous['state'] === 'installed' && !version_compare($item['manifest']['version'], $previous['manifest']['version'], '>')) {
                throw new ManagementException('Upgrade must increase installed version: ' . $plugin);
            }
            $plan['changes'][$plugin] = ['from' => $previous['manifest']['version'] ?? null, 'to' => $item['manifest']['version']];
        }
        $state = [];
        foreach ($this->store->all('installations') as $plugin => $row) $state[$plugin] = $row['revision'];
        $plan['installationRevisions'] = $state;
        $plan['fingerprint'] = hash('sha256', json_encode($plan));
        return $plan;
    }

    public function assertPlanStep(string $id, array $snapshot): array
    {
        if ($snapshot['manifest']['id'] !== $id || !hash_equals($snapshot['fingerprint'], $this->fingerprint($this->packagePath($id)))) throw new ManagementException('Package changed');
        foreach ($snapshot['manifest']['requiresPlugins'] ?? [] as $dep => $constraint) {
            $installed = $this->store->get('installations', $dep)['value'] ?? null;
            if (!$installed || $installed['state'] !== 'installed' || !Manifest::matches($installed['manifest']['version'], $constraint)) throw new ManagementException('Required plugin not installed: ' . $dep);
        }
        return $snapshot;
    }

    public function assertSingleInstall(string $id): array
    {
        $plan = $this->plan($id);
        if (array_keys($plan['steps']) !== [$id]) throw new ManagementException('Use the dependency installation plan before installing this plugin');
        return $plan['steps'][$id];
    }

    public function recordInstalled(string $id, array $snapshot, ?bool $enabled = null): void
    {
        $old = $this->store->get('installations', $id);
        $this->store->transaction(function () use ($id, $snapshot, $enabled, $old): void {
            $this->store->put('installations', $id, array_replace($snapshot, ['state' => 'installed', 'enabled' => $enabled ?? ($old['value']['enabled'] ?? true), 'source' => 'installed']), $old['revision'] ?? 0);
            $deps = $this->store->get('dependencies', $id);
            $this->store->put('dependencies', $id, ['requires' => $snapshot['manifest']['requiresPlugins'] ?? []], $deps['revision'] ?? 0);
        });
    }

    public function begin(string $id, array $snapshot): void
    {
        $old = $this->store->get('installations', $id);
        $value = $old && $old['value']['state'] !== 'uninstalled' ? $old['value'] : $snapshot + ['enabled' => true, 'source' => 'installed'];
        $value['state'] = $old && $old['value']['state'] !== 'uninstalled' ? 'upgrading' : 'installing';
        $value['pending'] = $snapshot;
        $this->store->put('installations', $id, $value, $old['revision'] ?? 0);
    }

    public function mark(string $id, string $state): void
    {
        $old = $this->store->get('installations', $id);
        if (!$old) return;
        $value = $old['value'];
        $value['state'] = $state;
        $this->store->put('installations', $id, $value, $old['revision']);
    }

    public function assertRemovable(string $id, bool $disable = false): void
    {
        (new DependencyResolver())->assertRemovable($id, $this->installed(), $disable);
    }

    public function discardPackage(string $id): void
    {
        $row = $this->store->get('packages', Manifest::id($id));
        if ($row) $this->store->remove('packages', $id, $row['revision']);
    }

    public function enable(string $id, bool $enabled): void
    {
        $lock = $this->lock();
        $old = $this->store->get('installations', Manifest::id($id));
        if (!$old || $old['value']['state'] !== 'installed') throw new ManagementException('Plugin is not installed');
        if (!$enabled) $this->assertRemovable($id, true);
        $value = $old['value'];
        $value['enabled'] = $enabled;
        $this->store->transaction(function () use ($id, $enabled, $value, $old): void {
            $this->store->put('installations', $id, $value, $old['revision']);
            if ($enabled) (new RuntimeGuard($this->store, $this->settings))->assertReady($id);
        });
    }

    public function catalog(): array
    {
        $result = [];
        $guard = new RuntimeGuard($this->store, $this->settings);
        foreach ($this->store->all('installations') as $id => $row) {
            $result[$id] = ['manifest' => $row['value']['manifest'], 'state' => $guard->status($id), 'revision' => $row['revision']];
        }
        foreach ($this->store->all('packages') as $id => $row) {
            $result[$id] ??= ['manifest' => $row['value']['manifest'], 'state' => ['status' => 'pending_install', 'ready' => false, 'enabled' => false], 'revision' => 0];
            $result[$id]['availableVersion'] = $row['value']['manifest']['version'];
        }
        return $result;
    }
}
