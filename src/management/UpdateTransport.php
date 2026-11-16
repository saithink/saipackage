<?php
namespace Saithink\Saipackage\management;

/** Bounded transfers, no redirects, TLS verification always enabled. */
final class UpdateTransport
{
    public function __construct(private bool $allowHttp = false)
    {
    }

    public function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !in_array($parts['scheme'] ?? '', $this->allowHttp ? ['http', 'https'] : ['https'], true)) throw new ManagementException('Invalid update source URL');
        return strtolower($parts['scheme'] . '://' . $parts['host']) . ':' . ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
    }

    public function transfer(string $url, array $headers = [], ?array $post = null, ?string $destination = null, int $limit = 1048576): string
    {
        $this->origin($url);
        $curl = curl_init($url);
        $body = '';
        $length = 0;
        $file = $destination !== null ? fopen($destination, 'wb') : null;
        if ($destination !== null && !$file) throw new ManagementException('Cannot create update download');
        try {
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $file ? 60 : 8,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => $this->allowHttp ? CURLPROTO_HTTP | CURLPROTO_HTTPS : CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$length, $limit, $file): int {
                    $length += strlen($chunk);
                    if ($length > $limit) return 0;
                    if ($file) return fwrite($file, $chunk);
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if ($post !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post, JSON_THROW_ON_ERROR)]);
            curl_exec($curl);
            $error = curl_errno($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($error === CURLE_OPERATION_TIMEDOUT) throw new ManagementException('UPDATE_TIMEOUT');
            if ($length > $limit) throw new ManagementException('UPDATE_RESPONSE_TOO_LARGE');
            if ($error) throw new ManagementException('UPDATE_UNREACHABLE');
            if (in_array($status, [401, 403], true)) throw new ManagementException('UPDATE_AUTH_FAILED');
            if ($status !== 200) throw new ManagementException('UPDATE_HTTP_ERROR');
            return $body;
        } finally {
            if ($file) fclose($file);
        }
    }
}
