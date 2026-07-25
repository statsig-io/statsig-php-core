<?php

declare(strict_types=1);

namespace Statsig\Tests;

use PHPUnit\Framework\TestCase;
use Statsig\Statsig;
use Statsig\StatsigOptions;
use Statsig\StatsigUser;

/**
 * Covers the enforce_overrides / enforce_targeting persistent-assignment
 * options. Fixture (enforce_sticky_dcs.json): experiment `enforce_exp` with a
 * console override rule matching userID `override-user`, a targeting gate
 * passing only users with custom `targeted=yes`, and layer `enforce_layer`
 * delegating to the experiment.
 *
 * @runTestsInSeparateProcesses
 */
class EnforceStickyValuesTest extends TestCase
{
    protected MockServer $server;
    protected Statsig $statsig;
    protected MockPersistentStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = dirname(__FILE__);
        $data = file_get_contents($dir . '/data/enforce_sticky_dcs.json');

        $this->server = new MockServer();
        $this->server->mock('/v2/download_config_specs/secret-key.json', $data);
        $this->server->mock('/v1/log_event', '{ "success": true }', ['status' => 202]);

        // user_persisted_values are only honored when a persistent storage
        // adapter is configured.
        $this->storage = new MockPersistentStorage();

        $options = new StatsigOptions(
            specs_url: $this->server->getUrl() . '/v2/download_config_specs',
            log_event_url: $this->server->getUrl() . '/v1/log_event',
            persistent_storage: $this->storage
        );

        $this->statsig = new Statsig('secret-key', $options);
        $this->statsig->initialize();
    }

    protected function tearDown(): void
    {
        $this->statsig->shutdown();
        $this->server->stop();
    }

    private static function makeUser(string $userID, bool $targeted): StatsigUser
    {
        return new StatsigUser(
            $userID,
            custom: ['targeted' => $targeted ? 'yes' : 'no']
        );
    }

    private static function stickyValues(string $configName, ?string $configDelegate): array
    {
        return [
            $configName => [
                'value' => true,
                'json_value' => ['value' => 'sticky_value'],
                'rule_id' => 'sticky_rule_id',
                'group_name' => 'Sticky Group',
                'secondary_exposures' => [],
                'undelegated_secondary_exposures' => [],
                'config_delegate' => $configDelegate,
                'explicit_parameters' => null,
                'time' => 1700000000000,
            ],
        ];
    }

    public function testStickyValueWinsWithoutEnforceOverrides()
    {
        $experiment = $this->statsig->getExperiment(
            self::makeUser('override-user', true),
            'enforce_exp',
            ['user_persisted_values' => self::stickyValues('enforce_exp', null)]
        );

        $this->assertEquals('sticky_value', $experiment->get('value', 'err'));
        $this->assertEquals('sticky_rule_id', $experiment->rule_id);
    }

    public function testEnforceOverridesLetsOverrideWinOverSticky()
    {
        $experiment = $this->statsig->getExperiment(
            self::makeUser('override-user', true),
            'enforce_exp',
            [
                'user_persisted_values' => self::stickyValues('enforce_exp', null),
                'enforce_overrides' => true,
            ]
        );

        $this->assertEquals('override_value', $experiment->get('value', 'err'));
        $this->assertEquals('override_rule:userID:id_override', $experiment->rule_id);
    }

    public function testEnforceOverridesKeepsStickyWhenNoOverrideMatches()
    {
        $experiment = $this->statsig->getExperiment(
            self::makeUser('plain-user', true),
            'enforce_exp',
            [
                'user_persisted_values' => self::stickyValues('enforce_exp', null),
                'enforce_overrides' => true,
            ]
        );

        $this->assertEquals('sticky_value', $experiment->get('value', 'err'));
    }

    public function testEnforceTargetingKeepsStickyWhenStillTargeted()
    {
        $experiment = $this->statsig->getExperiment(
            self::makeUser('plain-user', true),
            'enforce_exp',
            [
                'user_persisted_values' => self::stickyValues('enforce_exp', null),
                'enforce_targeting' => true,
            ]
        );

        $this->assertEquals('sticky_value', $experiment->get('value', 'err'));
    }

    public function testEnforceTargetingDropsStickyWhenNoLongerTargeted()
    {
        $experiment = $this->statsig->getExperiment(
            self::makeUser('plain-user', false),
            'enforce_exp',
            [
                'user_persisted_values' => self::stickyValues('enforce_exp', null),
                'enforce_targeting' => true,
            ]
        );

        $this->assertNotEquals('sticky_value', $experiment->get('value', 'err'));
        $this->assertEquals('targetingGate', $experiment->rule_id);
    }

    public function testLayerStickyValueWinsWithoutEnforceOverrides()
    {
        $layer = $this->statsig->getLayer(
            self::makeUser('override-user', true),
            'enforce_layer',
            ['user_persisted_values' => self::stickyValues('enforce_layer', 'enforce_exp')]
        );

        $this->assertEquals('sticky_value', $layer->get('value', 'err'));
    }

    public function testLayerEnforceOverridesKeepsStickyWhenNoOverrideMatches()
    {
        $layer = $this->statsig->getLayer(
            self::makeUser('plain-user', true),
            'enforce_layer',
            [
                'user_persisted_values' => self::stickyValues('enforce_layer', 'enforce_exp'),
                'enforce_overrides' => true,
            ]
        );

        $this->assertEquals('sticky_value', $layer->get('value', 'err'));
    }

    public function testLayerEnforceOverridesLetsOverrideWinOverSticky()
    {
        $layer = $this->statsig->getLayer(
            self::makeUser('override-user', true),
            'enforce_layer',
            [
                'user_persisted_values' => self::stickyValues('enforce_layer', 'enforce_exp'),
                'enforce_overrides' => true,
            ]
        );

        $this->assertEquals('override_value', $layer->get('value', 'err'));
    }

    public function testLayerEnforceTargetingKeepsStickyWhenStillTargeted()
    {
        $layer = $this->statsig->getLayer(
            self::makeUser('plain-user', true),
            'enforce_layer',
            [
                'user_persisted_values' => self::stickyValues('enforce_layer', 'enforce_exp'),
                'enforce_targeting' => true,
            ]
        );

        $this->assertEquals('sticky_value', $layer->get('value', 'err'));
    }

    public function testLayerEnforceTargetingDropsStickyWhenNoLongerTargeted()
    {
        $layer = $this->statsig->getLayer(
            self::makeUser('plain-user', false),
            'enforce_layer',
            [
                'user_persisted_values' => self::stickyValues('enforce_layer', 'enforce_exp'),
                'enforce_targeting' => true,
            ]
        );

        $this->assertNotEquals('sticky_value', $layer->get('value', 'err'));
        // The live layer evaluation delegates to enforce_exp, whose targeting
        // rule matched, so the layer reports the delegate's rule id.
        $this->assertEquals('targetingGate', $layer->rule_id);
    }
}
