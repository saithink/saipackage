<?php

namespace Saithink\Saipackage\service;

use plugin\saipackage\app\logic\InstallLogic;
use Tinywan\Jwt\JwtToken;
use Throwable;

class Terminal
{
    /**
     * @var string 当前执行的命令 $command 的 key
     */
    protected string $commandKey = '';

    /**
     * @var array proc_open 的参数
     */
    protected array $descriptorsPec = [];

    /**
     * @var resource|bool proc_open 返回的 resource
     */
    protected $process = false;

    /**
     * @var array proc_open 的管道
     */
    protected array $pipes = [];

    /**
     * @var int proc执行状态:0=未执行,1=执行中,2=执行完毕
     */
    protected int $procStatusMark = 0;

    /**
     * @var array proc执行状态数据
     */
    protected array $procStatusData = [];

    /**
     * @var string 命令在前台的uuid
     */
    protected string $uuid = '';

    /**
     * @var string 扩展信息
     */
    protected string $extend = '';

    /**
     * @var string 命令执行输出文件
     */
    protected string $outputFile = '';

    /**
     * @var string 命令执行实时输出内容
     */
    protected string $outputContent = '';

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->uuid   = request()->input('uuid', '');
        $this->extend = request()->input('extend', '');

        // 初始化日志文件
        $outputDir = runtime_path() . DIRECTORY_SEPARATOR . 'terminal';
        $this->outputFile = $outputDir . DIRECTORY_SEPARATOR . 'exec.log';

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        file_put_contents($this->outputFile, '');

        $this->descriptorsPec = [0 => ['pipe', 'r'], 1 => ['file', $this->outputFile, 'w'], 2 => ['file', $this->outputFile, 'w']];
    }

    /**
     * 获取命令配置
     *
     * @param string $key 命令key
     *
     * @return array|bool
     */
    public static function getCommand(string $key): bool|array
    {
        if (!$key) {
            return false;
        }

        $commands = config('plugin.saipackage.terminal.commands', []);
        if (stripos($key, '.')) {
            $keyParts = explode('.', $key);
            if (!isset($commands[$keyParts[0]]) || !is_array($commands[$keyParts[0]]) || !isset($commands[$keyParts[0]][$keyParts[1]])) {
                return false;
            }
            $command = $commands[$keyParts[0]][$keyParts[1]];
        } else {
            if (!isset($commands[$key])) {
                return false;
            }
            $command = $commands[$key];
        }

        if (!is_array($command)) {
            $command = [
                'cwd'     => base_path(),
                'command' => $command,
            ];
        } else {
            $command = [
                'cwd'     => $command['cwd'],
                'command' => $command['command'],
            ];
        }

        $command['cwd'] = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $command['cwd']);
        return $command;
    }

    /**
     * 执行命令
     * @throws Throwable
     */
    public function exec($auth = true)
    {
        $this->commandKey = request()->input('command');
        $command          = self::getCommand($this->commandKey);

        if (!$command) {
            yield $this->output('The command was not allowed to be executed');
            return;
        }

        if ($auth) {
            try {
                $token = request()->input('token');
                $extend = JwtToken::verify(1, $token)['extend'];
                if ($extend['id'] > 1) {
                    yield $this->output('Error: No permission');
                    yield $this->output('exec-error');
                    return;
                }
            } catch (Throwable $e) {
                yield $this->output('Error: Authentication failed');
                yield $this->output('exec-error');
                return;
            }
        }

        $this->beforeExecution();
        yield $this->output('connection-success');

        yield $this->output('> ' . $command['command']);

        if (!is_dir($command['cwd'])) {
            yield $this->output('Error: dir not exist');
            yield $this->output('exec-error');
            return;
        }

        try {
            $this->process = proc_open($command['command'], $this->descriptorsPec, $this->pipes, $command['cwd']);
        } catch (Throwable $e) {
            yield $this->output('Error: ' . $e->getMessage());
            yield $this->output('exec-error');
            return;
        }

        $this->outputContent = file_get_contents($this->outputFile);
        while ($this->getProcStatus()) {
            $contents = file_get_contents($this->outputFile);
            if ($contents !== $this->outputContent) {
                $newOutput = substr($contents, strlen($this->outputContent));
                if (preg_match('/\r\n|\r|\n/', $newOutput)) {
                    yield $this->output($newOutput);
                    $this->outputContent = $contents;
                }
            }

            usleep(500000);
        }

        yield $this->output('exitCode: ' . $this->procStatusData['exitcode']);
        if ($this->procStatusData['exitcode'] === 0) {
            if ($this->successCallback()) {
                yield $this->output('exec-success');
            } else {
                yield $this->output('Error: Command execution succeeded, but callback execution failed');
                yield $this->output('exec-error');
            }
        } else {
            yield $this->output('exec-error');
        }

        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($this->process);

        yield $this->output('exec-completed');

    }

    /**
     * 获取执行状态
     * @throws Throwable
     */
    public function getProcStatus(): bool
    {
        $this->procStatusData = proc_get_status($this->process);
        if ($this->procStatusData['running']) {
            $this->procStatusMark = 1;
            return true;
        } elseif ($this->procStatusMark === 1) {
            $this->procStatusMark = 2;
            return true;
        } else {
            return false;
        }
    }

    /**
     * 输出 EventSource 数据
     * @param string $data
     * @return string
     */
    public function output(string $data): string
    {
        $data = [
            'data'   => $data,
            'uuid'   => $this->uuid,
            'extend' => $this->extend,
            'key'    => $this->commandKey,
        ];
        $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($data === false) {
            $data = json_encode(['error' => 'JSON encode error'], JSON_UNESCAPED_UNICODE);
        }
        return $data;
    }

    /**
     * 成功后回调
     * @return bool
     * @throws Throwable
     */
    public function successCallback(): bool
    {
        if (stripos($this->commandKey, '.')) {
            $commandKeyArr = explode('.', $this->commandKey);
            $commandPKey   = $commandKeyArr[0] ?? '';
        } else {
            $commandPKey = $this->commandKey;
        }

        if ($commandPKey == 'web-build') {
            if (!self::mvDist()) {
                return false;
            }
        } elseif ($commandPKey == 'web-install' && $this->extend) {
            [$type, $value] = explode(':', $this->extend);
            if ($type == 'module-install' && $value) {
                $install = new InstallLogic($value);
                $install->dependentInstallComplete('npm');
            }
        } elseif ($commandPKey == 'composer' && $this->extend) {
            [$type, $value] = explode(':', $this->extend);
            if ($type == 'module-install' && $value) {
                $install = new InstallLogic($value);
                $install->dependentInstallComplete('composer');
                // 重启后端
                Server::restart();
            }
        }
        return true;
    }

    /**
     * 执行前埋点
     */
    public function beforeExecution(): void
    {
        if ($this->commandKey == 'test.pnpm') {
            @unlink(public_path() . DIRECTORY_SEPARATOR . 'npm-install-test' . DIRECTORY_SEPARATOR . 'pnpm-lock.yaml');
        } elseif ($this->commandKey == 'web-install.pnpm') {
            @unlink(dirname(base_path()) . DIRECTORY_SEPARATOR . env('FRONTEND_DIR', 'saiadmin-vue') . DIRECTORY_SEPARATOR . 'pnpm-lock.yaml');
        }
    }

    /**
     * 输出过滤
     */
    public static function outputFilter($str): string
    {
        $str  = trim($str);
        $preg = '/\[(.*?)m/i';
        $str  = preg_replace($preg, '', $str);
        $str  = str_replace(["\r\n", "\r", "\n"], "\n", $str);
        return mb_convert_encoding($str, 'UTF-8', 'UTF-8,GBK,GB2312,BIG5');
    }

    /**
     * 执行一个命令并以字符串的方式返回执行输出
     * 代替 exec 使用，这样就只需要解除 proc_open 的函数禁用了
     * @param $commandKey
     * @return string|bool
     */
    public static function getOutputFromProc($commandKey): bool|string
    {
        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            return false;
        }
        $command = self::getCommand($commandKey);
        if (!$command) {
            return false;
        }
        $descriptorsPec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process        = proc_open($command['command'], $descriptorsPec, $pipes, null, null);
        if (is_resource($process)) {
            $info = stream_get_contents($pipes[1]);
            $info .= stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return self::outputFilter($info);
        }
        return '';
    }

    public static function mvDist(): bool
    {
        $distPath      = dirname(base_path()) . DIRECTORY_SEPARATOR . env('FRONTEND_DIR', 'saiadmin-vue') . DIRECTORY_SEPARATOR . 'dist';
        $indexHtmlPath = $distPath . DIRECTORY_SEPARATOR . 'index.html';
        $assetsPath    = $distPath . DIRECTORY_SEPARATOR . 'assets';
        if (!file_exists($indexHtmlPath) || !file_exists($assetsPath)) {
            return false;
        }

        $toIndexHtmlPath = public_path() . DIRECTORY_SEPARATOR . 'index.html';
        $toAssetsPath    = public_path() . DIRECTORY_SEPARATOR . 'assets';
        @unlink($toIndexHtmlPath);
        Filesystem::delDir($toAssetsPath);

        if (rename($indexHtmlPath, $toIndexHtmlPath) && rename($assetsPath, $toAssetsPath)) {
            Filesystem::delDir($distPath);
            return true;
        } else {
            return false;
        }
    }

}