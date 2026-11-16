<?php
namespace Saithink\Saipackage\management;

use RuntimeException;

final class Services
{
    public const CODES = ['OK', 'UNREACHABLE', 'TIMEOUT', 'AUTH_FAILED', 'VERSION_UNSUPPORTED', 'CAPABILITY_MISSING', 'UNKNOWN'];

    public function __construct(private Store $store, private Settings $settings, private array $probes)
    {
    }

    public static function requirementsFingerprint(array $manifest): string
    {
        return hash('sha256', json_encode($manifest['externalServices'] ?? [], JSON_THROW_ON_ERROR));
    }

    public function check(string $id, array $manifest, array $schema, ?array $draft = null): array
    {
        $raw = $this->settings->raw($id);
        $values = $draft ?? $raw['values'];
        if (!Manifest::validateSettings($values, $schema)) throw new ManagementException('Configure required settings before testing');
        $results = [];
        foreach ($manifest['externalServices'] ?? [] as $service) {
            $adapter = $this->probes[$service['probe']] ?? null;
            $start = microtime(true);
            try {
                if (is_string($adapter)) $adapter = new $adapter();
                $result = $adapter instanceof ServiceProbe ? $adapter->check($values[$service['settingsGroup']] ?? [], $service) : ['code' => 'UNKNOWN'];
            } catch (\Throwable) {
                $result = ['code' => 'UNKNOWN'];
            }
            $code = in_array($result['code'] ?? '', self::CODES, true) ? $result['code'] : 'UNKNOWN';
            $results[$service['id']] = [
                'code' => $code, 'latencyMs' => (int) ((microtime(true) - $start) * 1000),
                // Only the typed public metadata is retained. Adapter error messages are intentionally discarded.
                'serviceVersion' => preg_match('/^[0-9A-Za-z.+_-]{1,64}$/D', $result['serviceVersion'] ?? '') ? $result['serviceVersion'] : null,
            ];
        }
        if ($draft === null) {
            $current = $this->settings->raw($id);
            if ($current['revision'] !== $raw['revision']) throw new ManagementException('Settings changed during connection test', 409);
            $row = $this->store->get('service_checks', $id);
            $this->store->put('service_checks', $id, [
                'settingsRevision' => $raw['revision'], 'fingerprint' => $this->settings->fingerprint($values, $schema),
                'requirementsFingerprint' => self::requirementsFingerprint($manifest),
                'results' => $results, 'checkedAt' => time(),
            ], $row['revision'] ?? 0);
        }
        return ['results' => $results, 'saved' => $draft === null];
    }
}
