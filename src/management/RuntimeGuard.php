<?php
namespace Saithink\Saipackage\management;

use RuntimeException;

final class RuntimeGuard
{
    public function __construct(private Store $store, private Settings $settings, private int $maxCheckAge = 300)
    {
    }

    public function status(string $id, array $chain = []): array
    {
        $item = $this->store->get('installations', Manifest::id($id))['value'] ?? null;
        if (!$item) return ['status' => 'unmanaged', 'ready' => true];
        if (in_array($id, $chain, true)) return ['status' => 'dependency_blocked', 'ready' => false];
        $installation = $item['state'];
        $enabled = $item['enabled'];
        $config = 'valid';
        $health = 'healthy';
        $dependency = 'satisfied';
        foreach ($item['manifest']['requiresPlugins'] ?? [] as $dep => $constraint) {
            $other = $this->store->get('installations', $dep)['value'] ?? null;
            if (!$other || $other['state'] !== 'installed' || !Manifest::matches($other['manifest']['version'], $constraint)) $dependency = 'unsatisfied';
            elseif (!$this->status($dep, [...$chain, $id])['ready']) $dependency = 'not_ready';
        }
        try {
            $raw = $this->settings->raw($id);
            if ($raw['revision'] && $raw['version'] !== ($item['manifest']['settingsVersion'] ?? 1)) $config = 'invalid';
            if (!Manifest::validateSettings($raw['values'], $item['schema'])) $config = 'missing';
            $services = $item['manifest']['externalServices'] ?? [];
            $check = $this->store->get('service_checks', $id)['value'] ?? [];
            foreach ($services as $service) {
                $same = isset($check['fingerprint']) && hash_equals($check['fingerprint'], $this->settings->fingerprint($raw['values'], $item['schema']))
                    && ($check['requirementsFingerprint'] ?? '') === Services::requirementsFingerprint($item['manifest']);
                $recent = time() - (int) ($check['checkedAt'] ?? 0) <= $this->maxCheckAge;
                $code = $same && $recent ? ($check['results'][$service['id']]['code'] ?? 'UNKNOWN') : 'UNKNOWN';
                if ($code !== 'OK') {
                    if ($service['required'] ?? true) {
                        if (!$same) $config = $config === 'missing' ? 'missing' : 'unverified';
                        $health = 'unhealthy';
                    } elseif ($health === 'healthy') $health = 'degraded';
                }
            }
        } catch (\Throwable) {
            $config = 'invalid';
        }
        $status = match (true) {
            $installation !== 'installed' => $installation,
            !$enabled => 'disabled',
            $dependency !== 'satisfied' => 'dependency_blocked',
            $config !== 'valid' => 'settings_' . $config,
            $health === 'unhealthy' => 'service_unhealthy',
            $health === 'degraded' => 'degraded',
            default => 'ready',
        };
        return ['status' => $status, 'ready' => in_array($status, ['ready', 'degraded'], true), 'installation' => $installation,
            'enabled' => $enabled, 'configuration' => $config, 'dependency' => $dependency, 'health' => $health];
    }

    public function assertReady(string $id): void
    {
        $status = $this->status($id);
        if (!$status['ready']) throw new ManagementException('Plugin unavailable: ' . $id . ' (' . $status['status'] . ')', 503);
    }
}
