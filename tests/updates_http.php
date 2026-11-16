<?php
/** Run on a disposable host with managed_ocr 1.1.0, healthy mock_health and configured mock_updates. */
require '/app/server/vendor/autoload.php';
require '/app/server/support/bootstrap.php';
use Saithink\Saipackage\management\Factory;
use Tinywan\Jwt\JwtToken;
use think\facade\Db;

$manager = Factory::manager();
$token = JwtToken::generateToken(['access_exp' => 600, 'id' => 1, 'username' => 'admin', 'type' => 'pc', 'plat' => 'saiadmin'])['access_token'];
$assert = static function (bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; };
$call = static function (string $action, ?array $data = null, bool $anonymous = false) use ($token): array {
    $url = str_starts_with($action, '/') ? $action : '/tool/install/management/' . $action;
    $curl = curl_init('http://127.0.0.1:8787' . $url);
    $headers = ['Content-Type: application/json']; if (!$anonymous) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers]);
    if ($data !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data)]);
    $result = json_decode((string) curl_exec($curl), true);
    if (!is_array($result)) throw new RuntimeException('Expected HTTP JSON');
    return $result;
};
$wait = static function (callable $read, callable $done): array {
    $deadline = microtime(true) + 20;
    do { $value = $read(); if ($done($value)) return $value; usleep(200000); } while (microtime(true) < $deadline);
    throw new RuntimeException('Timed out waiting for background work');
};
$check = static function () use ($call, $wait): array {
    $response = $call('checkUpdates', ['appName' => 'managed_ocr']);
    if ($response['code'] !== 200) throw new RuntimeException('Cannot schedule update check');
    return $wait(fn () => $call('updates')['data']['managed_ocr'], fn ($view) => $view['state'] === 'checked');
};
$task = static fn (string $id) => $manager->store->get('tasks', $id);
$waitTask = static fn (string $id) => $wait(fn () => $task($id), fn ($row) => in_array($row['value']['state'], ['succeeded', 'needs_attention'], true));
$cancel = static function (string $id) use ($call, $task): void {
    $response = $call('recover', ['taskId' => $id, 'revision' => $task($id)['revision'], 'action' => 'cancel']);
    if ($response['code'] !== 200) throw new RuntimeException('Cannot cancel fixture task');
};
$prepare = static function (array $view, string $version) use ($call, $waitTask): array {
    $release = array_values(array_filter($view['releases'], fn ($item) => $item['version'] === $version))[0];
    $response = $call('prepareUpdate', ['appName' => 'managed_ocr', 'releaseFingerprint' => $release['fingerprint']]);
    if ($response['code'] !== 200) throw new RuntimeException('Cannot prepare update: ' . ($response['message'] ?? 'unknown'));
    $id = $response['data']['taskId']; return [$id, $waitTask($id)];
};
$action = $argv[1] ?? 'check';
if ($action === 'check') {
    $count = count($manager->store->all('tasks'));
    file_put_contents(__DIR__ . '/update-download-count', '0');
    $assert($call('updates', null, true)['code'] === 401, 'update APIs require authentication');
    $view = $check();
    $assert($view['latest'] === '2.0.0' && $view['compatible'] === '1.3.0', 'latest and metadata-compatible versions differ');
    $assert($view['releases'][0]['compatibility'] === 'blocked', 'SaiAdmin 7 requirement blocks newest release');
    $assert((int) file_get_contents(__DIR__ . '/update-download-count') === 0, 'real automatic detector never downloads ZIPs');
    $assert(count($manager->store->all('tasks')) === $count, 'metadata check creates no install task');
    $assert($manager->installed()['managed_ocr']['manifest']['version'] === '1.1.0', 'automatic detection leaves installed version unchanged');
    $assert(!str_contains(json_encode($view), 'fixture-update-token') && !str_contains(json_encode($view), '/packages/'), 'public update API excludes credentials and download URLs');
    if (!Db::table('managed_ocr_items')->where('marker', 'online-upgrade-retained')->count()) Db::table('managed_ocr_items')->insert(['marker' => 'online-upgrade-retained']);
} elseif ($action === 'service') {
    [$id, $row] = $prepare($check(), '1.3.0');
    $assert($row['value']['state'] === 'succeeded', 'download preparation finishes without installing');
    $assert($manager->installed()['managed_ocr']['manifest']['version'] === '1.1.0', 'prepared update is not activated');
    $plan = $call('updatePlan', ['taskId' => $id]);
    $assert($plan['code'] !== 200 && $plan['message'] === 'UPDATE_EXTERNAL_SERVICE_VERSION_UNSUPPORTED', 'manual upgrade preview blocks incompatible external service');
    $attempt = $call('/app/saipackage/install/install', ['appName' => 'managed_ocr']);
    $failedId = $attempt['data']['taskId']; $failed = $waitTask($failedId);
    $assert($failed['value']['state'] === 'needs_attention' && $failed['value']['error'] === 'UPDATE_EXTERNAL_SERVICE_VERSION_UNSUPPORTED', 'actual execution rechecks external service compatibility');
    $assert($manager->installed()['managed_ocr']['manifest']['version'] === '1.1.0', 'failed service preflight preserves installed version');
    $cancel($failedId);
} elseif ($action === 'invalid') {
    $zip = new ZipArchive(); $zip->open(__DIR__ . '/fixtures/managed_ocr-1.2.0.zip'); $manifest = json_decode($zip->getFromName('manifest.json'), true); $zip->close();
    $realHash = hash_file('sha256', __DIR__ . '/fixtures/managed_ocr-1.2.0.zip');
    foreach (['checksum', 'identity', 'manifest'] as $failure) {
        $release = ['manifest' => $manifest, 'url' => 'http://127.0.0.1:19000/packages/managed_ocr-1.2.0.zip', 'sha256' => $realHash];
        if ($failure === 'checksum') $release['sha256'] = str_repeat('0', 64);
        if ($failure === 'identity') $release['manifest']['version'] = '1.4.0';
        if ($failure === 'manifest') $release['manifest']['requiresHost'] = ['php' => '>=8.0'];
        file_put_contents(__DIR__ . '/update-feed-override.json', json_encode(['schemaVersion' => 1, 'plugins' => ['managed_ocr' => [$release]]]));
        $before = $manager->fingerprint($manager->packagePath('managed_ocr'));
        [$id, $row] = $prepare($check(), $release['manifest']['version']);
        $expected = ['checksum' => 'UPDATE_CHECKSUM_MISMATCH', 'identity' => 'UPDATE_PACKAGE_IDENTITY_MISMATCH', 'manifest' => 'UPDATE_MANIFEST_MISMATCH'][$failure];
        $assert($row['value']['state'] === 'needs_attention' && $row['value']['error'] === $expected, 'invalid remote package rejected: ' . $failure);
        $assert($manager->fingerprint($manager->packagePath('managed_ocr')) === $before, 'rejected download preserves staged and installed packages');
        $cancel($id);
    }
    unlink(__DIR__ . '/update-feed-override.json');
} elseif ($action === 'upgrade') {
    [$id, $row] = $prepare($check(), '1.2.0');
    $assert($row['value']['state'] === 'succeeded', 'operator can choose an earlier compatible release above installed version');
    $assert($manager->installed()['managed_ocr']['manifest']['version'] === '1.1.0', 'manual download still does not upgrade');
    $plan = $call('updatePlan', ['taskId' => $id]);
    $assert($plan['code'] === 200 && $plan['data']['changes']['managed_ocr']['to'] === '1.2.0', 'review full upgrade plan before confirmation');
    $stale = $call('confirmUpdate', ['taskId' => $id, 'fingerprint' => 'stale']);
    $assert($stale['code'] === 409, 'stale upgrade confirmation rejected');
    $settings = $call('settings?appName=managed_ocr')['data'];
    $values = $settings['values']; $values['ocr']['timeout']++;
    $call('saveSettings', ['appName' => 'managed_ocr', 'revision' => $settings['revision'], 'values' => $values, 'secretActions' => []]);
    $stale = $call('confirmUpdate', ['taskId' => $id, 'fingerprint' => $plan['data']['fingerprint']]);
    $assert($stale['code'] === 409, 'configuration change requires a new upgrade preview');
    $plan = $call('updatePlan', ['taskId' => $id]);
    $response = $call('confirmUpdate', ['taskId' => $id, 'fingerprint' => $plan['data']['fingerprint']]);
    $assert($response['code'] === 200, 'explicit confirmation enqueues installation');
    $done = $waitTask($response['data']['taskId']);
    $assert($done['value']['state'] === 'succeeded', 'real worker upgrades confirmed package');
    $assert($manager->installed()['managed_ocr']['manifest']['version'] === '1.2.0', 'installed version changes only after confirmation');
    $assert(Db::table('managed_ocr_items')->where('marker', 'online-upgrade-retained')->count() === 1, 'online upgrade preserves business data');
    $assert($manager->settings->raw('managed_ocr')['revision'] === $settings['revision'] + 1, 'online upgrade preserves saved settings');
} elseif ($action === 'confirm_once') {
    // Run after upgrade, with mock_health reporting 3.0.0.
    [$id, $row] = $prepare($check(), '1.3.0');
    $assert($row['value']['state'] === 'succeeded', 'second preparation stages only');
    $assert($manager->installed()['managed_ocr']['manifest']['version'] === '1.2.0', 'second download waits for operator confirmation');
    $settingsRevision = $manager->settings->raw('managed_ocr')['revision'];
    $plan = $call('updatePlan', ['taskId' => $id]);
    $assert($plan['code'] === 200, 'updated external service permits review');
    $response = $call('confirmUpdate', ['taskId' => $id, 'fingerprint' => $plan['data']['fingerprint']]);
    $assert($response['code'] === 200, 'manual confirmation accepted once');
    $assert($task($id)['value']['confirmedTaskId'] === $response['data']['taskId'], 'preparation records confirmed installation atomically');
    $replay = $call('confirmUpdate', ['taskId' => $id, 'fingerprint' => $plan['data']['fingerprint']]);
    $assert($replay['code'] === 409 && $replay['message'] === 'UPDATE_ALREADY_CONFIRMED', 'replayed confirmation rejected');
    $assert($waitTask($response['data']['taskId'])['value']['state'] === 'succeeded', 'second real upgrade succeeds');
    $assert($manager->installed()['managed_ocr']['manifest']['version'] === '1.3.0', 'confirmed target version installed');
    $assert(Db::table('managed_ocr_items')->where('marker', 'online-upgrade-retained')->count() === 1, 'second upgrade retains business data');
    $assert($manager->settings->raw('managed_ocr')['revision'] === $settingsRevision, 'second upgrade retains settings revision');
}
