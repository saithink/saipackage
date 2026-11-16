<?php
namespace Saithink\Saipackage\management;

final class UpgradeServices
{
    public static function assertCompatible(Manager $manager, Services $services, array $snapshot, ?array $preparedSettings = null): void
    {
        $id = $snapshot['manifest']['id'];
        $preparedSettings ??= $manager->previewSettings($snapshot);
        $installed = $manager->store->get('installations', $id)['value'] ?? null;
        if (!$installed || $installed['state'] === 'uninstalled') return; // first installs may await configuration
        $required = array_filter($snapshot['manifest']['externalServices'] ?? [], fn ($service) => $service['required'] ?? true);
        if (!$required) return;
        try {
            $values = $preparedSettings['values'] ?? [];
            // New unrelated business settings may remain pending after upgrade. Validate service groups
            // against the target schema, without treating those unrelated missing fields as a probe failure.
            $groups = array_fill_keys(array_column($required, 'settingsGroup'), true);
            $schema = ['type' => 'object', 'properties' => array_intersect_key($snapshot['schema']['properties'], $groups),
                'required' => array_values(array_intersect($snapshot['schema']['required'] ?? [], array_keys($groups)))];
            $result = $services->check($id, $snapshot['manifest'], $schema, array_intersect_key($values, $groups));
        } catch (\Throwable) {
            throw new ManagementException('UPDATE_EXTERNAL_SERVICE_UNVERIFIED');
        }
        foreach ($required as $service) {
            $code = $result['results'][$service['id']]['code'] ?? 'UNKNOWN';
            if ($code !== 'OK') throw new ManagementException('UPDATE_EXTERNAL_SERVICE_' . $code);
        }
    }
}
