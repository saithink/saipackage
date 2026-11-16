<?php
namespace Saithink\Saipackage\management;

use PDO;
use RuntimeException;

/** A small portable document repository. Tables are installed explicitly, never in requests. */
final class Store
{
    public const TABLES = ['packages', 'installations', 'dependencies', 'settings', 'service_checks', 'tasks'];
    public const EXTRA_TABLES = ['updates'];

    public function __construct(private PDO $pdo, private string $prefix = '')
    {
        if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) {
            throw new ManagementException('Invalid database prefix');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function table(string $table): string
    {
        if (!in_array($table, [...self::TABLES, ...self::EXTRA_TABLES], true)) {
            throw new ManagementException('Invalid repository');
        }
        return $this->prefix . 'saipackage_' . $table;
    }

    public function get(string $table, string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT payload, revision FROM ' . $this->table($table) . ' WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ? ['value' => json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR), 'revision' => (int) $row['revision']] : null;
    }

    public function all(string $table): array
    {
        $rows = $this->pdo->query('SELECT id, payload, revision FROM ' . $this->table($table) . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['id']] = ['value' => json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR), 'revision' => (int) $row['revision']];
        }
        return $result;
    }

    /** Compare-and-swap prevents stale forms and workers from overwriting newer state. */
    public function put(string $table, string $id, array $value, int $expected): int
    {
        $name = $this->table($table);
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($expected === 0) {
            try {
                $statement = $this->pdo->prepare("INSERT INTO $name (id, payload, revision, updated_at) VALUES (?, ?, 1, ?)");
                $statement->execute([$id, $json, time()]);
            } catch (\PDOException $e) {
                // PostgreSQL aborts the transaction on a duplicate insert; a query here would mask the conflict.
                if ($e->getCode() === '23505') throw new ManagementException('Revision conflict', 409);
                if ($this->get($table, $id)) {
                    throw new ManagementException('Revision conflict', 409);
                }
                throw $e;
            }
        } else {
            $statement = $this->pdo->prepare("UPDATE $name SET payload = ?, revision = revision + 1, updated_at = ? WHERE id = ? AND revision = ?");
            $statement->execute([$json, time(), $id, $expected]);
            if ($statement->rowCount() !== 1) {
                throw new ManagementException('Revision conflict', 409);
            }
        }
        return $expected + 1;
    }

    public function remove(string $table, string $id, int $expected): void
    {
        $statement = $this->pdo->prepare('DELETE FROM ' . $this->table($table) . ' WHERE id = ? AND revision = ?');
        $statement->execute([$id, $expected]);
        if ($statement->rowCount() !== 1) {
            throw new ManagementException('Revision conflict', 409);
        }
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
