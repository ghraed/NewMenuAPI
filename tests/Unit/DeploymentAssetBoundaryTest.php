<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DeploymentAssetBoundaryTest extends TestCase
{
    public function test_deployed_web_servers_deny_direct_dish_asset_storage_paths(): void
    {
        $nginx = file_get_contents(dirname(__DIR__, 2).'/docker/nginx/default.conf');
        $containerApache = file_get_contents(dirname(__DIR__, 2).'/docker/apache/000-default.conf');
        $proxyApache = file_get_contents(dirname(__DIR__, 2).'/deploy/apache-proxy.conf');

        $this->assertIsString($nginx);
        $this->assertStringContainsString('location ^~ /storage/dishes/', $nginx);
        $this->assertStringContainsString('return 404;', $nginx);

        foreach ([$containerApache, $proxyApache] as $apacheConfig) {
            $this->assertIsString($apacheConfig);
            $this->assertStringContainsString('^/storage/dishes(?:/|$)', $apacheConfig);
            $this->assertStringContainsString('Require all denied', $apacheConfig);
        }
    }
}
