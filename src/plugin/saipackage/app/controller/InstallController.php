<?php

namespace plugin\saipackage\app\controller;

use plugin\saiadmin\app\middleware\SystemLog;
use plugin\saiadmin\app\middleware\CheckLogin;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\exception\ApiException;
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
            throw new ApiException('仅超级管理员能够操作');
        }
    }

    /**
     * 环境检查状态
     */
    static string $ok   = 'ok';
    static string $fail = 'fail';
    static string $warn = 'warn';

    static array $needDependentVersion = [
        'php'  => '8.1.0',
        'saiadmin'  => '5.0.1',
        'saipackage' => '1.0.0',
    ];

    /**
     * 应用列表
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $data = Server::installedList(runtime_path() . DIRECTORY_SEPARATOR . 'saipackage' . DIRECTORY_SEPARATOR);

        $phpVersion        = phpversion();
        $phpVersionCompare = Version::compare(self::$needDependentVersion['php'], $phpVersion);
        $phpVersionNotes = '正常';
        if (!$phpVersionCompare) {
            $phpVersionNotes = '需要版本' . ' >= ' . self::$needDependentVersion['php'];
        }

        $saiadminVersion        = config('plugin.saiadmin.app.version');
        $saiadminVersionCompare = Version::compare(self::$needDependentVersion['saiadmin'], $saiadminVersion);
        $saiadminVersionNotes = '正常';
        if (!$saiadminVersionCompare) {
            $saiadminVersionNotes = '需要版本' . ' >= ' . self::$needDependentVersion['saiadmin'];
        }

        $saithinkVersion        = config('plugin.saipackage.app.version');
        $saithinkVersionCompare = Version::compare(self::$needDependentVersion['saipackage'], $saithinkVersion);
        $saithinkVersionNotes = '正常';
        if (!$saithinkVersionCompare) {
            $saithinkVersionNotes = '需要版本' . ' >= ' . self::$needDependentVersion['saipackage'];
        }


        return $this->success([
            'version' => [
                'php_version' => [
                    'describe' => $phpVersion,
                    'state'  => $phpVersionCompare ? self::$ok : self::$fail,
                    'notes'   => $phpVersionNotes,
                ],
                'saiadmin_version' => [
                    'describe' => $saiadminVersion,
                    'state'  => $saiadminVersionCompare ? self::$ok : self::$fail,
                    'notes'   => $saiadminVersionNotes,
                ],
                'saipackage_version' => [
                    'describe' => $saithinkVersion,
                    'state'  => $saithinkVersionCompare ? self::$ok : self::$fail,
                    'notes'   => $saithinkVersionNotes,
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
        $spl_file  = current($request->file());
        if (!$spl_file->isValid()) {
            return $this->fail('上传文件校验失败');
        }
        $config = config('plugin.saipackage.upload', [
            'size' => 1024 * 1024 * 5,
            'type' => ['zip']
        ]);
        if (!in_array($spl_file->getUploadExtension(), $config['type'])) {
            return $this->fail('文件格式上传失败,请选择zip格式文件上传');
        }
        if ($spl_file->getSize() > $config['size']) {
            return $this->fail('文件大小不能超过5M');
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
        $appName  = $request->post("appName", '');
        if (empty($appName)) {
            return $this->fail('参数错误');
        }
        $install = new InstallLogic($appName);
        $info = $install->install();
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
        $appName  = $request->post("appName", '');
        if (empty($appName)) {
            return $this->fail('参数错误');
        }
        $install = new InstallLogic($appName);
        $install->uninstall();
        return $this->success('卸载插件成功');
    }

    /**
     * 重启
     * @param Request $request
     * @return Response
     */
    public function reload(Request $request): Response
    {
        Server::restart();

        return $this->success('重载成功');
    }

}
