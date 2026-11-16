<?php
namespace Saithink\Saipackage\management;

interface UpdateSource
{
    /** Public release metadata only. Credentials belong to the configured adapter. */
    public function releases(string $plugin): array;
    public function download(string $plugin, array $release, string $destination): void;
}
