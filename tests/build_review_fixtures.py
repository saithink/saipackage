"""Additional deterministic fixtures for upgrade/rollback failure regression tests."""
import json
import sys
import zipfile
from pathlib import Path

out = Path(sys.argv[1])
out.mkdir(parents=True, exist_ok=True)

def build(app, version, settings_version=1):
    cls = ''.join(x.title() for x in app.split('_'))
    manifest = {'schemaVersion': 1, 'id': app, 'version': version, 'settingsVersion': settings_version}
    schema = {'type': 'object', 'properties': {}}
    if app == 'managed_config':
        name = 'address' if settings_version == 3 else 'endpoint'
        schema = {'type': 'object', 'additionalProperties': False, 'required': [name, 'apiKey'], 'properties': {
            name: {'type': 'string'}, 'apiKey': {'type': 'string', 'writeOnly': True}}}
    files = {
        'info.ini': f'app = {app}\ntitle = {app}\nabout = review fixture\nauthor = Tests\nversion = {version}\nsupport = 6.x\n',
        'config.json': '{}', 'manifest.json': json.dumps(manifest), 'settings.schema.json': json.dumps(schema),
        f'plugin/{app}/release.txt': version,
        f'plugin/{app}/db/migrations/20261116000100_{app}_create.php': f'''<?php
namespace plugin\\{app}\\db\\migrations;
use Phinx\\Migration\\AbstractMigration;
final class {cls}Create extends AbstractMigration {{
 public function up(): void {{ $this->table('{app}_items')->addColumn('marker','string')->create(); }}
 public function down(): void {{
  if (is_file(runtime_path() . '/review-rollback-fail')) throw new \\RuntimeException('fixture rollback failure');
  $this->table('{app}_items')->drop()->save();
 }}
}}
''',
        f'plugin/{app}/db/seeds/{cls}Seeder.php': f'''<?php
namespace plugin\\{app}\\db\\seeds;
use Phinx\\Seed\\AbstractSeed;
final class {cls}Seeder extends AbstractSeed {{ public function run(): void {{ $this->table('{app}_items')->insert([['marker'=>'seed-once']])->saveData(); }} }}
''',
    }
    if version == '1.0.0':
        files[f'saiadmin-artd/src/views/plugin/{app}/obsolete.vue'] = '<template>old frontend</template>'
    else:
        files[f'plugin/{app}/db/migrations/20261116000200_{app}_upgrade.php'] = f'''<?php
namespace plugin\\{app}\\db\\migrations;
use Phinx\\Migration\\AbstractMigration;
final class {cls}Upgrade extends AbstractMigration {{ public function change(): void {{ $this->table('{app}_items')->addColumn('note','string',['null'=>true])->update(); }} }}
'''
    path = out / f'{app}-{version}.zip'
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as archive:
        for name, content in files.items():
            entry = zipfile.ZipInfo(name, (2026, 11, 16, 0, 0, 0)); entry.external_attr = 0o100644 << 16
            archive.writestr(entry, content, compress_type=zipfile.ZIP_DEFLATED)
    print(path.name)

build('managed_review', '1.0.0')
build('managed_review', '1.1.0')
build('managed_config', '1.0.0', 2)
build('managed_config', '1.1.0', 1)  # rejected before copying files/DDL
build('managed_config', '1.2.0', 3)
build('managed_config', '1.3.0', 3)

# SQL-only compatibility fixture; MySQL only, deliberately non-idempotent install.sql.
for version in ['1.0.0', '1.1.0']:
    files = {
        'info.ini': f'app = managed_sql\ntitle = managed_sql\nabout = review fixture\nauthor = Tests\nversion = {version}\nsupport = 6.x\n',
        'config.json': '{}',
        'manifest.json': json.dumps({'schemaVersion':1,'id':'managed_sql','version':version}),
        'plugin/managed_sql/release.txt': version,
        'install.sql': "CREATE TABLE managed_sql_items (marker VARCHAR(100) PRIMARY KEY);\nINSERT INTO managed_sql_items (marker) VALUES ('installed-once');\n",
        'update.sql': "INSERT INTO managed_sql_items (marker) VALUES ('upgraded-once');\n",
        'uninstall.sql': 'DROP TABLE managed_sql_items;\n',
    }
    with zipfile.ZipFile(out / f'managed_sql-{version}.zip', 'w', zipfile.ZIP_DEFLATED) as archive:
        for name, content in files.items():
            entry=zipfile.ZipInfo(name,(2026,11,16,0,0,0)); entry.external_attr=0o100644 << 16
            archive.writestr(entry,content,compress_type=zipfile.ZIP_DEFLATED)
