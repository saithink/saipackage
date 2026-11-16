<?php
namespace Saithink\Saipackage\management;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class Manifest
{
    public static function id(string $id): string
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,99}$/D', $id)) {
            throw new ManagementException('Invalid plugin identifier');
        }
        return $id;
    }

    public static function read(string $directory, array $info): array
    {
        $path = rtrim($directory, '/\\') . '/manifest.json';
        $manifest = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [
            'schemaVersion' => 1, 'id' => $info['app'], 'version' => (string) ($info['version'] ?? ''), 'legacy' => true,
        ];
        self::validate($manifest);
        if ($manifest['id'] !== $info['app'] || $manifest['version'] !== (string) ($info['version'] ?? '')) {
            throw new ManagementException('manifest.json and info.ini disagree');
        }
        return $manifest;
    }

    public static function validate(array $manifest): void
    {
        self::id($manifest['id'] ?? '');
        if (($manifest['schemaVersion'] ?? null) !== 1) {
            throw new ManagementException('Unsupported manifest schemaVersion');
        }
        if (isset($manifest['settingsVersion']) && (!is_int($manifest['settingsVersion']) || $manifest['settingsVersion'] < 1)) throw new ManagementException('settingsVersion must be a positive integer');
        if (isset($manifest['updateSource']) && (!is_string($manifest['updateSource']) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,99}$/D', $manifest['updateSource']))) throw new ManagementException('Invalid updateSource name');
        if (empty($manifest['legacy']) && !preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $manifest['version'] ?? '')) {
            throw new ManagementException('Plugin version must be SemVer');
        }
        foreach (['requiresPlugins', 'conflictsPlugins'] as $key) {
            foreach ($manifest[$key] ?? [] as $id => $constraint) {
                self::id($id);
                (new VersionParser())->parseConstraints($constraint);
            }
        }
        foreach (['saiadmin', 'saipackage', 'php'] as $key) {
            if (isset($manifest['requiresHost'][$key])) {
                (new VersionParser())->parseConstraints($manifest['requiresHost'][$key]);
            }
        }
        $seen = [];
        foreach ($manifest['externalServices'] ?? [] as $service) {
            $id = self::id($service['id'] ?? '');
            if (isset($seen[$id]) || empty($service['probe']) || !is_string($service['probe']) || !is_string($service['settingsGroup'] ?? null)) {
                throw new ManagementException('Invalid external service declaration');
            }
            $seen[$id] = true;
        }
    }

    public static function schema(string $directory): array
    {
        $path = rtrim($directory, '/\\') . '/settings.schema.json';
        $schema = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : ['type' => 'object', 'properties' => []];
        if (($schema['type'] ?? null) !== 'object' || !is_array($schema['properties'] ?? null)) {
            throw new ManagementException('Settings schema must describe an object');
        }
        // Explicit properties keep form fields, validation and secret redaction aligned.
        $check = function (mixed $node) use (&$check): void {
            if (!is_array($node)) return;
            foreach ($node as $key => $value) {
                if (in_array($key, ['$ref', 'allOf', 'anyOf', 'oneOf', 'if', 'then', 'else', 'patternProperties', 'dependentSchemas'], true)) {
                    throw new ManagementException('Settings must use explicit properties; schema composition/references are unsupported');
                }
                $check($value);
            }
        };
        $check($schema);
        foreach ($schema['ui']['groups'] ?? [] as $group) {
            if (($group['scope'] ?? 'instance') !== 'instance') throw new ManagementException('Tenant settings require a host scope adapter');
        }
        $fields = function (array $node) use (&$fields): void {
            foreach ($node['properties'] ?? [] as $field) {
                if (!in_array($field['type'] ?? '', ['object', 'string', 'integer', 'number', 'boolean'], true)) {
                    throw new ManagementException('Supported settings types: object, string, integer, number, boolean');
                }
                if (($field['type'] ?? '') === 'object') {
                    if (!empty($field['writeOnly'])) throw new ManagementException('Declare secrets on leaf fields');
                    $fields($field);
                }
                if (!empty($field['writeOnly']) && (isset($field['default']) || isset($field['examples']))) throw new ManagementException('Secret fields cannot declare defaults or examples');
            }
        };
        $fields($schema);
        return $schema;
    }

    public static function validateSettings(array $values, array $schema): bool
    {
        return (new Validator())->validate((object) self::objects($values, $schema), json_decode(json_encode(self::schemaObjects($schema))))->isValid();
    }

    private static function schemaObjects(array $node): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $value = self::schemaObjects($value);
                $node[$key] = in_array($key, ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'], true) ? (object) $value : $value;
            }
        }
        return $node;
    }

    private static function objects(array $values, array $schema): array
    {
        foreach ($values as $key => $value) {
            $child = $schema['properties'][$key] ?? [];
            if (is_array($value) && ($child['type'] ?? '') === 'object') {
                $values[$key] = (object) self::objects($value, $child);
            }
        }
        return $values;
    }

    public static function matches(string $version, string $constraint): bool
    {
        try {
            return $version !== '' && Semver::satisfies($version, $constraint);
        } catch (\UnexpectedValueException) {
            return false;
        }
    }
}
