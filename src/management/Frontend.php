<?php
namespace Saithink\Saipackage\management;

use RuntimeException;

final class Frontend
{
    /** Copies only installer-owned resources and adds an idempotent panel to the official 6.x view. */
    public static function install(string $frontend): bool
    {
        $target = rtrim($frontend, '/\\') . '/src/views/plugin/saipackage';
        $view = $target . '/install/index.vue';
        if (!is_file($view)) return false;
        $text = file_get_contents($view);
        if (!str_contains($text, '<ManagementPanel')) {
            if (!str_contains($text, '<div class="art-full-height">') || !preg_match('/<script setup(?: lang="ts")?>/', $text)) {
                throw new ManagementException('Custom installer view: manually import ../management/index.vue');
            }
            $text = str_replace('<div class="art-full-height">', '<div class="art-full-height">' . "\n    <ManagementPanel />", $text);
            $text = preg_replace('/(<script setup(?: lang="ts")?>)/', '$1' . "\nimport ManagementPanel from '../management/index.vue'", $text, 1);
        }
        if (!is_dir($target . '/management')) mkdir($target . '/management', 0755, true);
        foreach (glob(dirname(__DIR__) . '/frontend/management/*') as $file) {
            if (!copy($file, $target . '/management/' . basename($file))) throw new ManagementException('Cannot copy management UI');
        }
        if (file_put_contents($view, $text) === false) throw new ManagementException('Cannot update installer view');
        return true;
    }
}
