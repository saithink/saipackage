<?php

namespace plugin\saipackage\app\logic;

use Throwable;
use Saithink\Saipackage\management\Factory;
use Saithink\Saipackage\management\Manifest;
use Workerman\Timer;
use Saithink\Saipackage\service\Server;
use Saithink\Saipackage\service\Version;
use Saithink\Saipackage\service\Filesystem;
use Saithink\Saipackage\service\Depends;
use plugin\saiadmin\exception\ApiException;
use plugin\saiadmin\app\cache\UserMenuCache;
use plugin\saiadmin\utils\DbType;

class InstallLogic
{
    public const UNINSTALLED = 0;
    public const INSTALLED = 1;
    public const WAIT_INSTALL = 2;
    public const CONFLICT_PENDING = 3;
    public const DEPENDENT_WAIT_INSTALL = 4;
    public const DIRECTORY_OCCUPIED = 5;

    /**
     * @var string 安装目录
     */
    protected string $installDir;

    /**
     * @var string 备份目录
     */
    protected string $backupsDir;

    /**
     * @var string 插件名称
     */
    protected string $appName;

    /**
     * @var string 插件根目录
     */
    protected string $appDir;

    public function __construct(string $appName = '')
    {
        $this->installDir = runtime_path() . DIRECTORY_SEPARATOR . 'saipackage' . DIRECTORY_SEPARATOR;
        $this->backupsDir = $this->installDir . 'backups' . DIRECTORY_SEPARATOR;
        if (!is_dir($this->installDir)) {
            mkdir($this->installDir, 0755, true);
        }
        if (!is_dir($this->backupsDir)) {
            mkdir($this->backupsDir, 0755, true);
        }

        if ($appName) {
            $this->setAppName($appName);
        }
    }

    /**
     * 设置插件标识
     *
     * 标识会被直接拼进文件路径（runtime 暂存目录、plugin 目录、前端 views 目录），
     * 且来源是请求参数或压缩包内的 info.ini，必须做白名单校验，
     * 否则 `../` 之类的值会造成目录穿越，卸载时甚至能删掉任意目录
     *
     * @throws Throwable
     */
    protected function setAppName(string $appName): void
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $appName)) {
            throw new ApiException('插件标识不合法：只允许字母开头，由字母、数字和下划线组成');
        }
        $this->appName = $appName;
        $this->appDir  = $this->installDir . $appName . DIRECTORY_SEPARATOR;
    }

    /**
     * 前端项目根目录
     * @return string
     */
    public static function frontendDir(): string
    {
        return dirname(base_path()) . DIRECTORY_SEPARATOR . env('FRONTEND_DIR', 'saiadmin-artd');
    }

    /**
     * 暂停 webman 的文件监控
     *
     * config/process.php 的 monitorDir 里包含 plugin/*\/app 和 plugin/*\/config，
     * 而安装/卸载正好要往这些目录写入或删除文件。监控一旦发现变更，
     * windows.php:132 会用 `taskkill /F /T` 杀掉整棵进程树 ——
     * 正在执行安装的 worker 就在这棵树里，等于请求被自己触发的重启拦腰打断：
     * 浏览器看到连接被拒，插件停在装了一半的状态。
     * 所以整个安装/卸载期间必须先把监控停掉，结束后再统一重启一次。
     *
     * webman 版本较老时 Monitor 可能没有 pause/resume，这里做存在性判断。
     */
    protected static function monitorPause(): void
    {
        if (is_callable([\app\process\Monitor::class, 'pause'])) {
            \app\process\Monitor::pause();
        }
    }

    /**
     * 恢复文件监控
     * 锁文件在 runtime/monitor.lock，万一残留会导致热重载一直失效，手动删掉即可
     */
    protected static function monitorResume(): void
    {
        if (is_callable([\app\process\Monitor::class, 'resume'])) {
            \app\process\Monitor::resume();
        }
    }

    /**
     * 重启后端
     *
     * Linux 下 Server::restart() 走 posix_kill(SIGUSR1) 平滑重启，没问题。
     * Windows 没有 posix，Server::restart() 会退化成 Worker::stopAll()，
     * 而 windows.php 的守护循环只在「被监控文件发生变更」时才重新拉起子进程，
     * 且 config/process.php 里的 monitorDir 是启动时用 glob 求值的 —— 新装插件的目录不在其中，
     * 于是 stopAll 之后服务就停在那里不再恢复。
     * 因此 Windows 改为触碰一个已被监控的文件，交由守护循环完成重启。
     *
     * @return bool
     */
    public static function restartServer(): bool
    {
        if (DIRECTORY_SEPARATOR === '/') {
            self::monitorResume();
            Timer::add(0.5, static fn () => Server::restart(), [], false);
            return true;
        }

        // 延后 1 秒，先让当前请求的响应发出去，再恢复监控并触发重启。
        // 恢复监控放在这里而不是安装流程末尾，是为了让「响应回传」这段窗口也处于暂停状态，
        // 否则监控可能在响应还没发完时就把进程杀掉。
        // mtime 取 time()+1 是因为 Monitor::checkFilesChange() 用的是严格大于比较，
        // 同一秒内的改动会被漏掉
        Timer::add(1, function () {
            self::monitorResume();
            @touch(config_path() . DIRECTORY_SEPARATOR . 'app.php', time() + 1);
        }, [], false);

        return true;
    }

    public function getInstallState()
    {
        if (!is_dir($this->appDir)) {
            return self::UNINSTALLED;
        }
        $info = $this->getInfo();
        if ($info && isset($info['state'])) {
            return $info['state'];
        }

        // 目录已存在，但非正常的模块
        return Filesystem::dirIsEmpty($this->appDir) ? self::UNINSTALLED : self::DIRECTORY_OCCUPIED;
    }

    /**
     * 获取允许覆盖的目录
     * @return string[]
     */
    public function getAllowedPath(): array
    {
        $backend = 'plugin' . DIRECTORY_SEPARATOR . $this->appName;
        $frontend = env('FRONTEND_DIR', 'saiadmin-artd') . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $this->appName;
        return [
            $this->appDir . $backend => base_path() . DIRECTORY_SEPARATOR . $backend,
            $this->appDir . $frontend => dirname(base_path()) . DIRECTORY_SEPARATOR . $frontend
        ];
    }

    /**
     * @var PluginMigrator|null 迁移执行器，惰性构造
     */
    protected ?PluginMigrator $migratorInstance = null;

    /**
     * PostgreSQL 下遇到老版 SQL 插件时的提示
     */
    public const PGSQL_UNSUPPORTED = '老版本插件不支持 PostgreSQL：该插件包只提供 MySQL 的 install.sql，没有 db/migrations 迁移文件，当前数据库为 PostgreSQL，已终止安装（未复制任何文件、未改动数据库）。请向插件作者索取支持 Phinx 迁移的新版插件包。';

    /**
     * 本插件的迁移执行器
     */
    public function migrator(): PluginMigrator
    {
        if ($this->migratorInstance === null) {
            $this->migratorInstance = new PluginMigrator($this->appName);
        }

        return $this->migratorInstance;
    }

    /**
     * 取得该插件的操作锁，用返回值的生命周期控制持有时间
     *
     * 安装、卸载、上传三条流程共用一把锁：它们都在动同一份暂存包和同一批正式目录。
     * 调用方把返回值放进局部变量即可，方法退出（正常或异常）时自动释放
     * @throws Throwable
     */
    protected function acquireLock(): InstallLock
    {
        return new InstallLock(
            $this->installDir . '.' . $this->appName . '.lock',
            '插件[' . $this->appName . ']正在被另一个请求处理（安装 / 卸载 / 上传），请稍后重试'
        );
    }

    /**
     * 插件的数据库兼容性，供插件列表接口展示
     * @return array{has_migration: int, db_compatible: int, db_notes: string}
     */
    public static function dbSupport(string $appName): array
    {
        try {
            $migrator = new PluginMigrator($appName);
            // 与 install() 的判定保持一致：列表里的行来自暂存包，安装时也以暂存包为准。
            // 暂存载荷被手工删掉时回落 locate()，至少还能按已安装的那份显示
            $hasMigration = $migrator->migrationFiles($migrator->stagedDbDir())
                ? true
                : $migrator->hasMigrations();
        } catch (Throwable) {
            $hasMigration = false;
        }

        if ($hasMigration) {
            return ['has_migration' => 1, 'db_compatible' => 1, 'db_notes' => '迁移安装（MySQL / PostgreSQL）'];
        }
        if (DbType::isPgsql()) {
            return ['has_migration' => 0, 'db_compatible' => 0, 'db_notes' => '不兼容：老版本插件不支持 PostgreSQL'];
        }

        return ['has_migration' => 0, 'db_compatible' => 1, 'db_notes' => 'SQL 安装（仅 MySQL）'];
    }

    /**
     * 复制插件文件到正式目录
     *
     * 不直接用 Server::installByRelation()：它用 strrpos($dest, '/') 定位父目录，
     * Windows 路径分隔符是 \，永远匹配不到，父目录建不出来，
     * 而 webman 的 copy_dir() 内部是非递归的 mkdir()，父目录不存在时会静默失败
     *
     * @throws Throwable
     */
    protected function installFiles(): void
    {
        $paths = $this->getAllowedPath();

        // 升级时先把旧目录整个删掉再复制。copy_dir() 只覆盖同名文件，不会删多余的：
        // 上游改名或删掉的文件会永久留在正式目录里，旧控制器照样被路由和注解扫到，
        // 前端也可能还引用着已经废弃的组件 —— 装完看着是新版，跑起来是新旧混合体。
        // 删之前先打一个包，出问题能捞回来
        $clean = !empty($this->getInfo()['update']);
        if ($clean) {
            $failed = $this->backupInstalled('upgrade', $paths);
            if ($failed !== '') {
                // 没有副本还硬删线上代码风险太大，退回「只覆盖不删除」的老行为
                echo '备份失败，跳过旧文件清理：' . $failed . PHP_EOL;
                $clean = false;
            }
        }

        foreach ($paths as $source => $dest) {
            if ($clean && is_dir($dest)) {
                Filesystem::delDir($dest);
                if (is_dir($dest)) throw new ApiException('旧插件文件未能清理，请检查权限后恢复任务');
            }
            if (!is_dir($source)) {
                // A release can remove its entire frontend/backend subtree; the backed-up old tree must go too.
                continue;
            }
            $parent = dirname($dest);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new ApiException('目录创建失败，请检查写入权限：' . $parent);
            }
            copy_dir($source, $dest, true);
        }
        $this->assertInstalledFiles();
    }

    /** Do not activate a partial copy or finalize a recovery against a different deployed payload. */
    protected function assertInstalledFiles(): void
    {
        foreach ($this->getAllowedPath() as $source => $dest) {
            if (!is_dir($source)) continue;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile()) continue;
                $target = $dest . substr($file->getPathname(), strlen($source));
                if (!is_file($target) || is_link($target) || hash_file('sha256', $file->getPathname()) !== hash_file('sha256', $target)) throw new ApiException('插件文件复制不完整，请检查权限后恢复任务');
            }
        }
    }

    /**
     * 把已安装的插件文件打包到 runtime/saipackage/backups
     *
     * 升级前（installFiles() 要删旧目录）和卸载前都得留一份副本。
     * 失败只回报原因、不抛异常：备份不该把升级和卸载堵死 ——
     * 卸载被堵住的话用户就陷入既装不上也删不掉的死局
     *
     * @param string $tag   文件名里的场景标记
     * @param array  $paths getAllowedPath() 的结果
     * @return string 失败原因，成功或无可备份的目录时为空串
     */
    protected function backupInstalled(string $tag, array $paths): string
    {
        $backFiles = [];
        $index = 1;
        foreach ($paths as $dest) {
            if (is_dir($dest)) {
                $backFiles[$this->appName . '-' . $index] = $dest;
                $index++;
            }
        }
        if (!$backFiles) {
            return '';
        }

        $zip = $this->backupsDir . $this->appName . '-' . $tag . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.zip';
        try {
            // zipDir() 打包失败会抛异常，写不出文件时返回 false，两种都要接
            if (!Filesystem::zipDir($backFiles, $zip)) {
                return '备份文件未能写入：' . $zip;
            }
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return '';
    }

    /**
     * 上传安装
     * @param mixed $file
     * @return array 模块的基本信息
     * @throws Throwable
     */
    public function upload(mixed $file): array
    {
        // 加随机后缀，避免同一秒内的并发上传互相覆盖
        $copyTo = $this->installDir . 'uploadTemp' . date('YmdHis') . mt_rand(1000, 9999) . '.zip';
        $file->move($copyTo);

        return $this->unpack($copyTo);
    }

    /**
     * 从本地 zip 文件路径安装（用于在线下载后安装）
     * @param string $zipPath zip 文件完整路径
     * @return array 模块的基本信息
     * @throws Throwable
     */
    public function uploadFromPath(string $zipPath): array
    {
        if (!is_file($zipPath)) {
            throw new ApiException('文件不存在');
        }

        return $this->unpack($zipPath);
    }

    /**
     * 解包并放入待安装状态
     * @param string $zipPath zip 文件完整路径，处理完会被删除
     * @return array 模块的基本信息
     * @throws Throwable
     */
    protected function unpack(string $zipPath): array
    {
        $management = Factory::enabled() ? Factory::manager() : null;
        $globalLock = $management?->lock();
        $management?->importLegacy();
        // 解压
        // Extract on the destination filesystem; rename() cannot move directories across mounts.
        $unpackDir = $this->installDir . '.unpack-' . bin2hex(random_bytes(12));
        try {
            $copyToDir = Filesystem::unzip($zipPath, $unpackDir) . DIRECTORY_SEPARATOR;
        } catch (Throwable $e) {
            if (is_dir($unpackDir)) Filesystem::delDir($unpackDir);
            throw $e;
        }

        // 删除 zip
        @unlink($zipPath);

        // 读取 ini
        $info = Server::getIni($copyToDir);

        try {
            // info.ini 的完整性必须在这里查完 —— 再往下走就会删掉 $this->appDir，
            // 而升级场景下那个目录是已安装插件的唯一记录（插件列表、卸载入口都靠它）。
            // 原来是 rename 之后才 checkPackage()，包里缺个 about 就把已安装插件的记录删了，
            // 插件从列表消失、getInstallState() 变回未安装，代码和数据库却还在，彻底没法管
            $this->checkPackageInfo($info);
            Manifest::read($copyToDir, $info);
            Manifest::schema($copyToDir);
            $this->setAppName($info['app']);
        } catch (Throwable $e) {
            Filesystem::delDir($copyToDir);
            throw $e;
        }

        // 拿到标识之后立刻上锁：下面要删掉 $this->appDir 再 rename，
        // 与正在读同一个暂存包的安装流程并发就会看到半个包。
        // $lock 一直活到方法返回，析构时自动释放
        try {
            $lock = $this->acquireLock();
        } catch (Throwable $e) {
            Filesystem::delDir($copyToDir);
            throw $e;
        }

        $upgrade = false;
        $installed = $management?->store->get('installations', $this->appName)['value'] ?? null;
        if ($installed && $installed['state'] !== 'uninstalled') {
            $upgrade = Manifest::matches((string) ($info['version'] ?? ''), '>' . $installed['manifest']['version']);
            if (!$upgrade) {
                Filesystem::delDir($copyToDir);
                throw new ApiException('插件已经存在');
            }
        }
        if (is_dir($this->appDir)) {
            $oldInfo = $this->getInfo();
            if ($oldInfo && !empty($oldInfo['app'])) {
                // 补齐到三段再抬补丁号。原来只有 isset($versions[2]) 才 ++，
                // 版本号写成两段（如 6.1）时 nextVersion 与旧版本完全相同，
                // 而 Version::compare() 相等即为真 —— 重复上传同一个包会被当成升级，
                // 直接把线上那份覆盖掉（升级路径还会删旧目录）
                $baseline = $installed && $installed['state'] !== 'uninstalled' ? $installed['manifest']['version'] : ($oldInfo['version'] ?? '');
                $old = ltrim((string) $baseline, 'vV') ?: '0.0.0';
                $versions = array_slice(array_pad(explode('.', $old), 3, '0'), 0, 3);
                $versions[2] = (int) $versions[2] + 1;
                $nextVersion = implode('.', $versions);
                // Managed releases use SemVer, including beta -> stable without a patch bump.
                $upgrade = $management
                    ? Manifest::matches((string) ($info['version'] ?? ''), '>' . $old)
                    : Version::compare($nextVersion, $info['version'] ?? '');
                if (!$upgrade) {
                    Filesystem::delDir($copyToDir);
                    throw new ApiException('插件已经存在');
                }
            }

            // 只有「目录非空且不是升级」才算被占用。
            // 空目录是上一次安装失败留下的残骸，直接清掉即可 ——
            // 原来的判断把空目录也当成被占用，而它又因为没有 info.ini 不会出现在插件列表里，
            // 用户既装不上也卸不掉，只能手工去 runtime 下删
            if (!Filesystem::dirIsEmpty($this->appDir) && !$upgrade) {
                Filesystem::delDir($copyToDir);
                throw new ApiException('该插件的安装目录已经被占用');
            }

        }

        $newInfo = ['state' => self::WAIT_INSTALL];
        if ($upgrade) {
            $newInfo['update'] = 1;
        }

        // Keep the old staging record until the replacement is registered, including rename failures.
        $previous = $this->installDir . '.previous-' . bin2hex(random_bytes(12));
        $hadPrevious = is_dir($this->appDir);
        if ($hadPrevious && !rename(rtrim($this->appDir, DIRECTORY_SEPARATOR), $previous)) {
            Filesystem::delDir($copyToDir);
            throw new ApiException('插件暂存记录备份失败，请检查 runtime 目录权限');
        }
        try {
            if (!rename($copyToDir, $this->appDir)) throw new ApiException('插件文件写入失败，请检查 runtime 目录的写入权限');
            // state 由安装器维护，不该要求打包方预置，所以放在完整性校验之后再写
            if (!$this->setInfo($newInfo)) throw new ApiException('插件暂存状态写入失败');
            $this->checkPackage();
            $management?->registerPackage($this->appName);
        } catch (Throwable $e) {
            Filesystem::delDir($this->appDir);
            if ($hadPrevious && !rename($previous, $this->appDir)) throw new ApiException('插件暂存记录恢复失败，请保留 .previous 目录并人工核查');
            throw $e;
        } finally {
            if (is_dir($copyToDir)) Filesystem::delDir($copyToDir);
        }
        if ($hadPrevious) Filesystem::delDir($previous);

        return $info;
    }


    /**
     * 安装或更新
     * @return array
     * @throws Throwable
     */
    public function install(bool $restart = true, ?array $approvedSnapshot = null, ?callable $progress = null): array
    {
        $management = Factory::enabled() ? Factory::manager() : null;
        $globalLock = $management?->lock();
        $management?->importLegacy();
        $snapshot = $management ? ($approvedSnapshot ? $management->assertPlanStep($this->appName, $approvedSnapshot) : $management->assertSingleInstall($this->appName)) : null;
        $settingsChange = $management && $snapshot ? $management->previewSettings($snapshot) : null;
        if ($management && $snapshot) {
            \Saithink\Saipackage\management\UpgradeServices::assertCompatible($management, Factory::services($management), $snapshot, $settingsChange);
        }
        // 上锁再读状态：双击安装按钮会并发进来两个请求，两个都能过状态检查，
        // 然后同时往 plugin/{app} 复制文件、同时跑 migrate 抢 phinxlog_{app}，
        // 最后各自重启一次后端。$lock 活到方法返回为止（异常退出也会释放）
        $lock = $this->acquireLock();

        $state = $this->getInstallState();
        $installation = $management?->store->get('installations', $this->appName)['value'] ?? [];
        if ($state == self::INSTALLED && $snapshot && isset($installation['pending']) && ($installation['state'] ?? '') !== 'installed'
            && ($installation['pending']['fingerprint'] ?? '') === $snapshot['fingerprint']) {
            // A crash can occur after libraries set info.ini=1 but before recording the installed snapshot.
            // Only an explicit task retry reaches here; verify files and finalize without repeating SQL/seeds.
            $this->assertInstalledFiles();
            $progress && $progress('settings');
            $management->settings->commitMigration($this->appName, $settingsChange);
            $management->recordInstalled($this->appName, $snapshot);
            $fresh = $this->getInfo(); unset($fresh['update']); $this->setInfo([], $fresh);
            UserMenuCache::clearMenuCache();
            if ($restart) self::restartServer(); else self::monitorResume();
            return $this->getInfo();
        }
        if ($state == self::INSTALLED || $state == self::DIRECTORY_OCCUPIED) {
            throw new ApiException('插件已经存在');
        }

        // 没有暂存包（appName 传错、runtime 被清理、或者上一次卸载已经收尾）。
        // 不拦的话后面全程空跑：getInfo() 返回空数组、依赖处理抛 Undefined array key 'state'
        // 警告、最后还真把后端重启一遍，接口却返回成功
        if ($state == self::UNINSTALLED) {
            throw new ApiException('插件[' . $this->appName . ']没有待安装的包，请先上传插件包');
        }

        if ($state == self::DEPENDENT_WAIT_INSTALL) {
            throw new ApiException('等待依赖安装');
        }

        echo '开始安装[' . $this->appName . ']' . PHP_EOL;

        $info = $this->getInfo();

        // 装法由包里有没有 db/migrations 决定：有就跑迁移（MySQL / PG 通用），
        // 没有就是老版 MySQL 专用包，只能靠 install.sql。
        // 这两道检查都必须在 installFiles() 之前，被拒的安装一个文件都不能落地。
        // 判定与预检都显式针对暂存包：文件还没复制，已安装目录里是上一个版本，
        // 用 locate() 的话升级包里的坏迁移会漏过预检，直接在 require 阶段打死 worker
        $migrator = $this->migrator();
        $stagedDb = $migrator->stagedDbDir();
        $hasMigration = (bool) $migrator->migrationFiles($stagedDb);
        if (!$hasMigration && DbType::isPgsql()) {
            throw new ApiException(self::PGSQL_UNSUPPORTED);
        }
        if ($hasMigration) {
            // 重名迁移类会在 require 阶段 fatal，try/catch 拦不住，只能提前查
            $migrator->validate($stagedDb);
        }

        // Preflight code-library declarations before changing plugin files or database.
        if ($management) $this->preflightLibraryDependencies();
        if ($management && $snapshot) $management->begin($this->appName, $snapshot);
        // 安装全程关掉文件监控，理由见 monitorPause()
        self::monitorPause();

        try {
            // 先落地文件，再动数据库和状态。
            // 原来的顺序是 SQL -> 依赖处理(置为已安装) -> 复制文件，
            // 一旦复制失败就会留下「状态是已安装、代码却不存在」的残局，
            // 重装被拦为"插件已经存在"，只能先卸载才能恢复
            $progress && $progress('files');
            echo '安装文件' . PHP_EOL;
            $this->installFiles();

            $progress && $progress('database');
            if ($hasMigration) {
                // 首装与升级都是同一条 migrate，进度记在 phinxlog_{app} 里
                echo '执行数据库迁移' . PHP_EOL;
                $migrator->migrate();

                // 种子只在首装灌一次：升级时 unpack() 也会把 state 置为待安装，
                // 真正区分首装和升级的是 update 标记
                if (empty($info['update']) && empty($info['seeded']) && $migrator->hasSeeds()) {
                    $progress && $progress('seed');
                    echo '初始化数据' . PHP_EOL;
                    $migrator->seed();
                    $this->setInfo(['seeded' => 1]);
                }
            } else {
                if ($state == self::WAIT_INSTALL && empty($info['update'])) {
                    echo '安装数据库' . PHP_EOL;
                    $sql = $this->appDir . 'install.sql';
                    Server::importSql($sql);
                }

                if (isset($info['update']) && $info['update'] == 1) {
                    echo '更新数据库' . PHP_EOL;
                    $sql = $this->appDir . 'update.sql';
                    Server::importSql($sql);
                }
            }

            $progress && $progress('settings');
            if ($management && $snapshot) {
                $management->settings->commitMigration($this->appName, $settingsChange);
            }

            $progress && $progress('libraries');
            // 依赖检查
            $this->dependConflictHandle();

            // 依赖更新
            echo '依赖更新' . PHP_EOL;
            $this->dependUpdateHandle();
            // Preserve upgrade semantics across failures; retry must not execute first-install SQL/seeds.
            if (!empty($info['update'])) {
                $fresh = $this->getInfo();
                unset($fresh['update']);
                $this->setInfo([], $fresh);
            }
        } catch (Throwable $e) {
            // 失败也要把监控放回去，否则热重载会一直是关着的
            self::monitorResume();
            $management?->mark($this->appName, 'failed');
            throw $e;
        }

        if ($management && $snapshot && (int) $this->getInfo()['state'] === self::INSTALLED) {
            $management->recordInstalled($this->appName, $snapshot);
        } elseif ($management) {
            $management->mark($this->appName, 'waiting_dependencies');
        }

        // 清理菜单缓存
        UserMenuCache::clearMenuCache();

        // 重启后端
        if ($restart) self::restartServer();
        else self::monitorResume();

        // 上面几步改过 state，重新读一次再返回，否则前端拿到的是过期状态
        return $this->getInfo();
    }

    /**
     * @return array 卸载过程中的警告（数据库步骤被跳过或没清干净时）
     * @throws Throwable
     */
    public function uninstall(bool $restart = true, ?callable $progress = null): array
    {
        $management = Factory::enabled() ? Factory::manager() : null;
        $globalLock = $management?->lock();
        $management?->importLegacy();
        // 与安装、上传共用一把锁，理由见 install()
        $lock = $this->acquireLock();

        $state = $this->getInstallState();
        $managedInstallation = $management?->store->get('installations', $this->appName)['value'] ?? null;
        if ($managedInstallation && $managedInstallation['state'] !== 'uninstalled') {
            $management->assertRemovable($this->appName);
            $drain = config('plugin.saipackage.management.drain');
            if (!is_callable($drain) || $drain($this->appName) !== true) {
                throw new ApiException('请配置并完成插件业务任务排空检查后卸载');
            }
            $management->mark($this->appName, 'uninstalling');
            $state = self::INSTALLED;
        }
        if ($state != self::INSTALLED) {
            // 插件还没安装成功（待安装 / 目录被占），只清理 runtime 下的暂存包。
            // 这里绝不能去删 plugin/{app} 和前端 views 目录：
            // 升级场景下那正是线上正在跑的旧版本代码，而这个分支又不做备份，
            // 一删就没有任何恢复手段
            echo PHP_EOL . '清理插件暂存包[' . $this->appName . ']' . PHP_EOL;
            Filesystem::delDir($this->appDir);
            $management?->discardPackage($this->appName);

            return [];
        }

        echo '开始卸载[' . $this->appName . ']' . PHP_EOL;

        $warnings = [];
        $migrator = $this->migrator();
        $hasMigration = $migrator->hasMigrations();

        // 删除 plugin/{app} 同样会触发文件监控，理由见 monitorPause()
        self::monitorPause();

        try {
            // 备份必须排在数据库之前。迁移的 down() 会 drop 表、删菜单，rollback 一旦中途失败
            // （down() 写错、外键挡着）异常就直接抛出去了，原来放在后面的备份根本执行不到 ——
            // 数据库被改了一半，文件却一份副本都没留下。文件备份不依赖数据库，先做没有代价
            $progress && $progress('backup');
            echo '备份文件' . PHP_EOL;
            $pathRelation = $this->getAllowedPath();
            $failed = $this->backupInstalled('uninstall', $pathRelation);
            if ($failed !== '') {
                $warnings[] = '文件备份失败，本次卸载没有留下可恢复的副本：' . $failed;
            }

            $progress && $progress('database');
            echo '卸载数据库' . PHP_EOL;
            if ($hasMigration) {
                // 回滚到 0：每个迁移的 down() 都跑一遍，表和菜单真删。
                // 这一步必须在删文件之前 —— 迁移文件就在 plugin/{app}/db 里
                $migrator->rollbackAll();
                if (!$migrator->dropLogTable()) {
                    $warnings[] = '版本表 ' . $migrator->migrationTable() . ' 未能清空或删除，请检查迁移的 down() 是否完整。';
                }
            } elseif (DbType::isPgsql()) {
                // 老包的 uninstall.sql 是 MySQL 语法，在 PG 上执行只会报错。
                // 但卸载绝不能被数据库步骤卡住 —— 否则用户既装不上也删不掉
                $warnings[] = '该插件为老版 MySQL 包，当前数据库为 PostgreSQL，已跳过 uninstall.sql，数据库中的表与菜单需要手工清理。';
            } else {
                if ($managedInstallation && $managedInstallation['uninstallSql'] !== null) {
                    $sql = tempnam($this->installDir, '.uninstall-');
                    try {
                        file_put_contents($sql, $managedInstallation['uninstallSql']);
                        Server::importSql($sql);
                    } finally {
                        @unlink($sql);
                    }
                } else {
                    $sql = $this->appDir . 'uninstall.sql';
                    Server::importSql($sql);
                }
            }

            $progress && $progress('files');
            echo '卸载文件' . PHP_EOL;
            foreach ($pathRelation as $value) {
                if (is_dir($value)) {
                    Filesystem::delDir($value);
                }
            }

            // 删除临时目录
            Filesystem::delDir($this->appDir);
        } catch (Throwable $e) {
            self::monitorResume();
            $management?->mark($this->appName, 'failed');
            throw $e;
        }

        $management?->mark($this->appName, 'uninstalled');
        $management?->discardPackage($this->appName);

        // 清理菜单缓存
        UserMenuCache::clearMenuCache();

        // 重启后端
        if ($restart) self::restartServer();
        else self::monitorResume();

        return $warnings;
    }

    /**
     * 校验包自带的 info.ini 是否完整
     *
     * 必须在动 $this->appDir 之前调用，见 unpack() 里的说明。
     * 不校验 state：它由安装器写入，打包方预置反而容易写错
     * @param array $info 从包目录读出来的 info.ini
     * @throws Throwable
     */
    protected function checkPackageInfo(array $info): void
    {
        if (empty($info['app'])) {
            throw new ApiException('插件的基础配置信息错误');
        }
        foreach (['title', 'about', 'author', 'version'] as $key) {
            if (!array_key_exists($key, $info)) {
                throw new ApiException('该插件的基础配置信息不完善：info.ini 缺少 ' . $key);
            }
        }
    }

    /**
     * 检查包是否完整
     *
     * 落地之后的兜底检查。这里不再删目录：unpack() 已经在复制文件之前校验过同样的键，
     * 走到这一步还失败说明 setInfo() 写坏了文件，此时目录里可能是已安装插件的记录，
     * 删掉只会让插件从列表里消失、连卸载都点不动。留着，用户可以重新上传或手工卸载
     * @throws Throwable
     */
    public function checkPackage(): bool
    {
        if (!is_dir($this->appDir)) {
            throw new ApiException('插件目录不存在');
        }
        $info = $this->getInfo();
        $this->checkPackageInfo($info);
        if (!array_key_exists('state', $info)) {
            throw new ApiException('该插件的基础配置信息不完善：info.ini 缺少 state');
        }

        return true;
    }

    /**
     * 依赖安装完成标记
     * @throws Throwable
     */
    public function dependentInstallComplete(string $type): void
    {
        $management = Factory::enabled() ? Factory::manager() : null;
        $globalLock = $management?->lock();
        $info = $this->getInfo();
        if ($info['state'] == self::DEPENDENT_WAIT_INSTALL) {
            if ($type == 'npm') {
                unset($info['npm_dependent_wait_install']);
            }
            if ($type == 'composer') {
                unset($info['composer_dependent_wait_install']);
            }
            if ($type == 'all') {
                unset($info['npm_dependent_wait_install'], $info['composer_dependent_wait_install']);
            }
            if (!isset($info['npm_dependent_wait_install']) && !isset($info['composer_dependent_wait_install'])) {
                $info['state'] = self::INSTALLED;
            }
            $this->setInfo([], $info);
            if ($management && (int) $info['state'] === self::INSTALLED) {
                $management->recordInstalled($this->appName, $management->registerPackage($this->appName));
            }
        }
    }

    /**
     * 依赖冲突检查
     * @return bool
     * @throws Throwable
     */
    public function dependConflictHandle(): bool
    {
        $info = $this->getInfo();
        if ($info['state'] != self::WAIT_INSTALL && $info['state'] != self::CONFLICT_PENDING) {
            return false;
        }

        $coverFiles = [];// 要覆盖的文件-备份
        $depends = Server::getDepend($this->appDir);

        $serverDep = new Depends(base_path() . DIRECTORY_SEPARATOR . 'composer.json', 'composer');
        $webDep = new Depends(self::frontendDir() . DIRECTORY_SEPARATOR . 'package.json');

        // 如果有依赖更新，增加要备份的文件
        if ($depends) {
            foreach ($depends as $key => $item) {
                if (!$item) {
                    continue;
                }
                if ($key == 'require' || $key == 'require-dev') {
                    $coverFiles[] = base_path() . DIRECTORY_SEPARATOR . 'composer.json';
                    continue;
                }
                if ($key == 'dependencies' || $key == 'devDependencies') {
                    $coverFiles[] = self::frontendDir() . DIRECTORY_SEPARATOR . 'package.json';
                }
            }
        }

        // 备份将被覆盖的文件
        if ($coverFiles) {
            $backupsZip = $this->backupsDir . $this->appName . '-cover-' . date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.zip';
            Filesystem::zip($coverFiles, $backupsZip);
        }

        if ($depends) {
            $npm = false;
            $composer = false;

            // composer config 更新
            $composerConfig = Server::getConfig($this->appDir, 'composerConfig');
            if ($composerConfig) {
                $serverDep->setComposerConfig($composerConfig);
            }

            foreach ($depends as $key => $item) {
                if (!$item) {
                    continue;
                }
                if ($key == 'require') {
                    $composer = true;
                    foreach ($item as $name => &$constraint) {
                        $existing = $serverDep->hasDepend($name);
                        if (Factory::enabled() && $existing !== false) $constraint = \Saithink\Saipackage\management\LibraryConstraints::intersect($existing, $constraint);
                    }
                    unset($constraint);
                    $serverDep->addDepends($item, false, true);
                } elseif ($key == 'require-dev') {
                    $composer = true;
                    foreach ($item as $name => &$constraint) {
                        $existing = $serverDep->hasDepend($name, true);
                        if (Factory::enabled() && $existing !== false) $constraint = \Saithink\Saipackage\management\LibraryConstraints::intersect($existing, $constraint);
                    }
                    unset($constraint);
                    $serverDep->addDepends($item, true, true);
                } elseif ($key == 'dependencies') {
                    $npm = true;
                    $webDep->addDepends($item, false, true);
                } elseif ($key == 'devDependencies') {
                    $npm = true;
                    $webDep->addDepends($item, true, true);
                }
            }
            if ($npm) {
                $info['npm_dependent_wait_install'] = 1;
                $info['state'] = self::DEPENDENT_WAIT_INSTALL;
            }
            if ($composer) {
                $info['composer_dependent_wait_install'] = 1;
                $info['state'] = self::DEPENDENT_WAIT_INSTALL;
            }
            if ($info['state'] != self::DEPENDENT_WAIT_INSTALL) {
                // 无冲突
                $this->setInfo([
                    'state' => self::INSTALLED,
                ]);
            } else {
                $this->setInfo([], $info);
            }
        } else {
            // 无冲突
            $this->setInfo([
                'state' => self::INSTALLED,
            ]);
        }
        return true;
    }

    /**
     * 依赖升级处理
     * @throws Throwable
     */
    public function dependUpdateHandle(): void
    {
        $info = $this->getInfo();
        if ($info['state'] == self::DEPENDENT_WAIT_INSTALL) {
            $waitInstall = [];
            if (isset($info['composer_dependent_wait_install'])) {
                $waitInstall[] = 'composer_dependent_wait_install';
            }
            if (isset($info['npm_dependent_wait_install'])) {
                $waitInstall[] = 'npm_dependent_wait_install';
            }
            if (empty($waitInstall)) {
                $this->setInfo([
                    'state' => self::INSTALLED,
                ]);
            }
        }
    }

    /** Refuse incompatible root declarations instead of silently overwriting them. */
    protected function preflightLibraryDependencies(): void
    {
        $depends = Server::getDepend($this->appDir);
        foreach ($depends as $kind => $items) {
            $composer = in_array($kind, ['require', 'require-dev'], true);
            $file = $composer ? base_path() . '/composer.json' : self::frontendDir() . '/package.json';
            $current = is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
            foreach ($items as $name => $constraint) {
                $existing = $current[$kind][$name] ?? null;
                if ($existing === null || $existing === $constraint) continue;
                // npm constraints include protocols/URLs; don't pretend Composer can resolve those.
                if (!$composer || !\Composer\Semver\Intervals::haveIntersections(
                    (new \Composer\Semver\VersionParser())->parseConstraints($existing),
                    (new \Composer\Semver\VersionParser())->parseConstraints($constraint)
                )) throw new ApiException('代码库依赖声明冲突：' . $name);
            }
        }
    }

    /**
     * 获取模块基本信息
     */
    public function getInfo(): array
    {
        return Server::getIni($this->appDir);
    }

    /**
     * 设置模块基本信息
     * @throws Throwable
     */
    public function setInfo(array $kv = [], array $arr = []): bool
    {
        if ($kv) {
            $info = $this->getInfo();
            foreach ($kv as $k => $v) {
                $info[$k] = $v;
            }
            return Server::setIni($this->appDir, $info);
        } elseif ($arr) {
            return Server::setIni($this->appDir, $arr);
        }
        throw new ApiException('参数错误');
    }
}
