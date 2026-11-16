<?php
namespace Saithink\Saipackage\management;

use RuntimeException;

/** Single-host mutation lock. Every entry point takes this before a plugin lock. */
final class MutationLock
{
    private static array $handles = [];
    private string $path;

    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new ManagementException('Cannot create lock directory');
        $this->path = rtrim($directory, '/\\') . '/.management.lock';
        if (isset(self::$handles[$this->path])) {
            self::$handles[$this->path]['count']++;
            return;
        }
        $handle = fopen($this->path, 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle) fclose($handle);
            throw new ManagementException('Another plugin operation is running', 409);
        }
        self::$handles[$this->path] = ['handle' => $handle, 'count' => 1];
    }

    public function __destruct()
    {
        if (!isset(self::$handles[$this->path])) return;
        if (--self::$handles[$this->path]['count'] === 0) {
            flock(self::$handles[$this->path]['handle'], LOCK_UN);
            fclose(self::$handles[$this->path]['handle']);
            unset(self::$handles[$this->path]);
        }
    }
}
