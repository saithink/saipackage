<?php
require '/app/server/vendor/autoload.php';
require '/app/server/support/bootstrap.php';
use Saithink\Saipackage\management\Factory;

$manager = Factory::manager();
$item = $manager->store->get('installations', 'managed_ocr')['value'];
$values = $manager->settings->raw('managed_ocr')['values'];
$services = Factory::services($manager);
$assert = static function (string $actual, string $expected): void {
    if ($actual !== $expected) throw new RuntimeException("Expected $expected, received $actual");
    echo "PASS real HTTP probe $expected\n";
};
$check = static fn (array $draft) => $services->check('managed_ocr', $item['manifest'], $item['schema'], $draft)['results']['ocr']['code'];
try {
    $assert($check($values), 'OK');
    $bad = $values; $bad['ocr']['apiKey'] = 'invalid-fixture-key';
    $assert($check($bad), 'AUTH_FAILED');
    file_put_contents(__DIR__ . '/mock-state.json', json_encode(['version' => '3.0.0']));
    $assert($check($values), 'VERSION_UNSUPPORTED');
    file_put_contents(__DIR__ . '/mock-state.json', json_encode(['capabilities' => []]));
    $assert($check($values), 'CAPABILITY_MISSING');
    $bad = $values; $bad['ocr']['endpoint'] = 'http://127.0.0.1:18998';
    $assert($check($bad), 'UNREACHABLE');
    file_put_contents(__DIR__ . '/mock-state.json', json_encode(['delayMs' => 6000]));
    $assert($check($values), 'TIMEOUT');
} finally {
    @unlink(__DIR__ . '/mock-state.json');
}
