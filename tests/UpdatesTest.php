<?php
use PHPUnit\Framework\TestCase;
use Saithink\Saipackage\management\{Store, Settings, Manager, Updates, UpdateSource, Tasks, Manifest, Services, UpgradeServices, SaiStoreUpdateSource, JsonUpdateSource};

final class FixtureUpdateSource implements UpdateSource
{
    public int $checks = 0;
    public int $downloads = 0;
    public ?Throwable $error = null;
    public array $items = [];
    public function releases(string $plugin): array { $this->checks++; if ($this->error) throw $this->error; return $this->items; }
    public function download(string $plugin, array $release, string $destination): void { $this->downloads++; }
}

final class UpdatesTest extends TestCase
{
    private Manager $manager;
    private FixtureUpdateSource $source;
    private array $config;
    private string $directory;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        foreach ([...Store::TABLES, ...Store::EXTRA_TABLES] as $name) $pdo->exec("CREATE TABLE saipackage_$name (id VARCHAR(191) PRIMARY KEY, payload TEXT NOT NULL, revision INTEGER NOT NULL, updated_at BIGINT NOT NULL)");
        $store = new Store($pdo);
        $this->directory = sys_get_temp_dir() . '/saipackage-updates-' . bin2hex(random_bytes(6)); mkdir($this->directory);
        $this->manager = new Manager($store, new Settings($store, base64_encode(str_repeat('u', 32))), $this->directory, ['php' => '8.4.0', 'saiadmin' => '6.1.5', 'saipackage' => '6.2.0']);
        $this->manager->recordInstalled('a', ['manifest' => $this->manifest('1.0.0'), 'schema' => ['type' => 'object', 'properties' => []]]);
        $this->source = new FixtureUpdateSource();
        $this->config = ['automatic' => true, 'interval' => 86400, 'retry_interval' => 900, 'plugins' => ['a' => 'fixture'],
            'sources' => ['fixture' => ['type' => 'json', 'url' => 'https://example.test/index.json', 'token' => 'private-update-credential']]];
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->directory);
    }

    private function manifest(string $version): array { return ['schemaVersion' => 1, 'id' => 'a', 'version' => $version]; }
    private function release(string $version): array { return ['version' => $version, 'manifest' => $this->manifest($version), 'sha256' => str_repeat('a', 64), 'url' => 'https://example.test/plugin.zip', 'notes' => 'Release notes', 'withdrawn' => false]; }
    private function updates(): Updates { return new Updates($this->manager, $this->config, ['fixture' => $this->source]); }

    public function testAutomaticDetectionNeverDownloadsOrInstalls(): void
    {
        $this->source->items = [$this->release('1.2.0')];
        self::assertTrue($this->updates()->tick());
        self::assertSame(1, $this->source->checks); self::assertSame(0, $this->source->downloads);
        self::assertSame('1.0.0', $this->manager->installed()['a']['manifest']['version']);
        self::assertSame([], $this->manager->store->all('tasks'));
        self::assertFalse($this->updates()->tick()); // restart also observes the durable interval
    }

    public function testLatestAndCompatibleVersionsAreDifferent(): void
    {
        $blocked = $this->release('2.0.0'); $blocked['manifest']['requiresHost'] = ['saiadmin' => '^7'];
        $this->source->items = [$this->release('1.2.0'), $blocked, $this->release('1.10.0')];
        $view = $this->updates()->check('a');
        self::assertSame('2.0.0', $view['latest']); self::assertSame('1.10.0', $view['compatible']);
        self::assertSame('blocked', $view['releases'][0]['compatibility']);
    }

    public function testPrereleasesWithdrawnAndOlderVersionsAreExcluded(): void
    {
        $withdrawn = $this->release('3.0.0'); $withdrawn['withdrawn'] = true;
        $this->source->items = [$withdrawn, $this->release('2.0.0-beta.1'), $this->release('0.9.0'), $this->release('1.0.0')];
        self::assertNull($this->updates()->check('a')['latest']);
    }

    public function testPrereleasesCanBeEnabledExplicitly(): void
    {
        $this->config['allow_prerelease'] = true;
        $this->source->items = [$this->release('1.2.0-beta.1')];
        self::assertSame('1.2.0-beta.1', $this->updates()->check('a')['latest']);
    }

    public function testEnvironmentChangeInvalidatesOldDownloadRequest(): void
    {
        $this->source->items = [$this->release('1.2.0')]; $view = $this->updates()->check('a');
        $this->manager->recordInstalled('b', ['manifest' => ['schemaVersion' => 1, 'id' => 'b', 'version' => '1.0.0'], 'schema' => ['type' => 'object', 'properties' => []]]);
        self::assertSame('pending', $this->updates()->view('a')['state']);
        $this->expectExceptionMessage('UPDATE_CHECK_REQUIRED');
        $this->updates()->requestPreparation('a', $view['releases'][0]['fingerprint'], 1);
    }

    public function testMissingDependenciesAndReverseConstraintsAreBlocked(): void
    {
        $release = $this->release('2.0.0'); $release['manifest']['requiresPlugins'] = ['missing' => '^2'];
        $this->source->items = [$release];
        self::assertSame('blocked', $this->updates()->check('a')['releases'][0]['compatibility']);
        $this->manager->recordInstalled('b', ['manifest' => ['schemaVersion' => 1, 'id' => 'b', 'version' => '1.0.0', 'requiresPlugins' => ['a' => '^1']], 'schema' => ['type' => 'object', 'properties' => []]]);
        $this->source->items = [$this->release('2.0.0')];
        self::assertSame('blocked', $this->updates()->check('a')['releases'][0]['compatibility']);
    }

    public function testOfficialMissingManifestIsUnknown(): void
    {
        $release = $this->release('1.1.0'); $release['manifest'] = null;
        $this->source->items = [$release];
        $view = $this->updates()->check('a');
        self::assertSame('unknown', $view['releases'][0]['compatibility']); self::assertNull($view['compatible']);
    }

    public function testErrorsBackoffWithoutLeakingCredentialsOrClaimingCurrent(): void
    {
        $this->source->items = [$this->release('1.1.0')]; $first = $this->updates()->check('a');
        $this->source->error = new RuntimeException('private-update-credential at https://secret.test');
        $view = $this->updates()->check('a');
        self::assertSame('error', $view['state']); self::assertSame($first['checkedAt'], $view['checkedAt']);
        self::assertStringNotContainsString('private-update-credential', json_encode($this->manager->store->all('updates')));
        self::assertFalse($this->updates()->tick());
        $this->expectExceptionMessage('UPDATE_CHECK_REQUIRED');
        $this->updates()->requestPreparation('a', $first['releases'][0]['fingerprint'], 1);
    }

    public function testManualRequestOnlySchedulesMetadataRefresh(): void
    {
        $this->config['automatic'] = false;
        self::assertFalse($this->updates()->tick());
        $this->updates()->requestCheck('a'); self::assertSame(0, $this->source->checks);
        self::assertTrue($this->updates()->tick()); self::assertSame(1, $this->source->checks);
    }

    public function testSourceChangesInvalidateCachedRecommendations(): void
    {
        $this->source->items = [$this->release('1.1.0')]; $view = $this->updates()->check('a');
        $this->config['sources']['fixture']['token'] = 'rotated';
        self::assertSame('pending', $this->updates()->view('a')['state']);
        $this->expectExceptionMessage('UPDATE_CHECK_REQUIRED');
        $this->updates()->requestPreparation('a', $view['releases'][0]['fingerprint'], 1);
    }

    public function testManualPreparationIsASeparateTaskAndDoesNotInstall(): void
    {
        $this->source->items = [$this->release('1.1.0')]; $view = $this->updates()->check('a');
        $id = $this->updates()->requestPreparation('a', $view['releases'][0]['fingerprint'], 1);
        $installed = false;
        (new Tasks($this->manager))->tick(function () use (&$installed) { $installed = true; }, fn () => null, fn () => ['root' => 'a', 'fingerprint' => 'prepared']);
        self::assertFalse($installed); self::assertSame(0, $this->source->downloads);
        $task = $this->manager->store->get('tasks', $id)['value'];
        self::assertSame('succeeded', $task['state']); self::assertSame('awaiting_confirmation', $task['stage']);
        self::assertSame('1.0.0', $this->manager->installed()['a']['manifest']['version']);
    }

    public function testOfficialStoreContractNormalization(): void
    {
        $result = SaiStoreUpdateSource::normalize([['id' => 12, 'version' => '1.2.0', 'remark' => 'fix']]);
        self::assertSame(12, $result[0]['versionId']); self::assertNull($result[0]['manifest']);
        self::assertNull($result[0]['sha256']);
    }

    public function testDefaultTransportRejectsPlainHttp(): void
    {
        $this->expectExceptionMessage('Invalid update source URL');
        new JsonUpdateSource(['url' => 'http://example.test/index.json']);
    }

    public function testRequiredExternalServiceBlocksUpgradeWithoutChangingInstallation(): void
    {
        $snapshot = ['schema' => ['type' => 'object', 'properties' => []], 'manifest' => $this->manifest('2.0.0') + ['externalServices' => [['id' => 'ocr', 'probe' => 'missing', 'settingsGroup' => 'ocr', 'required' => true]]]];
        $this->expectExceptionMessage('UPDATE_EXTERNAL_SERVICE_UNKNOWN');
        UpgradeServices::assertCompatible($this->manager, new Services($this->manager->store, $this->manager->settings, []), $snapshot);
    }

    public function testConfirmationIsAtomicAndCannotBeReplayed(): void
    {
        $this->source->items = [$this->release('1.1.0')];
        $view = $this->updates()->check('a');
        $path = $this->manager->packagePath('a'); mkdir($path);
        file_put_contents($path . 'info.ini', "app = a\nversion = 1.1.0\nstate = 2\ntitle = A\nauthor = Tests\nabout = Tests\n");
        file_put_contents($path . 'manifest.json', json_encode($this->manifest('1.1.0')));
        $this->manager->registerPackage('a');
        $this->manager->store->put('tasks', 'prepared', ['kind' => 'prepare_update', 'state' => 'succeeded', 'stage' => 'awaiting_confirmation', 'plugin' => 'a',
            'releaseFingerprint' => $view['releases'][0]['fingerprint'], 'result' => ['packageFingerprint' => $this->manager->fingerprint($path)]], 0);
        $services = new Services($this->manager->store, $this->manager->settings, []);
        $plan = $this->updates()->preparedPlan('prepared', $services);
        $id = $this->updates()->confirm('prepared', $plan['fingerprint'], 1, $services);
        self::assertSame('queued', $this->manager->store->get('tasks', $id)['value']['state']);
        self::assertSame($id, $this->manager->store->get('tasks', 'prepared')['value']['confirmedTaskId']);
        $this->expectExceptionMessage('UPDATE_ALREADY_CONFIRMED');
        $this->updates()->confirm('prepared', $plan['fingerprint'], 1, $services);
    }

    public function testChangedMigrationPreviewInvalidatesManualConfirmation(): void
    {
        $store = $this->manager->store; $settings = $this->manager->settings; $label = 'before';
        $settings->save('a', ['type'=>'object','properties'=>[]], 1, [], [], 0, 1);
        $this->manager = new Manager($store, $settings, $this->directory, [], ['a'=>static function () use (&$label) { return ['label'=>$label]; }]);
        $release = $this->release('1.1.0'); $release['manifest']['settingsVersion']=2; $this->source->items=[$release];
        $view = $this->updates()->check('a'); $path=$this->manager->packagePath('a'); mkdir($path);
        file_put_contents($path.'info.ini', "app = a\nversion = 1.1.0\nstate = 2\ntitle = A\nauthor = Tests\nabout = Tests\n");
        file_put_contents($path.'manifest.json', json_encode($release['manifest']));
        file_put_contents($path.'settings.schema.json', json_encode(['type'=>'object','properties'=>['label'=>['type'=>'string']], 'ui'=>['fields'=>['label'=>['connection'=>false]]]]));
        $this->manager->registerPackage('a');
        $store->put('tasks','prepared',['kind'=>'prepare_update','state'=>'succeeded','plugin'=>'a','releaseFingerprint'=>$view['releases'][0]['fingerprint'],
            'result'=>['packageFingerprint'=>$this->manager->fingerprint($path)]],0);
        $services=new Services($store,$settings,[]); $plan=$this->updates()->preparedPlan('prepared',$services);
        self::assertSame(1,$settings->raw('a')['version']);
        $label='after';
        $this->expectExceptionMessage('Installation plan has changed');
        $this->updates()->confirm('prepared',$plan['fingerprint'],1,$services);
    }
}
