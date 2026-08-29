<?php

namespace plugin\saipackage\app\logic;

use Phinx\Config\Config;
use Phinx\Util\Util;
use plugin\saiadmin\exception\ApiException;
use plugin\saiadmin\utils\PhinxRunner;
use support\Db;
use Throwable;

/**
 * 单个插件的 Phinx 迁移执行器
 *
 * 新版插件包把建表/菜单写成 db/migrations 下的 Phinx 迁移，同一个包在 MySQL 和
 * PostgreSQL 上都能装；老包只有 MySQL 专用的 install.sql，仍旧走 Server::importSql()。
 *
 * 逻辑单独放这个文件，是因为 plugin/saipackage 是 composer 包 saithink/saipackage 的
 * 工作副本，composer update 会 copy_dir 覆盖它 —— InstallLogic.php 的改动要尽量小，
 * 覆盖后重新打补丁的成本才低。
 */
class PluginMigrator
{
    /**
     * @var string 插件标识
     */
    protected string $appName;

    public function __construct(string $appName)
    {
        // 标识会被拼进文件路径和表名，必须白名单校验（与 InstallLogic::setAppName() 同规则）
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $appName)) {
            throw new ApiException('插件标识不合法：只允许字母开头，由字母、数字和下划线组成');
        }
        $this->appName = $appName;
    }

    /**
     * 已安装位置的迁移目录（真正执行迁移的地方）
     */
    public function installedDbDir(): string
    {
        return base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $this->appName
            . DIRECTORY_SEPARATOR . 'db';
    }

    /**
     * 暂存包里的迁移目录（复制文件之前用来探测和预检）
     */
    public function stagedDbDir(): string
    {
        return runtime_path() . DIRECTORY_SEPARATOR . 'saipackage' . DIRECTORY_SEPARATOR . $this->appName
            . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $this->appName
            . DIRECTORY_SEPARATOR . 'db';
    }

    /**
     * 定位可用的迁移目录：优先已安装位置，回落暂存包
     * @return string 没有迁移时返回空串
     */
    public function locate(): string
    {
        foreach ([$this->installedDbDir(), $this->stagedDbDir()] as $dir) {
            if ($this->migrationFiles($dir)) {
                return $dir;
            }
        }

        return '';
    }

    /**
     * 目录下的迁移文件
     * @return string[]
     */
    public function migrationFiles(string $dir): array
    {
        return glob($dir . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '*.php') ?: [];
    }

    /**
     * 是否为迁移版插件包 —— 判定安装方式的唯一依据，老包不用改也能被正确识别
     */
    public function hasMigrations(): bool
    {
        return $this->locate() !== '';
    }

    /**
     * 目录下的种子文件
     * @return string[]
     */
    public function seedFiles(string $dir): array
    {
        return glob($dir . DIRECTORY_SEPARATOR . 'seeds' . DIRECTORY_SEPARATOR . '*.php') ?: [];
    }

    /**
     * 是否带种子数据
     */
    public function hasSeeds(): bool
    {
        $dir = $this->locate();
        if ($dir === '') {
            return false;
        }

        return (bool) $this->seedFiles($dir);
    }

    /**
     * 版本表名：每个插件一张，与核心的 phinxlog 隔离，卸载时可以整张丢掉
     */
    public function migrationTable(): string
    {
        return 'phinxlog_' . $this->appName;
    }

    /**
     * 迁移类命名空间，与 webman 的 plugin\ PSR-4 映射一致
     */
    public function migrationNamespace(): string
    {
        return 'plugin\\' . $this->appName . '\\db\\migrations';
    }

    /**
     * 种子类命名空间
     */
    public function seedNamespace(): string
    {
        return 'plugin\\' . $this->appName . '\\db\\seeds';
    }

    /**
     * 拼出这个插件专属的 Phinx 配置
     *
     * 不写临时配置文件、不往 $_ENV 塞参数：直接 require 核心的 phinx.php 拿它的返回值，
     * 复用那一套 .env → adapter/host/port/name/user/pass/schema/charset 的逻辑，
     * 只覆盖 paths 与 migration_table 两处。
     * 用 require 而不是 require_once —— 一次请求里可能要为多个插件反复求值。
     *
     * paths 写成「命名空间 => 路径」是 Phinx 官方支持的写法（NamespaceAwareTrait），
     * 迁移类带上命名空间才不会与核心或其它插件的迁移类重名 ——
     * Manager::getMigrations() 是 require_once + class_exists，重名会触发不可捕获的 fatal。
     */
    public function config(string $dir = ''): Config
    {
        $dir = $dir ?: $this->locate();
        if ($dir === '') {
            throw new ApiException('插件[' . $this->appName . ']没有可用的迁移目录');
        }

        $file = PhinxRunner::corePhinxFile();
        $base = require $file;

        $base['paths'] = [
            'migrations' => [$this->migrationNamespace() => $dir . DIRECTORY_SEPARATOR . 'migrations'],
            'seeds' => [$this->seedNamespace() => $dir . DIRECTORY_SEPARATOR . 'seeds'],
        ];
        // per-environment 的 migration_table 优先于 default_migration_table
        $base['environments']['db']['migration_table'] = $this->migrationTable();

        return new Config($base, $file);
    }

    /**
     * 安装前预检（迁移与种子都查）
     *
     * 目的是把「会打死 worker 的 fatal」提前变成一条能看懂的报错：
     * 重名类与重复声明的 trait 都是 require 阶段的 fatal，try/catch 拦不住。
     * 必须在复制文件之前调用。
     *
     * @throws Throwable
     */
    public function validate(string $dir = ''): bool
    {
        $dir = $dir ?: $this->locate();
        if ($dir === '') {
            throw new ApiException('插件[' . $this->appName . ']没有可用的迁移目录');
        }

        foreach ($this->migrationFiles($dir) as $file) {
            $name = basename($file);

            if (!Util::isValidMigrationFileName($name)) {
                throw new ApiException('插件迁移文件名不合法：' . $name . '，必须形如 20260825000000_create_demo_tables.php');
            }

            $this->validateClassFile('迁移', $file, $this->migrationNamespace(), Util::mapFileNameToClassName($name));
        }

        // 种子文件同样要查，而且比迁移更要紧：Manager::getMigrations() 至少会先做一次重名预检、
        // 抛的是可捕获的异常，而 getSeeds() 是裸的 require_once + class_exists ——
        // 重名类、重复声明的 trait 都会在 require 阶段 fatal，直接打死 worker 进程
        foreach ($this->seedFiles($dir) as $file) {
            $name = basename($file);

            if (!Util::isValidSeedFileName($name)) {
                throw new ApiException('插件种子文件名不合法：' . $name . '，必须是字母开头、只含字母和数字的类名，如 DemoSeeder.php');
            }

            // 种子的类名就是文件名本身（Phinx 用 命名空间 + pathinfo(FILENAME) 拼），
            // 不像迁移那样要去掉时间戳前缀
            $this->validateClassFile('种子', $file, $this->seedNamespace(), pathinfo($name, PATHINFO_FILENAME));
        }

        return true;
    }

    /**
     * 迁移与种子共用的文件检查：命名空间声明、自带 SaiSchema 副本、类名冲突
     *
     * @param string $label     报错里的称呼（迁移 / 种子）
     * @param string $file      文件绝对路径
     * @param string $namespace 该类型要求的命名空间
     * @param string $className Phinx 会拿来 class_exists 的类名（不含命名空间）
     * @throws Throwable
     */
    protected function validateClassFile(string $label, string $file, string $namespace, string $className): void
    {
        $name = basename($file);
        $content = (string) file_get_contents($file);

        // 必须带命名空间，否则与核心/其它插件重名时会 fatal
        if (!preg_match('/^\s*namespace\s+' . preg_quote($namespace, '/') . '\s*;/mi', $content)) {
            throw new ApiException('插件' . $label . ' ' . $name . ' 缺少命名空间声明，必须是 namespace ' . $namespace . ';');
        }

        // 插件不能自带 SaiSchema 副本：它是全局 trait，重复声明同样是 fatal
        if (preg_match('/\btrait\s+SaiSchema\b/i', $content)) {
            throw new ApiException('插件' . $label . ' ' . $name . ' 自带了 SaiSchema 副本，请改为 require_once 核心的 plugin/saiadmin/db/support/SaiSchema.php');
        }

        $class = $namespace . '\\' . $className;
        if (class_exists($class, false) && !$this->loadedFromOwnDb($class)) {
            throw new ApiException('插件' . $label . '类 ' . $class . ' 与已加载的类重名，请修改文件名');
        }
    }

    /**
     * 已加载的这个类是不是本插件自己的迁移/种子
     *
     * 安装失败后不会重启进程，同一个 worker 里再点一次安装时，上一轮 require 进来的迁移类
     * 还在内存里。这时候光看 class_exists 会把自己认成「重名」，报一句「请修改文件名」，
     * 除了重启没有别的出路。类文件就在本插件的 db 目录下（暂存或已安装）就说明是自己人 ——
     * 命名空间按插件隔离，别人的类不可能落在这里，放过它不会削弱重名保护
     */
    protected function loadedFromOwnDb(string $class): bool
    {
        try {
            $loadedFrom = (new \ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return false;
        }
        if (!$loadedFrom) {
            return false;
        }

        $normalize = static fn (string $path): string => str_replace('\\', '/', realpath($path) ?: $path);
        $loadedFrom = $normalize($loadedFrom);
        foreach ([$this->installedDbDir(), $this->stagedDbDir()] as $ownDir) {
            if (str_starts_with($loadedFrom, $normalize($ownDir) . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 执行迁移（首装与升级都是同一条命令，靠 phinxlog_{app} 记录进度）
     * @throws Throwable
     */
    public function migrate(array $args = [], string $dir = ''): string
    {
        return $this->exec('migrate', $args, $dir);
    }

    /**
     * 灌种子数据（不指定 --seed 时会跑遍 seeds 目录）
     * @throws Throwable
     */
    public function seed(array $args = [], string $dir = ''): string
    {
        return $this->exec('seed', $args, $dir);
    }

    /**
     * 回滚到 0：每个迁移的 down() 都会执行，表和菜单真删，与老版 uninstall.sql 行为一致
     * @throws Throwable
     */
    public function rollbackAll(string $dir = ''): string
    {
        return $this->rollback(['--target' => '0', '--force' => true], $dir);
    }

    /**
     * 回滚（命令行按需指定 --target）
     * @throws Throwable
     */
    public function rollback(array $args = [], string $dir = ''): string
    {
        return $this->exec('rollback', $args, $dir);
    }

    /**
     * 迁移状态（命令行排障用）
     */
    public function status(string $dir = ''): array
    {
        return PhinxRunner::run('status', [], $this->config($dir));
    }

    /**
     * 跑一条 Phinx 命令，失败就抛出带尾部日志的异常
     * @throws Throwable
     */
    protected function exec(string $command, array $args = [], string $dir = ''): string
    {
        $result = PhinxRunner::run($command, $args, $this->config($dir));
        if (!$result['ok']) {
            throw new ApiException('插件[' . $this->appName . ']数据库' . $this->actionLabel($command)
                . '失败：' . PhinxRunner::errorTail($result['output'], 500));
        }

        return $result['output'];
    }

    protected function actionLabel(string $command): string
    {
        return match ($command) {
            'migrate' => '迁移',
            'seed' => '初始化',
            'rollback' => '回滚',
            default => $command,
        };
    }

    /**
     * 版本表里还剩几条记录
     * @return int|null null 表示表不存在（或读不到）
     */
    public function logTableCount(): ?int
    {
        try {
            // 裸 SQL：绕开 Eloquent 的表前缀处理，Phinx 建表时也不带前缀
            $rows = Db::select('select count(*) as c from ' . $this->migrationTable());
        } catch (Throwable) {
            return null;
        }
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        $row = (array) $row;

        return (int) ($row['c'] ?? 0);
    }

    /**
     * 删掉版本表
     *
     * 只在表确实空了时才删：Phinx 的 rollback 即使什么都没干也返回 0，
     * 表里还有记录说明有迁移没回滚干净，这时候删表等于把痕迹一起抹掉，后面没法排查。
     */
    public function dropLogTable(): bool
    {
        $count = $this->logTableCount();
        if ($count === null) {
            // 表本来就不存在
            return true;
        }
        if ($count > 0) {
            return false;
        }
        try {
            Db::statement('drop table ' . $this->migrationTable());
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
