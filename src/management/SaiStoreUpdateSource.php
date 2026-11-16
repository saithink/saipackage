<?php
namespace Saithink\Saipackage\management;

/** Adapter for the existing official store contract. No new login or credential scraping. */
final class SaiStoreUpdateSource implements UpdateSource
{
    private const BASE = 'https://saas.saithink.top/dev-api/app/saistore/api/StoreOrder/';
    private UpdateTransport $http;

    public function __construct(private array $config)
    {
        $this->http = new UpdateTransport();
    }

    private function headers(): array
    {
        $token = $this->config['token'] ?? '';
        if ($token === '' || str_contains($token, "\r") || str_contains($token, "\n")) throw new ManagementException('UPDATE_AUTH_REQUIRED');
        return ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
    }

    public function releases(string $plugin): array
    {
        $appId = (int) ($this->config['apps'][$plugin] ?? 0);
        if ($appId < 1) throw new ManagementException('UPDATE_APP_MAPPING_REQUIRED');
        $data = json_decode($this->http->transfer(self::BASE . 'appVersionList?app_id=' . $appId, $this->headers()), true, 64, JSON_THROW_ON_ERROR);
        if (($data['code'] ?? 0) !== 200 || !is_array($data['data'] ?? null)) throw new ManagementException('UPDATE_STORE_ERROR');
        return self::normalize($data['data']);
    }

    public static function normalize(array $data): array
    {
        $data = $data['data'] ?? $data;
        if (!array_is_list($data) || count($data) > 100) throw new ManagementException('UPDATE_INVALID_METADATA');
        $result = [];
        foreach ($data as $release) {
            if (!is_array($release) || !is_numeric($release['id'] ?? null) || !is_string($release['version'] ?? null)) throw new ManagementException('UPDATE_INVALID_METADATA');
            $result[] = ['version' => $release['version'], 'versionId' => (int) $release['id'], 'manifest' => null,
                'sha256' => null, 'notes' => mb_substr((string) ($release['remark'] ?? ''), 0, 8000),
                'publishedAt' => mb_substr((string) ($release['create_time'] ?? ''), 0, 64), 'withdrawn' => false];
        }
        return $result;
    }

    public function download(string $plugin, array $release, string $destination): void
    {
        $this->http->transfer(self::BASE . 'downloadVersion', $this->headers(), ['version_id' => (int) $release['versionId']], $destination, min(104857600, (int) ($this->config['max_package_bytes'] ?? 52428800)));
    }
}
