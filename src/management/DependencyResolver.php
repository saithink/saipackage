<?php
namespace Saithink\Saipackage\management;

use RuntimeException;

/** Resolve against the entire installed set, including reverse dependencies. */
final class DependencyResolver
{
    private int $remaining = 10000;

    public function resolve(string $root, array $installed, array $packages, array $host = []): array
    {
        $this->remaining = 10000;
        Manifest::id($root);
        if (!isset($packages[$root])) throw new ManagementException('No uploaded package: ' . $root);
        $choices = [];
        foreach ($installed as $id => $item) $choices[$id][] = $item;
        foreach ($packages as $id => $item) $choices[$id][] = $item;
        // The requested root always selects its uploaded candidate; dependencies prefer installed versions.
        $choices[$root] = [$packages[$root]];
        $required = array_fill_keys(array_keys($installed), []);
        $required[$root][] = '=' . $packages[$root]['manifest']['version'];
        $solution = $this->search([], $required, $choices, $host);
        if ($solution === null) throw new ManagementException('Unsatisfied plugin dependencies, conflicts or host versions: ' . $this->explain($required, $choices));
        $order = [];
        $visiting = [];
        $visit = function (string $id, array $chain = []) use (&$visit, &$order, &$visiting, $solution): void {
            if (($visiting[$id] ?? 0) === 1) throw new ManagementException('Circular plugin dependency: ' . implode(' -> ', [...$chain, $id]));
            if (($visiting[$id] ?? 0) === 2) return;
            $visiting[$id] = 1;
            foreach ($solution[$id]['manifest']['requiresPlugins'] ?? [] as $dep => $_) $visit($dep, [...$chain, $id]);
            $visiting[$id] = 2;
            $order[] = $id;
        };
        foreach (array_keys($solution) as $id) $visit($id);
        $steps = [];
        foreach ($order as $id) {
            if (($solution[$id]['source'] ?? '') === 'package') $steps[$id] = $solution[$id];
        }
        return ['root' => $root, 'steps' => $steps, 'selected' => array_map(fn ($item) => $item['manifest']['version'], $solution)];
    }

    private function search(array $selected, array $required, array $choices, array $host): ?array
    {
        if (--$this->remaining < 0) throw new ManagementException('Dependency graph is too complex; reduce candidate versions or split the installation plan');
        foreach ($selected as $id => $item) {
            // Reserve failed installations for reverse constraints, but never reuse them as prerequisites.
            if (($item['source'] ?? '') === 'installed' && ($item['state'] ?? 'installed') !== 'installed' && !empty($required[$id])) return null;
            foreach ($required[$id] ?? [] as $constraint) if (!Manifest::matches($item['manifest']['version'], $constraint)) return null;
            foreach ($item['manifest']['conflictsPlugins'] ?? [] as $other => $constraint) {
                if (isset($selected[$other]) && Manifest::matches($selected[$other]['manifest']['version'], $constraint)) return null;
            }
            foreach ($item['manifest']['requiresHost'] ?? [] as $key => $constraint) {
                if (!Manifest::matches((string) ($host[$key] ?? ''), $constraint)) return null;
            }
        }
        $pending = array_diff(array_keys($required), array_keys($selected));
        if (!$pending) return $selected;
        $id = reset($pending);
        foreach ($choices[$id] ?? [] as $choice) {
            $next = $selected;
            $next[$id] = $choice;
            $constraints = $required;
            foreach ($choice['manifest']['requiresPlugins'] ?? [] as $dep => $constraint) $constraints[$dep][] = $constraint;
            if ($result = $this->search($next, $constraints, $choices, $host)) return $result;
        }
        return null;
    }

    private function explain(array $required, array $choices): string
    {
        $details = [];
        foreach ($choices as $id => $items) {
            foreach ($items as $item) {
                foreach ($item['manifest']['requiresPlugins'] ?? [] as $dep => $constraint) $details[$dep][] = "$id requires $constraint";
            }
        }
        foreach ($required as $id => $constraints) $details[$id][] = implode(' & ', $constraints);
        return json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function assertRemovable(string $id, array $installed, bool $disable = false): void
    {
        foreach ($installed as $other => $item) {
            if ($other === $id || ($disable && empty($item['enabled']))) continue;
            if (isset($item['manifest']['requiresPlugins'][$id])) throw new ManagementException("$id is required by $other");
        }
    }
}
