<?php
namespace Saithink\Saipackage\management;

use RuntimeException;

final class Settings
{
    public function __construct(private Store $store, private string $key)
    {
    }

    private function key(): string
    {
        $key = base64_decode($this->key, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new ManagementException('Configure SAIPACKAGE_SETTINGS_KEY with a stable base64-encoded 32-byte key');
        }
        return $key;
    }

    private function encrypt(array $values): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox(json_encode($values, JSON_THROW_ON_ERROR), $nonce, $this->key()));
    }

    private function decrypt(string $encrypted): array
    {
        $bytes = base64_decode($encrypted, true);
        if ($bytes === false || strlen($bytes) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) throw new ManagementException('Invalid encrypted settings');
        $plain = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
        if ($plain === false) throw new ManagementException('Unable to decrypt settings; restore the original settings key');
        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }

    public function raw(string $id): array
    {
        Manifest::id($id);
        $row = $this->store->get('settings', $id);
        return $row ? ['values' => $this->decrypt($row['value']['encrypted']), 'revision' => $row['revision'], 'version' => $row['value']['version']] : ['values' => [], 'revision' => 0, 'version' => 0];
    }

    public function view(string $id, array $schema): array
    {
        $raw = $this->raw($id);
        // Never return fields removed from the active schema (they may contain retired credentials).
        $values = [];
        $secrets = [];
        foreach ($this->fields($schema) as $path => $field) {
            if (($value = $this->value($raw['values'], $path)) !== null) $this->assign($values, $path, $value);
            if (!empty($field['writeOnly'])) {
                $secrets[$path] = ['configured' => $value !== null];
                $this->forget($values, $path);
            }
        }
        foreach ($this->store->get('settings', $id)['value']['secretPaths'] ?? [] as $path) $this->forget($values, $path);
        return ['values' => $values, 'secrets' => $secrets, 'revision' => $raw['revision']];
    }

    public function prepare(string $id, array $schema, array $values, array $actions): array
    {
        $old = $this->raw($id)['values'];
        $fields = $this->fields($schema);
        foreach ($actions as $path => $_) {
            if (empty($fields[$path]['writeOnly'])) throw new ManagementException('Unknown secret field');
        }
        foreach ($fields as $path => $field) {
            if (($schema['ui']['fields'][$path]['scope'] ?? 'instance') !== 'instance') {
                throw new ManagementException('Tenant settings require a host scope adapter');
            }
            if (!empty($field['writeOnly'])) {
                if ($this->value($values, $path) !== null) throw new ManagementException('Use secretActions to edit secrets');
                $action = $actions[$path] ?? ['action' => 'keep'];
                switch ($action['action'] ?? '') {
                    case 'keep':
                        if (($secret = $this->value($old, $path)) !== null) $this->assign($values, $path, $secret);
                        break;
                    case 'replace':
                        if (!is_string($action['value'] ?? null) || $action['value'] === '') throw new ManagementException('Secret replacement must be non-empty');
                        $this->assign($values, $path, $action['value']);
                        break;
                    case 'clear': break;
                    default: throw new ManagementException('Invalid secret action');
                }
            } elseif ($this->value($values, $path) === null && array_key_exists('default', $field)) {
                $this->assign($values, $path, $field['default']);
            }
        }
        if (!Manifest::validateSettings($values, $schema)) throw new ManagementException('Settings do not match schema', 422);
        return $values;
    }

    public function save(string $id, array $schema, int $version, array $values, array $actions, int $expected, int $actor): int
    {
        $before = $this->raw($id);
        if ($before['revision'] !== $expected) throw new ManagementException('Revision conflict', 409);
        if ($before['revision'] && $version < $before['version']) throw new ManagementException('Settings version downgrade is not supported');
        $prepared = $this->prepare($id, $schema, $values, $actions);
        $row = $this->store->get('settings', $id);
        $history = $row['value']['history'] ?? [];
        if ($row) $history[] = ['encrypted' => $row['value']['encrypted'], 'version' => $row['value']['version'], 'revision' => $row['revision']];
        $audit = $row['value']['audit'] ?? [];
        $audit[] = ['actor' => $actor, 'at' => time(), 'revision' => $expected + 1, 'fields' => array_keys($this->fields($schema))];
        $fingerprint = $this->fingerprint($prepared, $schema);
        return $this->store->put('settings', $id, [
            'encrypted' => $this->encrypt($prepared), 'version' => $version, 'fingerprint' => $fingerprint,
            'secretPaths' => $this->secretPaths($schema, $row['value']['secretPaths'] ?? []),
            'history' => array_slice($history, -5), 'audit' => array_slice($audit, -50),
        ], $expected);
    }

    /** Pure preview: fail before touching plugin files or database, then commit with a revision check. */
    public function prepareMigration(string $id, array $schema, int $target, ?callable $migration, array $previousSchema = []): ?array
    {
        $old = $this->raw($id);
        if (!$old['revision']) return null;
        if ($target < $old['version']) throw new ManagementException('Settings version downgrade is not supported');
        $values = $migration && $old['version'] !== $target ? $migration($old['values'], $old['version'], $target) : $old['values'];
        if (!is_array($values)) throw new ManagementException('Settings migration must return an array');
        $row = $this->store->get('settings', $id);
        $secrets = $this->secretPaths($schema, $this->secretPaths($previousSchema, $row['value']['secretPaths'] ?? []));
        return ['values' => $values, 'version' => $target, 'revision' => $old['revision'], 'schema' => $schema, 'secretPaths' => $secrets];
    }

    public function commitMigration(string $id, ?array $prepared): void
    {
        if ($prepared === null) return;
        $row = $this->store->get('settings', $id);
        if (!$row || $row['revision'] !== $prepared['revision']) throw new ManagementException('Settings changed during installation', 409);
        $old = $this->raw($id);
        if ($old['version'] === $prepared['version'] && $old['values'] === $prepared['values'] && ($row['value']['secretPaths'] ?? []) === $prepared['secretPaths']) return;
        $history = $row['value']['history'] ?? [];
        $history[] = ['encrypted' => $row['value']['encrypted'], 'version' => $old['version'], 'revision' => $old['revision']];
        $this->store->put('settings', $id, [
            'encrypted' => $this->encrypt($prepared['values']), 'version' => $prepared['version'], 'secretPaths' => $prepared['secretPaths'],
            'fingerprint' => $this->fingerprint($prepared['values'], $prepared['schema']), 'history' => array_slice($history, -5), 'audit' => $row['value']['audit'] ?? [],
        ], $old['revision']);
    }

    public function migrate(string $id, array $schema, int $target, ?callable $migration): void
    {
        $this->commitMigration($id, $this->prepareMigration($id, $schema, $target, $migration));
    }

    private function secretPaths(array $schema, array $existing = []): array
    {
        foreach ($this->fields($schema) as $path => $field) if (!empty($field['writeOnly'])) $existing[] = $path;
        $existing = array_values(array_unique($existing));
        sort($existing);
        return $existing;
    }

    /** Keyed digest avoids exposing hashes useful for offline guessing of secrets. */
    public function fingerprint(array $values, array $schema, bool $connectionsOnly = true): string
    {
        foreach ($this->fields($schema) as $path => $_) {
            if ($connectionsOnly && ($schema['ui']['fields'][$path]['connection'] ?? true) === false) $this->forget($values, $path);
        }
        $sort = function (array &$node) use (&$sort): void {
            ksort($node);
            foreach ($node as &$value) if (is_array($value)) $sort($value);
        };
        $sort($values);
        return hash_hmac('sha256', json_encode([$values, $schema], JSON_THROW_ON_ERROR), $this->key());
    }

    public function clear(string $id, int $expected): void
    {
        $this->store->transaction(function () use ($id, $expected): void {
            $row = $this->store->get('settings', Manifest::id($id));
            // Keep a tombstone revision: deleting/recreating the row would let an old form overwrite new settings.
            $this->store->put('settings', $id, ['encrypted' => $this->encrypt([]), 'version' => $row['value']['version'] ?? 1,
                'secretPaths' => [], 'history' => [], 'audit' => []], $expected);
            $check = $this->store->get('service_checks', $id);
            if ($check) $this->store->remove('service_checks', $id, $check['revision']);
        });
    }

    public function fields(array $schema, string $prefix = ''): array
    {
        $result = [];
        foreach ($schema['properties'] ?? [] as $name => $field) {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $name)) throw new ManagementException('Invalid settings field name');
            $path = $prefix . $name;
            if (($field['type'] ?? '') === 'object') $result += $this->fields($field, $path . '.');
            else $result[$path] = $field;
        }
        return $result;
    }

    public function value(array $values, string $path): mixed
    {
        foreach (explode('.', $path) as $part) {
            if (!is_array($values) || !array_key_exists($part, $values)) return null;
            $values = $values[$part];
        }
        return $values;
    }

    private function assign(array &$values, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $last = array_pop($parts);
        foreach ($parts as $part) {
            if (isset($values[$part]) && !is_array($values[$part])) throw new ManagementException('Invalid settings group');
            $values[$part] ??= [];
            $values = &$values[$part];
        }
        $values[$last] = $value;
    }

    private function forget(array &$values, string $path): void
    {
        $parts = explode('.', $path);
        $last = array_pop($parts);
        foreach ($parts as $part) {
            if (!isset($values[$part]) || !is_array($values[$part])) return;
            $values = &$values[$part];
        }
        unset($values[$last]);
    }
}
