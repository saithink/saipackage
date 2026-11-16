<?php
// Disposable local HTTP fixture; never include in a published plugin package.
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/health') {
    http_response_code(404); exit;
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer fixture-secret') {
    http_response_code(401); exit;
}
header('Content-Type: application/json');
$state = is_file(__DIR__ . '/mock-state.json') ? json_decode(file_get_contents(__DIR__ . '/mock-state.json'), true) : [];
if (!empty($state['delayMs'])) usleep((int) $state['delayMs'] * 1000);
echo json_encode(['version' => $state['version'] ?? '2.3.1', 'capabilities' => $state['capabilities'] ?? ['recognize']]);
