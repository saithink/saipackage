<?php
namespace Saithink\Saipackage\management;

final class JsonUpdateSource implements UpdateSource
{
    private UpdateTransport $http;

    public function __construct(private array $config)
    {
        $this->http = new UpdateTransport($config['allow_http'] ?? false);
        $this->http->origin($config['url']);
        if (isset(parse_url($config['url'])['query'])) throw new ManagementException('Use headers for update source authentication');
    }

    private function headers(): array
    {
        $token = $this->config['token'] ?? '';
        if (str_contains($token, "\r") || str_contains($token, "\n")) throw new ManagementException('Invalid update source credentials');
        return $token !== '' ? ['Authorization: Bearer ' . $token] : [];
    }

    public function releases(string $plugin): array
    {
        $data = json_decode($this->http->transfer($this->config['url'], $this->headers()), true, 64, JSON_THROW_ON_ERROR);
        if (($data['schemaVersion'] ?? null) !== 1 || !is_array($data['plugins'] ?? null)) throw new ManagementException('UPDATE_INVALID_METADATA');
        $releases = $data['plugins'][Manifest::id($plugin)] ?? [];
        if (!is_array($releases) || count($releases) > 100) throw new ManagementException('UPDATE_INVALID_METADATA');
        $result = [];
        foreach ($releases as $release) {
            if (!is_array($release) || !isset($release['manifest'], $release['sha256'], $release['url'])) throw new ManagementException('UPDATE_INVALID_METADATA');
            Manifest::validate($release['manifest']);
            if ($release['manifest']['id'] !== $plugin || !empty($release['manifest']['legacy']) || !preg_match('/^[a-f0-9]{64}$/D', $release['sha256'])) throw new ManagementException('UPDATE_INVALID_METADATA');
            $this->validateDownload($release['url']);
            $result[] = [
                'version' => $release['manifest']['version'], 'manifest' => $release['manifest'],
                'url' => $release['url'], 'sha256' => $release['sha256'],
                'notes' => mb_substr((string) ($release['notes'] ?? ''), 0, 8000),
                'publishedAt' => mb_substr((string) ($release['publishedAt'] ?? ''), 0, 64),
                'withdrawn' => ($release['withdrawn'] ?? false) === true,
            ];
        }
        return $result;
    }

    private function validateDownload(string $url): void
    {
        $origin = $this->http->origin($url);
        if (isset(parse_url($url)['query'])) throw new ManagementException('Signed query download URLs are unsupported');
        $allowed = [$this->http->origin($this->config['url'])];
        foreach ($this->config['download_origins'] ?? [] as $allowedOrigin) $allowed[] = $this->http->origin($allowedOrigin);
        if (!in_array($origin, $allowed, true)) throw new ManagementException('Untrusted update download origin');
    }

    public function download(string $plugin, array $release, string $destination): void
    {
        $this->validateDownload($release['url']);
        $headers = $this->http->origin($release['url']) === $this->http->origin($this->config['url']) ? $this->headers() : [];
        $this->http->transfer($release['url'], $headers, null, $destination, min(104857600, (int) ($this->config['max_package_bytes'] ?? 52428800)));
    }
}
