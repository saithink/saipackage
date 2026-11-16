<?php
namespace Saithink\Saipackage\management;

interface ServiceProbe
{
    /** Read-only request, bounded by the adapter's timeout. Never return credentials or response bodies. */
    public function check(array $settings, array $service): array;
}
