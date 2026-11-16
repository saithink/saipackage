<?php
/** Disposable-host HTTP contract tests. Tokens use the host's normal issuer; auth middleware stays enabled. */
require '/app/server/vendor/autoload.php';
require '/app/server/support/bootstrap.php';

use Tinywan\Jwt\JwtToken;
use Saithink\Saipackage\management\Factory;
use think\facade\Db;

$manager = Factory::manager();
$assert = static function (bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; };
$token = JwtToken::generateToken(['access_exp' => 600, 'id' => 1, 'username' => 'admin', 'type' => 'pc', 'plat' => 'saiadmin'])['access_token'];
$call = static function (string $path, ?array $data = null, ?string $auth = null): array {
    $curl = curl_init('http://127.0.0.1:8787' . $path);
    $headers = ['Content-Type: application/json'];
    if ($auth) $headers[] = 'Authorization: Bearer ' . $auth;
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers]);
    if ($data !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data)]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $json = json_decode((string) $body, true);
    if (!is_array($json)) throw new RuntimeException('Expected JSON response, HTTP ' . $status);
    return $json + ['httpStatus' => $status];
};
$base = '/tool/install/management/';
$assert(($call($base . 'catalog')['code'] ?? 0) === 401, 'anonymous requests rejected');
$catalog = $call($base . 'catalog', null, $token);
$assert($catalog['code'] === 200 && isset($catalog['data']['managed_ocr']), 'authenticated catalog');

$admin = Db::table('sa_system_user')->where('id', 1)->find();
$operator = $admin;
$operator['id'] = 2; $operator['username'] = 'fixture_operator'; $operator['is_super'] = 2;
if (!Db::table('sa_system_user')->where('id', 2)->find()) Db::table('sa_system_user')->insert($operator);
$restricted = JwtToken::generateToken(['access_exp' => 600, 'id' => 2, 'username' => 'fixture_operator', 'type' => 'pc', 'plat' => 'saiadmin'])['access_token'];
$response = $call($base . 'catalog', null, $restricted);
$assert($response['code'] === 403, 'non-super-admin rejected');

$get = $call($base . 'settings?appName=managed_ocr', null, $token)['data'];
$assert(!str_contains(json_encode($get), 'fixture-secret') && $get['secrets']['ocr.apiKey']['configured'], 'settings GET redacts credentials');
$revision = $get['revision'];
$payload = ['appName' => 'managed_ocr', 'revision' => $revision, 'values' => ['ocr' => ['endpoint' => 'http://127.0.0.1:18999']], 'secretActions' => ['ocr.apiKey' => ['action' => 'keep']]];
$save = $call($base . 'saveSettings', $payload, $token);
$assert($save['code'] === 200, 'save settings through HTTP');
$stale = $call($base . 'saveSettings', $payload, $token);
$assert($stale['code'] === 409 && !isset($stale['request_param']), 'stale revision rejected without debug payload');
$bad = $payload; $bad['revision'] = $save['data']['revision']; $bad['values']['ocr']['apiKey'] = 'do-not-echo-this-credential';
$rejected = $call($base . 'saveSettings', $bad, $token);
$assert($rejected['code'] !== 200 && !str_contains(json_encode($rejected), 'do-not-echo-this-credential'), 'invalid secret request is never echoed');

$draft = $payload + ['draft' => true];
$checked = $call($base . 'probe', $draft, $token);
$assert($checked['code'] === 200 && $checked['data']['saved'] === false, 'HTTP draft test');
$saved = $call($base . 'probe', ['appName' => 'managed_ocr', 'draft' => false], $token);
$assert($saved['code'] === 200, 'HTTP saved connection verification');
$status = $saved['data']['results']['ocr']['code'];
echo 'SERVICE_RESULT ' . $status . "\n";
$assert($status === 'OK', 'real external-service HTTP fixture is healthy');
$call($base . 'enable', ['appName' => 'managed_dep', 'enabled' => true], $token);
$call($base . 'enable', ['appName' => 'managed_ocr', 'enabled' => true], $token);
$blocked = $call($base . 'enable', ['appName' => 'managed_dep', 'enabled' => false], $token);
$assert($blocked['code'] !== 200, 'HTTP disable reverse dependency protection');
$uninstall = $call($base . 'uninstallPlan', ['appName' => 'managed_dep'], $token);
$assert($uninstall['code'] !== 200, 'HTTP uninstall reverse dependency protection');

$off = $call($base . 'enable', ['appName' => 'managed_ocr', 'enabled' => false], $token);
$assert($off['code'] === 200, 'HTTP plugin disable');
$business = $call('/app/managed_ocr/index/index');
$assert($business['code'] === 503 && $business['httpStatus'] === 503, 'business HTTP route guarded');
if ($status === 'OK') {
    $on = $call($base . 'enable', ['appName' => 'managed_ocr', 'enabled' => true], $token);
    $assert($on['code'] === 200, 'HTTP enable after verification');
    $assert($call('/app/managed_ocr/index/index')['fixture'] === '1.1.0', 'ready business HTTP route works');
}
