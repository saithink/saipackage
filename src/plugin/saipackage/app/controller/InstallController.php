<?php

namespace plugin\saipackage\app\controller;

use plugin\saiadmin\app\cache\UserMenuCache;
use plugin\saiadmin\app\middleware\SystemLog;
use plugin\saiadmin\app\middleware\CheckLogin;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\exception\ApiException;
use plugin\saiadmin\utils\DbType;
use plugin\saipackage\app\logic\InstallLogic;
use Saithink\Saipackage\service\Server;
use Saithink\Saipackage\service\Version;
use support\annotation\Middleware;
use support\Request;
use support\Response;
use Throwable;

#[Middleware(CheckLogin::class, SystemLog::class)]
class InstallController extends BaseController
{
    /**
     * 构造
     */
    public function __construct()
    {
        parent::__construct();
        if ($this->adminId > 1) {
            throw new ApiException('仅超级管理员能够操作', 403);
        }
    }

    /**
     * 环境检查状态
     */
    static string $ok = 'ok';
    static string $fail = 'fail';
    static string $warn = 'warn';

    static array $needDependentVersion = [
        'php' => '8.1.0',
        'saiadmin' => '6.0.0',
        'saipackage' => '6.0.0',
    ];

    /**
     * 应用列表
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $data = Server::installedList(runtime_path() . DIRECTORY_SEPARATOR . 'saipackage' . DIRECTORY_SEPARATOR);

        // 补上数据库兼容性：老版插件只有 MySQL 的 install.sql，在 PG 下装不了，
        // 列表里就要看得出来，不能等点了安装才报错
        foreach ($data as &$item) {
            $item = array_merge($item, InstallLogic::dbSupport((string) ($item['app'] ?? '')));
        }
        unset($item);

        $phpVersion = phpversion();
        $phpVersionCompare = Version::compare(self::$needDependentVersion['php'], $phpVersion);
        $phpVersionNotes = '正常';
        if (!$phpVersionCompare) {
            $phpVersionNotes = '需要版本' . ' >= ' . self::$needDependentVersion['php'];
        }

        $saiadminVersion = config('plugin.saiadmin.app.version');
        $saiadminVersionCompare = Version::compare(self::$needDependentVersion['saiadmin'], $saiadminVersion);
        $saiadminVersionNotes = '正常';
        if (!$saiadminVersionCompare) {
            $saiadminVersionNotes = '需要版本' . ' >= ' . self::$needDependentVersion['saiadmin'];
        }

        $saithinkVersion = config('plugin.saipackage.app.version');
        $saithinkVersionCompare = Version::compare(self::$needDependentVersion['saipackage'], $saithinkVersion);
        $saithinkVersionNotes = '正常';
        if (!$saithinkVersionCompare) {
            $saithinkVersionNotes = '需要版本' . ' >= ' . self::$needDependentVersion['saipackage'];
        }


        return $this->success([
            'db_type' => DbType::get(),
            'version' => [
                'php_version' => [
                    'describe' => $phpVersion,
                    'state' => $phpVersionCompare ? self::$ok : self::$fail,
                    'notes' => $phpVersionNotes,
                ],
                'saiadmin_version' => [
                    'describe' => $saiadminVersion,
                    'state' => $saiadminVersionCompare ? self::$ok : self::$fail,
                    'notes' => $saiadminVersionNotes,
                ],
                'saipackage_version' => [
                    'describe' => $saithinkVersion,
                    'state' => $saithinkVersionCompare ? self::$ok : self::$fail,
                    'notes' => $saithinkVersionNotes,
                ],
            ],
            'data' => $data
        ]);
    }

    /**
     * 上传插件
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function upload(Request $request): Response
    {
        $files = $request->file();
        $spl_file = $files ? current($files) : null;
        if (!$spl_file || !$spl_file->isValid()) {
            return $this->fail('上传文件校验失败');
        }
        $config = config('plugin.saipackage.upload', [
            'size' => 1024 * 1024 * 50,
            'type' => ['zip']
        ]);
        if (!in_array($spl_file->getUploadExtension(), $config['type'])) {
            return $this->fail('文件格式上传失败,请选择zip格式文件上传');
        }
        // 真正的上限是两者取小：比 max_package_size 大的请求 workerman 会直接断开连接，
        // 这里放宽也没用（浏览器只看到一个莫名的网络错误）。
        // 留 512KB 给 multipart 的分隔符和表单字段，否则刚好卡在边界上的包还是过不去
        $transportLimit = max(1024 * 1024, (int) config('server.max_package_size', 10 * 1024 * 1024) - 512 * 1024);
        $limit = min((int) $config['size'], $transportLimit);
        if ($spl_file->getSize() > $limit) {
            $message = '文件大小不能超过' . round($limit / 1024 / 1024, 2) . 'M';
            if ($limit === $transportLimit) {
                $message .= '，如需上传更大的插件包，请调大 config/server.php 的 max_package_size 后重启服务';
            }
            return $this->fail($message);
        }
        $install = new InstallLogic();
        $info = $install->upload($spl_file);
        return $this->success($info);
    }

    /**
     * 安装插件
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function install(Request $request): Response
    {
        $appName = $request->post("appName", '');
        if (empty($appName)) {
            return $this->fail('参数错误');
        }
        $install = new InstallLogic($appName);
        $info = $install->install();
        UserMenuCache::clearMenuCache();
        return $this->success($info);
    }

    /**
     * 卸载插件
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function uninstall(Request $request): Response
    {
        $appName = $request->post("appName", '');
        if (empty($appName)) {
            return $this->fail('参数错误');
        }
        $install = new InstallLogic($appName);
        $warnings = $install->uninstall();
        UserMenuCache::clearMenuCache();
        $message = '卸载插件成功';
        if ($warnings) {
            $message .= '（' . implode(' ', $warnings) . '）';
        }
        return $this->success($message);
    }

    /**
     * 重启
     * @param Request $request
     * @return Response
     */
    public function reload(Request $request): Response
    {
        InstallLogic::restartServer();

        return $this->success('重载成功');
    }

    // ========== 商店代理接口 ==========

    /**
     * 代理请求封装
     */
    protected function proxyRequest(string $url, string $method = 'GET', ?string $token = null, ?array $postData = null, int $timeout = 10): array
    {
        $headers = [];
        if ($token) {
            $headers[] = "Authorization: Bearer {$token}";
        }
        if ($postData !== null) {
            $headers[] = "Content-Type: application/json";
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $postData ? json_encode($postData) : null,
                'timeout' => $timeout,
                // 让 4xx/5xx 也把响应体读回来，而不是直接返回 false，便于给出准确的错误提示
                'ignore_errors' => true,
            ],
            'ssl' => [
                // 这个通道下载回来的 zip 会被解压、其中的 PHP 复制进 plugin/ 并执行，
                // 关掉证书校验等于把中间人攻击直接升级成远程代码执行，必须开启
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $http_response_header = [];
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last()['message'] ?? '';
            if (stripos($error, 'ssl') !== false || stripos($error, 'certificate') !== false) {
                return ['success' => false, 'message' => 'HTTPS 证书校验失败，请检查 php.ini 中的 openssl.cafile 配置'];
            }
            return ['success' => false, 'message' => '请求失败' . ($error ? '：' . $error : '')];
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $match)) {
            $status = (int) $match[1];
        }

        // 尝试解析 JSON
        $data = json_decode($response, true);
        if (is_array($data) && isset($data['code'])) {
            if ($data['code'] === 200) {
                return ['success' => true, 'data' => $data['data'] ?? null];
            }
            return ['success' => false, 'message' => $data['message'] ?? '请求失败'];
        }

        // 网关错误页之类的非 JSON 错误响应不能当成"文件"往下传，
        // 否则会被原样写成 .zip，最后在解压时报一句看不懂的错
        if ($status >= 400) {
            return ['success' => false, 'message' => '远程服务返回错误（HTTP ' . $status . '）'];
        }

        // 非 JSON 响应（可能是文件）
        return ['success' => true, 'raw' => $response, 'headers' => $http_response_header];
    }

    /**
     * 获取应用商店列表
     */
    public function appList(Request $request): Response
    {
        $params = http_build_query([
            'page' => $request->input('page', 1),
            'limit' => $request->input('limit', 16),
            'price' => $request->input('price', 'all'),
            'type' => $request->input('type', ''),
            'keywords' => $request->input('keywords', ''),
        ]);

        $result = $this->proxyRequest("https://saas.saithink.top/dev-api/app/saistore/api/store/appList?{$params}");

        return $result['success']
            ? $this->success($result['data'])
            : $this->fail($result['message']);
    }

    /**
     * 获取商店验证码
     */
    public function storeCaptcha(): Response
    {
        $result = $this->proxyRequest("https://saas.saithink.top/dev-api/app/saiuser/api/common/index/captcha");

        return $result['success']
            ? $this->success($result['data'])
            : $this->fail($result['message']);
    }

    /**
     * 商店登录
     */
    public function storeLogin(Request $request): Response
    {
        $result = $this->proxyRequest(
            "https://saas.saithink.top/dev-api/app/saiuser/api/common/index/accountLogin",
            'POST',
            null,
            [
                'username' => $request->input('username'),
                'password' => $request->input('password'),
                'code' => $request->input('code'),
                'uuid' => $request->input('uuid'),
            ]
        );

        return $result['success']
            ? $this->success($result['data'])
            : $this->fail($result['message']);
    }

    /**
     * 获取商店用户信息
     */
    public function storeUserInfo(Request $request): Response
    {
        $token = $request->input('token');
        if (empty($token)) {
            return $this->fail('未登录');
        }

        $result = $this->proxyRequest(
            "https://saas.saithink.top/dev-api/app/saiuser/api/user/user/userInfo",
            'GET',
            $token
        );

        return $result['success']
            ? $this->success($result['data'])
            : $this->fail($result['message']);
    }

    /**
     * 获取已购应用列表
     */
    public function storePurchasedApps(Request $request): Response
    {
        $token = $request->input('token');
        if (empty($token)) {
            return $this->fail('未登录');
        }

        $result = $this->proxyRequest(
            "https://saas.saithink.top/dev-api/app/saistore/api/StoreOrder/orderList?saiType=all",
            'GET',
            $token
        );

        return $result['success']
            ? $this->success($result['data'])
            : $this->fail($result['message']);
    }

    /**
     * 获取应用版本列表
     */
    public function storeAppVersions(Request $request): Response
    {
        $token = $request->input('token');
        $appId = $request->input('app_id');

        if (empty($token)) {
            return $this->fail('未登录');
        }

        $result = $this->proxyRequest(
            "https://saas.saithink.top/dev-api/app/saistore/api/StoreOrder/appVersionList?app_id={$appId}",
            'GET',
            $token
        );

        return $result['success']
            ? $this->success($result['data'])
            : $this->fail($result['message']);
    }

    /**
     * 下载应用 - 下载并调用 InstallLogic 处理
     */
    public function storeDownloadApp(Request $request): Response
    {
        $token = $request->input('token');
        $versionId = $request->input('id');

        if (empty($token)) {
            return $this->fail('未登录');
        }

        if (empty($versionId)) {
            return $this->fail('版本ID不能为空');
        }

        $result = $this->proxyRequest(
            "https://saas.saithink.top/dev-api/app/saistore/api/StoreOrder/downloadVersion",
            'POST',
            $token,
            ['version_id' => (int) $versionId],
            60
        );

        if (!$result['success']) {
            return $this->fail($result['message'] ?? '下载失败');
        }

        if (!isset($result['raw'])) {
            return $this->fail('下载失败');
        }

        // 落盘前先确认它真的是个 zip
        if (strncmp($result['raw'], "PK\x03\x04", 4) !== 0) {
            return $this->fail('下载到的内容不是有效的插件包，请稍后重试');
        }

        // 保存临时 zip 文件
        $tempZip = runtime_path() . DIRECTORY_SEPARATOR . 'saipackage' . DIRECTORY_SEPARATOR . 'downloadTemp' . date('YmdHis') . mt_rand(1000, 9999) . '.zip';
        if (!is_dir(dirname($tempZip))) {
            mkdir(dirname($tempZip), 0755, true);
        }
        file_put_contents($tempZip, $result['raw']);

        try {
            // 调用 InstallLogic 处理
            $install = new InstallLogic();
            $info = $install->uploadFromPath($tempZip);

            return $this->success($info, '下载成功，请在插件列表中安装');
        } catch (Throwable $e) {
            @unlink($tempZip);
            return $this->fail($e->getMessage());
        }
    }
}
