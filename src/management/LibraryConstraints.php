<?php
namespace Saithink\Saipackage\management;

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;

final class LibraryConstraints
{
    /** Distribute OR clauses so merging never relaxes an existing Composer requirement. */
    public static function intersect(string $existing, string $requested): string
    {
        if ($existing === $requested) return $existing;
        $parser = new VersionParser();
        $result = [];
        foreach (preg_split('/\s*\|\|?\s*/', $existing) as $left) {
            foreach (preg_split('/\s*\|\|?\s*/', $requested) as $right) {
                if (Intervals::haveIntersections($parser->parseConstraints($left), $parser->parseConstraints($right))) $result[] = trim($left . ' ' . $right);
            }
        }
        if (!$result) throw new ManagementException('Incompatible Composer dependency declarations');
        return implode(' || ', array_unique($result));
    }
}
