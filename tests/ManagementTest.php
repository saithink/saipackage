<?php
use PHPUnit\Framework\TestCase;
use Saithink\Saipackage\management\{Store, Settings, Manifest, DependencyResolver, Services, ServiceProbe, RuntimeGuard, Manager, Tasks};

final class ManagementTest extends TestCase
{
    private Store $store;
    private Settings $settings;
    private string $directory;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        foreach (Store::TABLES as $name) $pdo->exec("CREATE TABLE saipackage_$name (id VARCHAR(191) PRIMARY KEY, payload TEXT NOT NULL, revision INTEGER NOT NULL, updated_at BIGINT NOT NULL)");
        $this->store = new Store($pdo);
        $this->settings = new Settings($this->store, base64_encode(str_repeat('k', 32)));
        $this->directory = sys_get_temp_dir() . '/saipackage-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->directory);
    }

    private function item(string $id, string $version = '1.0.0', array $deps = [], string $source = 'package'): array
    {
        return ['manifest' => ['schemaVersion' => 1, 'id' => $id, 'version' => $version, 'requiresPlugins' => $deps], 'source' => $source];
    }

    private function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['ocr'], 'properties' => ['ocr' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['endpoint', 'apiKey'], 'properties' => [
                'endpoint' => ['type' => 'string', 'format' => 'uri'],
                'apiKey' => ['type' => 'string', 'minLength' => 1, 'writeOnly' => true],
                'timeout' => ['type' => 'integer', 'minimum' => 1, 'default' => 60],
            ],
        ]], 'ui' => ['fields' => ['ocr.timeout' => ['connection' => false]]]];
    }

    private function save(int $revision = 0, string $endpoint = 'http://ocr:8080', array $actions = []): int
    {
        return $this->settings->save('ocr', $this->schema(), 1, ['ocr' => ['endpoint' => $endpoint]], $actions ?: ['ocr.apiKey' => ['action' => 'replace', 'value' => 'test-private-key']], $revision, 1);
    }

    public function testDependencyTopologicalOrder(): void
    {
        $packages = ['a' => $this->item('a', '1.0.0', ['b' => '^1']), 'b' => $this->item('b', '1.0.0', ['c' => '^1']), 'c' => $this->item('c')];
        self::assertSame(['c', 'b', 'a'], array_keys((new DependencyResolver())->resolve('a', [], $packages)['steps']));
    }

    public function testResolverBacktracksSharedConstraints(): void
    {
        $installed = ['b' => $this->item('b', '1.0.0', [], 'installed')];
        $packages = ['a' => $this->item('a', '1.0.0', ['b' => '^1.3', 'c' => '^1']), 'b' => $this->item('b', '1.4.0'), 'c' => $this->item('c', '1.0.0', ['b' => '<2'])];
        self::assertSame('1.4.0', (new DependencyResolver())->resolve('a', $installed, $packages)['selected']['b']);
    }

    public function testReverseDependencyRejectsIncompatibleUpgrade(): void
    {
        $this->expectException(RuntimeException::class);
        (new DependencyResolver())->resolve('b', ['a' => $this->item('a', '1.0.0', ['b' => '^1'], 'installed'), 'b' => $this->item('b', '1.0.0', [], 'installed')], ['b' => $this->item('b', '2.0.0')]);
    }

    public function testCyclesShowChain(): void
    {
        $this->expectExceptionMessage('Circular plugin dependency: a -> b -> a');
        (new DependencyResolver())->resolve('a', [], ['a' => $this->item('a', '1.0.0', ['b' => '*']), 'b' => $this->item('b', '1.0.0', ['a' => '*'])]);
    }

    public function testMissingDependency(): void
    {
        $this->expectExceptionMessage('missing');
        (new DependencyResolver())->resolve('a', [], ['a' => $this->item('a', '1.0.0', ['missing' => '^1'])]);
    }

    public function testConflictAndHostCompatibility(): void
    {
        $a = $this->item('a');
        $a['manifest']['requiresHost'] = ['php' => '>=9'];
        $this->expectException(RuntimeException::class);
        (new DependencyResolver())->resolve('a', [], ['a' => $a], ['php' => '8.4.0']);
    }

    public function testInstalledCompatibleDependencyIsReused(): void
    {
        $result = (new DependencyResolver())->resolve('a', ['b' => $this->item('b', '1.2.0', [], 'installed')], ['a' => $this->item('a', '1.0.0', ['b' => '^1']), 'b' => $this->item('b', '1.3.0')]);
        self::assertSame(['a'], array_keys($result['steps']));
    }

    public function testUninstallProtectsEvenDisabledDependents(): void
    {
        $this->expectExceptionMessage('b is required by a');
        (new DependencyResolver())->assertRemovable('b', ['a' => $this->item('a', '1.0.0', ['b' => '^1']) + ['enabled' => false]]);
    }

    public function testDisableIgnoresDisabledDependents(): void
    {
        (new DependencyResolver())->assertRemovable('b', ['a' => $this->item('a', '1.0.0', ['b' => '^1']) + ['enabled' => false]], true);
        self::assertTrue(true);
    }

    public function testSettingsEncryptedRedactedAndRevisionProtected(): void
    {
        self::assertSame(1, $this->save());
        $row = $this->store->get('settings', 'ocr');
        self::assertStringNotContainsString('test-private-key', json_encode($row));
        $view = $this->settings->view('ocr', $this->schema());
        self::assertArrayNotHasKey('apiKey', $view['values']['ocr']);
        self::assertTrue($view['secrets']['ocr.apiKey']['configured']);
        $this->save(1, 'http://other:8080', ['ocr.apiKey' => ['action' => 'keep']]);
        self::assertSame('test-private-key', $this->settings->raw('ocr')['values']['ocr']['apiKey']);
        $this->expectExceptionMessage('Revision conflict');
        $this->save(1);
    }

    public function testSecretClearRespectsRequiredSchema(): void
    {
        $this->save();
        $this->expectExceptionMessage('Settings do not match schema');
        $this->save(1, 'http://ocr:8080', ['ocr.apiKey' => ['action' => 'clear']]);
    }

    public function testUnknownSettingsAndInlineSecretsAreRejected(): void
    {
        $this->expectExceptionMessage('Use secretActions');
        $this->settings->prepare('ocr', $this->schema(), ['ocr' => ['endpoint' => 'http://ocr', 'apiKey' => 'leak']], []);
    }

    public function testSchemaValidationAndDefaults(): void
    {
        $this->save();
        self::assertSame(60, $this->settings->raw('ocr')['values']['ocr']['timeout']);
        self::assertFalse(Manifest::validateSettings(['ocr' => ['endpoint' => 123]], $this->schema()));
    }

    public function testMissingKeyFailsClosed(): void
    {
        $this->expectExceptionMessage('SAIPACKAGE_SETTINGS_KEY');
        (new Settings($this->store, ''))->save('ocr', $this->schema(), 1, ['ocr' => ['endpoint' => 'http://ocr']], ['ocr.apiKey' => ['action' => 'replace', 'value' => 'secret']], 0, 1);
    }

    public function testDraftProbeDoesNotValidateSavedSettings(): void
    {
        $this->save();
        $probe = new class implements ServiceProbe { public function check(array $settings, array $service): array { return ['code' => 'OK']; } };
        $manifest = $this->serviceManifest();
        $services = new Services($this->store, $this->settings, ['ocr' => $probe]);
        $services->check('ocr', $manifest, $this->schema(), $this->settings->raw('ocr')['values']);
        self::assertNull($this->store->get('service_checks', 'ocr'));
    }

    private function serviceManifest(): array
    {
        return ['schemaVersion' => 1, 'id' => 'ocr', 'version' => '1.0.0', 'externalServices' => [['id' => 'ocr', 'probe' => 'ocr', 'settingsGroup' => 'ocr', 'required' => true]]];
    }

    public function testHealthRecoveryAndConfigurationInvalidation(): void
    {
        $this->save();
        $manifest = $this->serviceManifest();
        $manager = new Manager($this->store, $this->settings, $this->directory);
        $manager->recordInstalled('ocr', ['manifest' => $manifest, 'schema' => $this->schema()]);
        $guard = new RuntimeGuard($this->store, $this->settings);
        self::assertSame('settings_unverified', $guard->status('ocr')['status']);
        $probe = new class implements ServiceProbe {
            public string $code = 'OK';
            public function check(array $settings, array $service): array { return ['code' => $this->code, 'message' => 'secret leak']; }
        };
        $services = new Services($this->store, $this->settings, ['ocr' => $probe]);
        $services->check('ocr', $manifest, $this->schema());
        self::assertTrue($guard->status('ocr')['ready']);
        self::assertStringNotContainsString('secret leak', json_encode($this->store->get('service_checks', 'ocr')));
        $probe->code = 'TIMEOUT';
        $services->check('ocr', $manifest, $this->schema());
        self::assertSame('service_unhealthy', $guard->status('ocr')['status']);
        self::assertTrue($guard->status('ocr')['enabled']);
        $probe->code = 'OK';
        $services->check('ocr', $manifest, $this->schema());
        self::assertTrue($guard->status('ocr')['ready']);
        $this->save(1, 'http://changed:8080');
        self::assertSame('settings_unverified', $guard->status('ocr')['status']);
    }

    public function testNonConnectionSettingPreservesVerification(): void
    {
        $this->save();
        $old = $this->settings->fingerprint($this->settings->raw('ocr')['values'], $this->schema());
        $this->settings->save('ocr', $this->schema(), 1, ['ocr' => ['endpoint' => 'http://ocr:8080', 'timeout' => 90]], [], 1, 1);
        self::assertSame($old, $this->settings->fingerprint($this->settings->raw('ocr')['values'], $this->schema()));
    }

    public function testProbeDetectsConcurrentSettingsChange(): void
    {
        $this->save();
        $probe = new class($this->settings, $this->schema()) implements ServiceProbe {
            public function __construct(private Settings $settings, private array $schema) {}
            public function check(array $settings, array $service): array {
                $this->settings->save('ocr', $this->schema, 1, ['ocr' => ['endpoint' => 'http://changed']], [], 1, 2);
                return ['code' => 'OK'];
            }
        };
        $this->expectExceptionMessage('Settings changed during connection test');
        (new Services($this->store, $this->settings, ['ocr' => $probe]))->check('ocr', $this->serviceManifest(), $this->schema());
    }

    public function testSettingsMigrationFailurePreservesOldRevision(): void
    {
        $this->save();
        try {
            $this->settings->migrate('ocr', $this->schema(), 2, fn () => throw new RuntimeException('failed'));
            self::fail('Expected failure');
        } catch (RuntimeException) {
            self::assertSame(1, $this->settings->raw('ocr')['revision']);
            self::assertSame(1, $this->settings->raw('ocr')['version']);
        }
    }

    public function testClearSettingsAndChecks(): void
    {
        $this->save();
        $this->settings->clear('ocr', 1);
        self::assertSame(2, $this->settings->raw('ocr')['revision']);
        self::assertSame([], $this->settings->raw('ocr')['values']);
    }

    public function testFailedStateBlocksBusinessEvenWithValidConfig(): void
    {
        $manager = new Manager($this->store, $this->settings, $this->directory);
        $manager->recordInstalled('a', ['manifest' => $this->item('a')['manifest'], 'schema' => ['type' => 'object', 'properties' => []]]);
        $manager->mark('a', 'failed');
        $this->expectExceptionMessage('Plugin unavailable');
        (new RuntimeGuard($this->store, $this->settings))->assertReady('a');
    }

    public function testPluginWithoutConfigurationIsReadyWithoutEncryptionKey(): void
    {
        $settings = new Settings($this->store, '');
        $manager = new Manager($this->store, $settings, $this->directory);
        $manager->recordInstalled('a', ['manifest' => $this->item('a')['manifest'], 'schema' => ['type' => 'object', 'properties' => []], 'source' => 'package']);
        self::assertTrue((new RuntimeGuard($this->store, $settings))->status('a')['ready']);
        self::assertSame('installed', $manager->installed()['a']['source']);
    }

    public function testInterruptedTaskRequiresExplicitRecovery(): void
    {
        $manager = new Manager($this->store, $this->settings, $this->directory);
        $this->store->put('tasks', 'task', ['state' => 'running', 'kind' => 'install', 'current' => 'a', 'completed' => [], 'events' => []], 0);
        $called = false;
        (new Tasks($manager))->tick(function () use (&$called) { $called = true; }, fn () => null);
        self::assertFalse($called);
        self::assertSame('needs_attention', $this->store->get('tasks', 'task')['value']['state']);
    }

    public function testComposerIntersectionDoesNotRelaxExistingRanges(): void
    {
        $constraint = \Saithink\Saipackage\management\LibraryConstraints::intersect('^1 || ^2', '>=1.5 <2.5');
        self::assertFalse(Manifest::matches('1.4.0', $constraint));
        self::assertTrue(Manifest::matches('1.8.0', $constraint));
        self::assertTrue(Manifest::matches('2.3.0', $constraint));
        self::assertFalse(Manifest::matches('2.8.0', $constraint));
    }

    public function testConflictingPluginsAreRejected(): void
    {
        $a = $this->item('a');
        $a['manifest']['conflictsPlugins'] = ['b' => '^1'];
        $this->expectException(RuntimeException::class);
        (new DependencyResolver())->resolve('a', ['b' => $this->item('b', '1.0.0', [], 'installed')], ['a' => $a]);
    }

    public function testUnknownUnrelatedLegacyVersionDoesNotBlockInstallation(): void
    {
        $result = (new DependencyResolver())->resolve('a', ['legacy' => $this->item('legacy', '', [], 'installed')], ['a' => $this->item('a')]);
        self::assertSame(['a'], array_keys($result['steps']));
    }

    public function testUnknownRequiredVersionIsNotAssumedCompatible(): void
    {
        $this->expectException(RuntimeException::class);
        (new DependencyResolver())->resolve('a', ['legacy' => $this->item('legacy', '', [], 'installed')], ['a' => $this->item('a', '1.0.0', ['legacy' => '*'])]);
    }

    private function stage(string $id, array $deps = []): Manager
    {
        $path = $this->directory . '/' . $id;
        mkdir($path);
        file_put_contents($path . '/info.ini', "app = $id\nversion = 1.0.0\nstate = 2\nsupport = 6.x\ntitle = Test\nauthor = Test\nabout = Test\n");
        file_put_contents($path . '/manifest.json', json_encode($this->item($id, '1.0.0', $deps)['manifest']));
        return new Manager($this->store, $this->settings, $this->directory);
    }

    public function testPersistentDependencyTaskInstallsEachPluginOnce(): void
    {
        $this->stage('a', ['b' => '^1']);
        $manager = $this->stage('b');
        $plan = $manager->plan('a');
        $tasks = new Tasks($manager);
        $id = $tasks->submit('a', $plan['fingerprint'], 1);
        $calls = [];
        $install = function ($plugin, $snapshot) use ($manager, &$calls) {
            $calls[] = $plugin;
            $manager->recordInstalled($plugin, $snapshot);
            return ['state' => 1];
        };
        $tasks->tick($install, fn () => null);
        $tasks->tick($install, fn () => null);
        $tasks->tick($install, fn () => null);
        self::assertSame(['b', 'a'], $calls);
        self::assertSame('succeeded', $this->store->get('tasks', $id)['value']['state']);
    }

    public function testChangedPackageInvalidatesReviewedPlan(): void
    {
        $manager = $this->stage('a');
        $plan = $manager->plan('a');
        file_put_contents($this->directory . '/a/config.json', '{"require":{}}');
        $this->expectExceptionMessage('Installation plan has changed');
        (new Tasks($manager))->submit('a', $plan['fingerprint'], 1);
    }

    public function testFailedTaskRetainsCompletedDependencyAndRequestsWorkerRecycle(): void
    {
        $this->stage('a', ['b' => '^1']);
        $manager = $this->stage('b');
        $tasks = new Tasks($manager);
        $id = $tasks->submit('a', $manager->plan('a')['fingerprint'], 1);
        $tasks->tick(function ($plugin, $snapshot) use ($manager) { $manager->recordInstalled($plugin, $snapshot); return ['state' => 1]; }, fn () => null);
        self::assertTrue($tasks->tick(function ($id, $snapshot, $progress) { $progress('database'); throw new RuntimeException('private exception detail'); }, fn () => null));
        $row = $this->store->get('tasks', $id);
        self::assertSame(['b'], $row['value']['completed']);
        self::assertSame('needs_attention', $row['value']['state']);
        self::assertSame('database', $row['value']['stage']);
        self::assertStringNotContainsString('private exception detail', json_encode($row));
        self::assertSame('installed', $this->store->get('installations', 'b')['value']['state']);
    }

    public function testUninstallTaskRetainsWarningsAndCompletes(): void
    {
        $manager = $this->stage('a');
        $snapshot = $manager->registerPackage('a');
        $manager->recordInstalled('a', $snapshot);
        $tasks = new Tasks($manager);
        $id = $tasks->submitUninstall('a', 1);
        $tasks->tick(fn () => null, function ($plugin) use ($manager) { $manager->mark($plugin, 'uninstalled'); return ['File backup failed']; });
        self::assertSame('succeeded', $this->store->get('tasks', $id)['value']['state']);
        self::assertSame(['File backup failed'], $this->store->get('tasks', $id)['value']['warnings']);
    }

    public function testSchemaCannotHideSecretsBehindReferences(): void
    {
        file_put_contents($this->directory . '/settings.schema.json', json_encode(['type' => 'object', 'properties' => [], '$ref' => '#/$defs/hidden']));
        $this->expectExceptionMessage('explicit properties');
        Manifest::schema($this->directory);
    }

    public function testSettingsMigrationRetainsEncryptedSnapshotAndMarksNewRequiredFieldMissing(): void
    {
        $this->save();
        $schema = $this->schema();
        $schema['properties']['ocr']['required'][] = 'model';
        $schema['properties']['ocr']['properties']['model'] = ['type' => 'string'];
        $this->settings->migrate('ocr', $schema, 2, fn ($values) => $values);
        $row = $this->store->get('settings', 'ocr');
        self::assertSame(2, $row['value']['version']);
        self::assertCount(1, $row['value']['history']);
        self::assertStringNotContainsString('test-private-key', json_encode($row));
        self::assertFalse(Manifest::validateSettings($this->settings->raw('ocr')['values'], $schema));
    }

    public function testFailedPrerequisiteMustBeRepairedInsteadOfReused(): void
    {
        $failed = $this->item('b', '1.0.0', [], 'installed') + ['state' => 'failed'];
        $resolver = new DependencyResolver();
        $packages = ['a' => $this->item('a', '1.0.0', ['b' => '^1'])];
        try { $resolver->resolve('a', ['b' => $failed], $packages); self::fail('Failed dependency reused'); }
        catch (RuntimeException $e) { self::assertStringContainsString('Unsatisfied', $e->getMessage()); }
        $packages['b'] = $this->item('b');
        self::assertSame(['b', 'a'], array_keys($resolver->resolve('a', ['b' => $failed], $packages)['steps']));
    }

    public function testServiceContractChangeInvalidatesHealthyResultWithUnchangedSettings(): void
    {
        $this->save();
        $manager = new Manager($this->store, $this->settings, $this->directory);
        $snapshot = ['manifest' => $this->serviceManifest(), 'schema' => $this->schema()];
        $manager->recordInstalled('ocr', $snapshot);
        $probe = new class implements ServiceProbe { public function check(array $settings, array $service): array { return ['code' => 'OK']; } };
        (new Services($this->store, $this->settings, ['ocr' => $probe]))->check('ocr', $snapshot['manifest'], $snapshot['schema']);
        self::assertTrue((new RuntimeGuard($this->store, $this->settings))->status('ocr')['ready']);
        $snapshot['manifest']['externalServices'][0]['version'] = '^3';
        $manager->recordInstalled('ocr', $snapshot);
        self::assertFalse((new RuntimeGuard($this->store, $this->settings))->status('ocr')['ready']);
    }

    public function testRemovedOrReclassifiedSecretsNeverAppearInSettingsView(): void
    {
        $this->save();
        $plain = $this->schema();
        unset($plain['properties']['ocr']['properties']['apiKey']['writeOnly']);
        $this->settings->migrate('ocr', $plain, 2, null);
        self::assertStringNotContainsString('test-private-key', json_encode($this->settings->view('ocr', $plain)));
        unset($plain['properties']['ocr']['properties']['apiKey']);
        self::assertStringNotContainsString('test-private-key', json_encode($this->settings->view('ocr', $plain)));
        // Rows saved before secretPaths existed acquire their previous classification on upgrade.
        $row = $this->store->get('settings', 'ocr'); $value = $row['value']; unset($value['secretPaths']);
        $this->store->put('settings', 'ocr', $value, $row['revision']);
        $prepared = $this->settings->prepareMigration('ocr', $plain, 3, null, $this->schema());
        $this->settings->commitMigration('ocr', $prepared);
        self::assertSame(['ocr.apiKey'], $this->store->get('settings', 'ocr')['value']['secretPaths']);
    }

    public function testClearAndRecreateCannotReuseAStaleSettingsRevision(): void
    {
        $this->save();
        $this->settings->clear('ocr', 1);
        $this->save(2);
        $this->expectExceptionMessage('Revision conflict');
        $this->save(1);
    }

    public function testSettingsSaveCannotDowngradeAMigratedConfiguration(): void
    {
        $this->save();
        $this->settings->migrate('ocr', $this->schema(), 2, null);
        $this->expectExceptionMessage('Settings version downgrade');
        $this->save(2);
    }

    public function testMigrationPreviewDoesNotPersistAndCommitRejectsConcurrentEdits(): void
    {
        $this->save();
        $prepared = $this->settings->prepareMigration('ocr', $this->schema(), 2, fn ($values) => $values);
        self::assertSame(1, $this->settings->raw('ocr')['version']);
        $this->save(1, 'http://changed');
        $this->expectExceptionMessage('Settings changed during installation');
        $this->settings->commitMigration('ocr', $prepared);
    }

    public function testUpgradeProbesMigratedValuesAgainstTargetSchema(): void
    {
        $this->save();
        $manager = new Manager($this->store, $this->settings, $this->directory, [], ['ocr' => static function ($values) { $values['service'] = $values['ocr']; unset($values['ocr']); return $values; }]);
        $manager->recordInstalled('ocr', ['manifest' => $this->serviceManifest(), 'schema' => $this->schema()]);
        $schema = $this->schema(); $schema['properties']['service'] = $schema['properties']['ocr']; unset($schema['properties']['ocr']); $schema['required'] = ['service'];
        $manifest = $this->serviceManifest(); $manifest['settingsVersion'] = 2; $manifest['externalServices'][0]['settingsGroup'] = 'service';
        $probe = new class implements ServiceProbe { public function check(array $settings, array $service): array { return ['code' => ($settings['apiKey'] ?? '') === 'test-private-key' ? 'OK' : 'AUTH_FAILED']; } };
        \Saithink\Saipackage\management\UpgradeServices::assertCompatible($manager, new Services($this->store, $this->settings, ['ocr' => $probe]), ['schema' => $schema, 'manifest' => $manifest]);
        self::assertSame(1, $this->settings->raw('ocr')['version']);
    }

    public function testFailedUninstallCanRetryTheSameInstallationAfterStateRevisionChanges(): void
    {
        $manager = $this->stage('a');
        $manager->recordInstalled('a', $manager->registerPackage('a'));
        $tasks = new Tasks($manager); $id = $tasks->submitUninstall('a', 1);
        $tasks->tick(fn () => null, function () use ($manager) { $manager->mark('a', 'uninstalling'); $manager->mark('a', 'failed'); throw new RuntimeException('rollback failed'); });
        $tasks->recover($id, 'retry', $this->store->get('tasks', $id)['revision'], 1);
        $tasks->tick(fn () => null, function () use ($manager) { $manager->mark('a', 'uninstalled'); return []; });
        self::assertSame('succeeded', $this->store->get('tasks', $id)['value']['state']);
    }

    public function testNewBusinessSettingsCanRemainPendingWhenRequiredServiceIsCompatible(): void
    {
        $this->save();
        $manager = new Manager($this->store, $this->settings, $this->directory);
        $manager->recordInstalled('ocr', ['manifest' => $this->serviceManifest(), 'schema' => $this->schema()]);
        $schema = $this->schema(); $schema['properties']['model'] = ['type'=>'string']; $schema['required'][] = 'model';
        $probe = new class implements ServiceProbe { public function check(array $settings, array $service): array { return ['code' => 'OK']; } };
        \Saithink\Saipackage\management\UpgradeServices::assertCompatible($manager, new Services($this->store, $this->settings, ['ocr'=>$probe]), ['manifest'=>$this->serviceManifest(),'schema'=>$schema]);
        self::assertFalse(Manifest::validateSettings($this->settings->raw('ocr')['values'], $schema));
    }

    public function testIncompleteRegistryFinalizationRemainsPlannable(): void
    {
        $manager = $this->stage('a'); $snapshot = $manager->registerPackage('a');
        $manager->begin('a', $snapshot);
        file_put_contents($this->directory . '/a/info.ini', str_replace('state = 2', 'state = 1', file_get_contents($this->directory . '/a/info.ini')));
        self::assertSame(['a'], array_keys($manager->plan('a')['steps']));
    }

    public function testInterruptedStagingArtifactsAreNotImportedAsPluginRecords(): void
    {
        mkdir($this->directory . '/.previous-fixture');
        file_put_contents($this->directory . '/.previous-fixture/info.ini', "app = a\nversion = 1.0.0\nstate = 1\n");
        self::assertSame([], \Saithink\Saipackage\service\Server::installedList($this->directory . '/'));
    }

    public function testUninstallRetryRejectsAReplacementInstallation(): void
    {
        $manager = $this->stage('a'); $snapshot = $manager->registerPackage('a'); $manager->recordInstalled('a', $snapshot);
        $tasks = new Tasks($manager); $id = $tasks->submitUninstall('a', 1);
        $tasks->tick(fn () => null, function () use ($manager) { $manager->mark('a', 'failed'); throw new RuntimeException('failed'); });
        $snapshot['fingerprint'] = 'replacement'; $manager->recordInstalled('a', $snapshot);
        $this->expectExceptionMessage('Installation changed');
        $tasks->recover($id, 'retry', $this->store->get('tasks', $id)['revision'], 1);
    }

    public function testWaitingLibrariesCannotRetryDatabaseWork(): void
    {
        $manager = $this->stage('a'); $tasks = new Tasks($manager); $id = $tasks->submit('a', $manager->plan('a')['fingerprint'], 1);
        $tasks->tick(fn () => ['state' => 4], fn () => null);
        $this->expectExceptionMessage('Complete library installation');
        $tasks->recover($id, 'retry', $this->store->get('tasks', $id)['revision'], 1);
    }

    public function testAcceptCompletedRequiresTheReviewedPayload(): void
    {
        $manager = $this->stage('a'); $tasks = new Tasks($manager); $id = $tasks->submit('a', $manager->plan('a')['fingerprint'], 1);
        $tasks->tick(fn () => throw new RuntimeException('failed'), fn () => null);
        $snapshot = $manager->registerPackage('a'); $snapshot['fingerprint'] = 'different'; $manager->recordInstalled('a', $snapshot);
        $this->expectExceptionMessage('Installed package differs from plan');
        $tasks->recover($id, 'accept_completed', $this->store->get('tasks', $id)['revision'], 1);
    }
}
