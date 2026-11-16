<?php
// Run manually in a disposable host: php -S 127.0.0.1:19000 tests/mock_updates.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer fixture-update-token') { http_response_code(401); exit; }
if ($path === '/index.json') {
    header('Content-Type: application/json');
    if (is_file(__DIR__ . '/update-feed-override.json')) { readfile(__DIR__ . '/update-feed-override.json'); exit; }
    $releases = [];
    foreach (['1.2.0', '1.3.0'] as $version) {
        $file = __DIR__ . '/fixtures/managed_ocr-' . $version . '.zip';
        $zip = new ZipArchive(); $zip->open($file);
        $manifest = json_decode($zip->getFromName('manifest.json'), true); $zip->close();
        $releases[] = ['manifest' => $manifest, 'url' => 'http://127.0.0.1:19000/packages/managed_ocr-' . $version . '.zip',
            'sha256' => hash_file('sha256', $file), 'notes' => 'Fixture release ' . $version, 'publishedAt' => '2026-11-16'];
    }
    $blocked = $releases[0]; $blocked['manifest']['version'] = '2.0.0'; $blocked['manifest']['requiresHost'] = ['saiadmin' => '^7'];
    $releases[] = $blocked;
    echo json_encode(['schemaVersion' => 1, 'plugins' => ['managed_ocr' => $releases]]); exit;
}
if (preg_match('#^/packages/(managed_ocr-1\.[23]\.0\.zip)$#D', $path, $matches)) {
    file_put_contents(__DIR__ . '/update-download-count', (int) @file_get_contents(__DIR__ . '/update-download-count') + 1);
    header('Content-Type: application/zip'); readfile(__DIR__ . '/fixtures/' . $matches[1]); exit;
}
http_response_code(404);
