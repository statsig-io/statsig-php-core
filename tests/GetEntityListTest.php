<?php

declare(strict_types=1);

namespace Statsig\Tests;

use PHPUnit\Framework\TestCase;
use Statsig\Statsig;
use Statsig\StatsigOptions;

/**
 * @runTestsInSeparateProcesses
 */
class GetEntityListTest extends TestCase
{
    protected MockServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = dirname(__FILE__);
        $data = file_get_contents($dir . '/../../statsig-rust/tests/data/eval_proj_dcs.json');

        $this->server = new MockServer();
        $this->server->mock('/v2/download_config_specs/secret-key.json', $data);
        $this->server->mock('/v1/log_event', '{ "success": true }', ['status' => 202]);
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    private function initStatsig(): Statsig
    {
        $options = new StatsigOptions(
            specs_url: $this->server->getUrl() . '/v2/download_config_specs',
            log_event_url: $this->server->getUrl() . '/v1/log_event'
        );

        $statsig = new Statsig('secret-key', $options);
        $statsig->initialize();

        return $statsig;
    }

    public function testGetFeatureGateList()
    {
        $feature_gate_list = $this->initStatsig()->getFeatureGateList();

        $this->assertIsArray($feature_gate_list);
        $this->assertContains('test_small_pass_gate', $feature_gate_list);
    }

    public function testGetDynamicConfigList()
    {
        $dynamic_config_list = $this->initStatsig()->getDynamicConfigList();

        $this->assertIsArray($dynamic_config_list);
        $this->assertContains('test_custom_config', $dynamic_config_list);
    }

    public function testGetExperimentList()
    {
        $experiment_list = $this->initStatsig()->getExperimentList();

        $this->assertIsArray($experiment_list);
        $this->assertContains('test_experiment_no_targeting', $experiment_list);
    }

    public function testGetLayerList()
    {
        $layer_list = $this->initStatsig()->getLayerList();

        $this->assertIsArray($layer_list);
        $this->assertContains('test_layer', $layer_list);
    }

    public function testGetParameterStoreList()
    {
        $parameter_store_list = $this->initStatsig()->getParameterStoreList();

        $this->assertIsArray($parameter_store_list);
        $this->assertContains('test_parameter_store', $parameter_store_list);
    }

    public function testGetAutotuneList()
    {
        $autotune_list = $this->initStatsig()->getAutotuneList();

        $this->assertIsArray($autotune_list);
        $this->assertContains('test_autotune', $autotune_list);
    }
}
