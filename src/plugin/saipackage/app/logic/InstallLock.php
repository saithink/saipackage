<?php

namespace plugin\saipackage\app\logic;

use plugin\saiadmin\exception\ApiException;

/**
 * 插件安装/卸载/上传的互斥锁
 *
 * 前端的按钮点两下就能并发进来两个请求，而这几个流程互相踩得很厉害：
 * 两个 install 会同时往 plugin/{app} 复制文件、同时跑 migrate（phinxlog_{app} 里抢同一个版本号），
 * upload 又会把 runtime 下的暂存包整个删掉重建 —— 正在读它的安装流程就只能看到半个包。
 *
 * 用 flock 而不是「存在即上锁」的标记文件：worker 被 taskkill 打断时，
 * 操作系统会在进程退出时自动释放文件锁，不会留下一个谁都解不开的死锁。
 * 锁文件本身不删（删了会有「后来者拿到已被 unlink 的 inode」的竞态），
 * 留在 runtime/saipackage/ 下也没有副作用。
 *
 * 生命周期跟着局部变量走：方法正常返回或抛异常时局部变量都会被释放，
 * __destruct 里放锁，不必给整个 install() 套一层 try/finally。
 */
class InstallLock
{
    /**
     * @var resource|null 锁文件句柄，持有期间即持有锁
     */
    protected $handle = null;

    public function __construct(string $file, string $busyMessage)
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ApiException('目录创建失败，请检查写入权限：' . $dir);
        }

        // 'c' 模式：不存在就创建，存在也不截断（截断要等拿到锁之后再做）
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            throw new ApiException('无法创建插件操作锁文件，请检查写入权限：' . $file);
        }

        // 非阻塞：拿不到锁就立刻回报「有别的操作在跑」，让用户重试，
        // 阻塞等待只会把请求挂死到超时
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new ApiException($busyMessage);
        }

        // 写点线索，排障时能看出锁是哪个进程什么时候拿的
        @ftruncate($handle, 0);
        @fwrite($handle, date('Y-m-d H:i:s') . ' pid=' . getmypid() . PHP_EOL);
        @fflush($handle);

        $this->handle = $handle;
    }

    /**
     * 提前释放（正常流程不必调用，析构会做）
     */
    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        @flock($this->handle, LOCK_UN);
        @fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
