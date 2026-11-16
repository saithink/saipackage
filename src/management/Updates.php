<?php
namespace Saithink\Saipackage\management;

use Composer\Semver\VersionParser;
use Saithink\Saipackage\service\Filesystem;
use Saithink\Saipackage\service\Server;

/** Detection never downloads or installs. Preparation and confirmation are separate operator actions. */
final class Updates
{
    public function __construct(private Manager $manager, private array $config, private array $adapters = [])
    {
    }

    private function source(string $id): ?array
    {
        $item = $this->manager->store->get('installations', Manifest::id($id))['value'] ?? null;
        $name = $this->config['plugins'][$id] ?? $item['manifest']['updateSource'] ?? null;
        if (!is_string($name) || !isset($this->config['sources'][$name])) return null;
        $source = $this->config['sources'][$name];
        return ['name' => $name, 'config' => $source,
            'fingerprint' => hash('sha256', json_encode([$name, $source, $this->config['allow_prerelease'] ?? false], JSON_THROW_ON_ERROR))];
    }

    private function adapter(array $source): UpdateSource
    {
        if (isset($this->adapters[$source['name']])) return $this->adapters[$source['name']];
        return match ($source['config']['type'] ?? 'json') {
            'json' => new JsonUpdateSource($source['config']),
            'saistore' => new SaiStoreUpdateSource($source['config']),
            default => throw new ManagementException('UPDATE_INVALID_SOURCE'),
        };
    }

    private function interval(): int
    {
        return max(60, (int) ($this->config['interval'] ?? 86400));
    }

    public function requestCheck(?string $plugin = null): void
    {
        $lock = new MutationLock($this->manager->updateDirectory());
        foreach ($this->manager->installed() as $id => $item) {
            if ($plugin !== null && $plugin !== $id) continue;
            if ($item['state'] !== 'installed') continue;
            $row = $this->manager->store->get('updates', $id);
            $value = $row['value'] ?? [];
            $value['requestedAt'] = time();
            $value['nextCheckAt'] = 0;
            $value['state'] = 'pending';
            $this->manager->store->put('updates', $id, $value, $row['revision'] ?? 0);
        }
    }

    /** At most one bounded metadata request per worker poll. Durable scheduling survives restarts. */
    public function tick(): bool
    {
        foreach ($this->manager->installed() as $id => $item) {
            if ($item['state'] !== 'installed') continue;
            $row = $this->manager->store->get('updates', $id);
            $source = $this->source($id);
            $requested = isset($row['value']['requestedAt']);
            if (!$requested && !($this->config['automatic'] ?? true)) continue;
            $stale = !$row || ($row['value']['installedVersion'] ?? '') !== $item['manifest']['version']
                || ($row['value']['environmentFingerprint'] ?? '') !== $this->manager->environmentFingerprint()
                || ($row['value']['sourceFingerprint'] ?? null) !== ($source['fingerprint'] ?? null);
            if ($stale || time() >= ($row['value']['nextCheckAt'] ?? 0)) {
                $this->check($id);
                return true;
            }
        }
        return false;
    }

    public function check(string $id): array
    {
        $lock = new MutationLock($this->manager->updateDirectory());
        $installed = $this->manager->store->get('installations', Manifest::id($id));
        if (!$installed || $installed['value']['state'] !== 'installed') throw new ManagementException('Plugin is not installed');
        $source = $this->source($id);
        $old = $this->manager->store->get('updates', $id);
        $value = $old['value'] ?? [];
        unset($value['requestedAt'], $value['error']);
        $value = array_replace($value, ['state' => 'unconfigured', 'sourceFingerprint' => $source['fingerprint'] ?? null,
            'environmentFingerprint' => $this->manager->environmentFingerprint(),
            'installedVersion' => $installed['value']['manifest']['version'], 'installedRevision' => $installed['revision'],
            'nextCheckAt' => time() + $this->interval(), 'attemptedAt' => time()]);
        if ($source) {
            try {
                $parser = new VersionParser();
                $parser->normalize($value['installedVersion']);
                $releases = [];
                $seen = [];
                foreach ($this->adapter($source)->releases($id) as $release) {
                    $version = ltrim((string) $release['version'], 'vV');
                    $normalized = $parser->normalize($version);
                    if (isset($seen[$normalized])) throw new ManagementException('UPDATE_DUPLICATE_VERSION');
                    $seen[$normalized] = true;
                    if (!empty($release['withdrawn']) || (!($this->config['allow_prerelease'] ?? false) && str_contains(explode('+', $version)[0], '-'))) continue;
                    if (!Manifest::matches($version, '>' . $value['installedVersion'])) continue;
                    $release['version'] = $version;
                    $release['compatibility'] = 'unknown';
                    $release['reasons'] = [];
                    if ($release['manifest'] !== null) {
                        try {
                            $this->manager->resolveManifest($release['manifest']);
                            $release['compatibility'] = 'compatible';
                        } catch (ManagementException $e) {
                            $release['compatibility'] = 'blocked';
                            $release['reasons'] = ['HOST_OR_PLUGIN_DEPENDENCY'];
                        }
                    }
                    $release['fingerprint'] = hash('sha256', json_encode([$source['fingerprint'], $release], JSON_THROW_ON_ERROR));
                    $releases[] = $release;
                }
                usort($releases, fn ($a, $b) => version_compare($b['version'], $a['version']));
                $value['releases'] = $releases;
                $value['state'] = 'checked';
                $value['checkedAt'] = time();
            } catch (\Throwable $e) {
                $value['state'] = 'error';
                $value['error'] = $e instanceof ManagementException && str_starts_with($e->getMessage(), 'UPDATE_') ? $e->getMessage() : 'UPDATE_CHECK_FAILED';
                $value['nextCheckAt'] = time() + max(60, (int) ($this->config['retry_interval'] ?? 900));
            }
        } else {
            $value['releases'] = [];
        }
        // A concurrent install/source change invalidates this snapshot instead of publishing stale recommendations.
        if (($this->manager->store->get('installations', $id)['revision'] ?? 0) !== $installed['revision']) {
            $value['state'] = 'pending'; $value['nextCheckAt'] = 0; $value['releases'] = [];
        }
        $this->manager->store->put('updates', $id, $value, $old['revision'] ?? 0);
        return $this->view($id);
    }

    public function view(string $id): array
    {
        $row = $this->manager->store->get('updates', $id);
        if (!$row) return ['state' => $this->source($id) ? 'pending' : 'unconfigured', 'releases' => [], 'revision' => 0];
        $value = $row['value'];
        $installed = $this->manager->store->get('installations', $id);
        $stale = ($installed['revision'] ?? 0) !== ($value['installedRevision'] ?? 0)
            || ($value['environmentFingerprint'] ?? '') !== $this->manager->environmentFingerprint()
            || ($value['sourceFingerprint'] ?? null) !== ($this->source($id)['fingerprint'] ?? null);
        $releases = [];
        foreach ($stale ? [] : ($value['releases'] ?? []) as $release) {
            $releases[] = array_intersect_key($release, array_flip(['version', 'notes', 'publishedAt', 'compatibility', 'reasons', 'fingerprint']));
        }
        $compatible = array_values(array_filter($releases, fn ($release) => $release['compatibility'] === 'compatible'));
        return ['state' => $stale ? 'pending' : $value['state'], 'releases' => $releases, 'latest' => $releases[0]['version'] ?? null,
            'compatible' => $compatible[0]['version'] ?? null, 'checkedAt' => $value['checkedAt'] ?? null,
            'error' => $value['error'] ?? null, 'revision' => $row['revision']];
    }

    public function catalog(): array
    {
        $result = [];
        foreach ($this->manager->installed() as $id => $_) $result[$id] = $this->view($id);
        return $result;
    }

    private function cachedRelease(string $id, string $fingerprint, bool $requireEnvironment = true): array
    {
        $row = $this->manager->store->get('updates', $id);
        $source = $this->source($id);
        $installed = $this->manager->store->get('installations', $id);
        if (!$source || !$row || $row['value']['state'] !== 'checked'
            || ($row['value']['sourceFingerprint'] ?? '') !== $source['fingerprint']
            || ($row['value']['installedRevision'] ?? 0) !== ($installed['revision'] ?? -1)
            || ($requireEnvironment && ($row['value']['environmentFingerprint'] ?? '') !== $this->manager->environmentFingerprint())
            || time() - ($row['value']['checkedAt'] ?? 0) > $this->interval()) throw new ManagementException('UPDATE_CHECK_REQUIRED', 409);
        foreach ($row['value']['releases'] as $release) {
            if (hash_equals($release['fingerprint'], $fingerprint)) return [$source, $release];
        }
        throw new ManagementException('UPDATE_RELEASE_CHANGED', 409);
    }

    public function requestPreparation(string $id, string $fingerprint, int $actor): string
    {
        $lock = $this->manager->lock();
        [, $release] = $this->cachedRelease($id, $fingerprint);
        if ($release['compatibility'] === 'blocked') throw new ManagementException('UPDATE_INCOMPATIBLE');
        foreach ($this->manager->store->all('tasks') as $row) {
            if (in_array($row['value']['state'], ['queued', 'running', 'needs_attention', 'waiting_dependencies'], true)) throw new ManagementException('Resolve the existing task first', 409);
        }
        $taskId = bin2hex(random_bytes(16));
        $this->manager->store->put('tasks', $taskId, ['kind' => 'prepare_update', 'state' => 'queued', 'plugin' => $id,
            'releaseFingerprint' => $fingerprint, 'actor' => $actor, 'events' => [], 'createdAt' => time()], 0);
        return $taskId;
    }

    public function prepare(array $task): array
    {
        $id = Manifest::id($task['plugin']);
        [$source, $release] = $this->cachedRelease($id, $task['releaseFingerprint']);
        $directory = $this->manager->updateDirectory() . '/' . bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        $zip = $directory . '/package.zip';
        try {
            $this->adapter($source)->download($id, $release, $zip);
            $digest = hash_file('sha256', $zip);
            if ($release['sha256'] !== null && !hash_equals($release['sha256'], $digest)) throw new ManagementException('UPDATE_CHECKSUM_MISMATCH');
            $unpacked = Filesystem::unzip($zip, $directory . '/inspect');
            $snapshot = $this->manager->snapshot($unpacked, Server::getIni($unpacked . '/'));
            if ($snapshot['manifest']['id'] !== $id || (new VersionParser())->normalize($snapshot['manifest']['version']) !== (new VersionParser())->normalize($release['version'])) throw new ManagementException('UPDATE_PACKAGE_IDENTITY_MISMATCH');
            if ($release['manifest'] !== null && $snapshot['manifest'] != $release['manifest']) throw new ManagementException('UPDATE_MANIFEST_MISMATCH');
            $this->manager->resolveManifest($snapshot['manifest']);
            // Import only after the remote package's identity and declarations have passed validation.
            $stagedPath = $this->manager->packagePath($id);
            $sameStaged = is_file($stagedPath . 'info.ini') && in_array((int) (Server::getIni($stagedPath)['state'] ?? 0), [2, 3], true)
                && hash_equals($snapshot['fingerprint'], $this->manager->fingerprint($stagedPath));
            if (!$sameStaged) (new \plugin\saipackage\app\logic\InstallLogic())->uploadFromPath($zip);
            $plan = $this->manager->plan($id);
            return array_intersect_key($plan, array_flip(['root', 'selected', 'changes', 'fingerprint']))
                + ['packageFingerprint' => $snapshot['fingerprint'], 'sha256' => $digest, 'releaseFingerprint' => $task['releaseFingerprint']];
        } finally {
            Filesystem::delDir($directory);
        }
    }

    public function preparedPlan(string $taskId, Services $services): array
    {
        $task = $this->manager->store->get('tasks', $taskId)['value'] ?? [];
        if (($task['kind'] ?? '') !== 'prepare_update' || $task['state'] !== 'succeeded') throw new ManagementException('UPDATE_PREPARATION_REQUIRED');
        if (isset($task['confirmedTaskId'])) throw new ManagementException('UPDATE_ALREADY_CONFIRMED', 409);
        $id = $task['plugin'];
        $this->cachedRelease($id, $task['releaseFingerprint'], false);
        if (!hash_equals($task['result']['packageFingerprint'], $this->manager->fingerprint($this->manager->packagePath($id)))) throw new ManagementException('UPDATE_PACKAGE_CHANGED', 409);
        $plan = $this->manager->plan($id);
        $settings = [];
        foreach ($plan['steps'] as $plugin => $snapshot) {
            $prepared = $this->manager->previewSettings($snapshot);
            UpgradeServices::assertCompatible($this->manager, $services, $snapshot, $prepared);
            $settings[$plugin] = [$this->manager->store->get('settings', $plugin)['revision'] ?? 0,
                $prepared === null ? null : $this->manager->settings->fingerprint($prepared['values'], $snapshot['schema'], false)];
        }
        $plan['fingerprint'] = hash('sha256', json_encode([$plan['fingerprint'], $settings], JSON_THROW_ON_ERROR));
        return array_intersect_key($plan, array_flip(['root', 'selected', 'changes', 'fingerprint']));
    }

    public function confirm(string $taskId, string $fingerprint, int $actor, Services $services): string
    {
        $lock = $this->manager->lock();
        $plan = $this->preparedPlan($taskId, $services);
        if (!hash_equals($plan['fingerprint'], $fingerprint)) throw new ManagementException('Installation plan has changed; review it again', 409);
        return $this->manager->store->transaction(function () use ($plan, $taskId, $actor): string {
            $id = (new Tasks($this->manager))->submit($plan['root'], $this->manager->plan($plan['root'])['fingerprint'], $actor);
            $row = $this->manager->store->get('tasks', $taskId);
            $task = $row['value'];
            $task['confirmedTaskId'] = $id;
            $task['stage'] = 'confirmed';
            $this->manager->store->put('tasks', $taskId, $task, $row['revision']);
            return $id;
        });
    }
}
