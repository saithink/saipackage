<?php
namespace Saithink\Saipackage\management;

/** Optional adapter for services implementing GET /health -> {version, capabilities}. */
final class JsonHealthProbe implements ServiceProbe
{
    public function check(array $settings, array $service): array
    {
        $endpoint = rtrim((string) ($settings['endpoint'] ?? ''), '/');
        $url = parse_url($endpoint);
        if (!$url || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) return ['code' => 'UNREACHABLE'];
        $curl = curl_init($endpoint . '/health');
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . str_replace(["\r", "\n"], '', (string) ($settings['apiKey'] ?? ''))],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 65536) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_exec($curl);
        $error = curl_errno($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($error === CURLE_OPERATION_TIMEDOUT) return ['code' => 'TIMEOUT'];
        if ($error) return ['code' => 'UNREACHABLE'];
        if (in_array($status, [401, 403], true)) return ['code' => 'AUTH_FAILED'];
        if ($status !== 200) return ['code' => 'UNREACHABLE'];
        $data = json_decode($body, true);
        if (!is_array($data) || !is_string($data['version'] ?? null)) return ['code' => 'UNKNOWN'];
        if (!Manifest::matches($data['version'], (string) ($service['version'] ?? '*'))) return ['code' => 'VERSION_UNSUPPORTED'];
        foreach ($service['capabilities'] ?? [] as $capability) {
            if (!in_array($capability, $data['capabilities'] ?? [], true)) return ['code' => 'CAPABILITY_MISSING'];
        }
        return ['code' => 'OK', 'serviceVersion' => $data['version']];
    }
}
