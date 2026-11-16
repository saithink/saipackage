<?php
namespace plugin\saipackage\app\middleware;

use Saithink\Saipackage\management\Factory;
use Saithink\Saipackage\management\RuntimeGuard;
use Webman\MiddlewareInterface;
use Webman\Http\Request;
use Webman\Http\Response;

final class PluginReady implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $plugin = $request->plugin ?? '';
        if (preg_match('/^plugin\\\\([a-zA-Z][a-zA-Z0-9_]*)\\\\/', $request->controller ?? '', $matches)) $plugin = $matches[1];
        if (Factory::enabled() && $plugin && !in_array($plugin, ['saipackage', 'saiadmin'], true)) {
            $manager = Factory::manager();
            $state = (new RuntimeGuard($manager->store, $manager->settings))->status($plugin);
            if (!$state['ready']) return json(['code' => 503, 'message' => 'Plugin unavailable', 'data' => $state])->withStatus(503);
        }
        return $handler($request);
    }
}
