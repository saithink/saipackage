"""Build deterministic ZIP fixtures; no Docker or network is used by the builder."""
import json
import sys
import zipfile
from pathlib import Path

out = Path(sys.argv[1])
out.mkdir(parents=True, exist_ok=True)

def build(app, version, deps=None, external=False, fail=False, service_version='^2'):
    manifest = {"schemaVersion": 1, "id": app, "version": version, "requiresPlugins": deps or {}, "settingsVersion": 1}
    schema = {"type": "object", "properties": {}}
    if external:
        manifest['externalServices'] = [{"id": "ocr", "probe": "json-health", "settingsGroup": "ocr", "required": True, "version": service_version, "capabilities": ["recognize"]}]
        schema = {"type": "object", "additionalProperties": False, "required": ["ocr"], "properties": {"ocr": {
            "type": "object", "additionalProperties": False, "required": ["endpoint", "apiKey"], "properties": {
                "endpoint": {"type": "string", "format": "uri", "title": "Endpoint"},
                "apiKey": {"type": "string", "writeOnly": True, "minLength": 1, "title": "API key"},
                "timeout": {"type": "integer", "minimum": 1, "default": 60, "title": "Timeout"}
            }}}, "ui": {"fields": {"ocr.timeout": {"connection": False}}}}
    cls = ''.join(part.title() for part in app.split('_'))
    migration = f'''<?php
namespace plugin\\{app}\\db\\migrations;
use Phinx\\Migration\\AbstractMigration;
final class {cls}Create extends AbstractMigration {{
 public function change(): void {{
  $this->table('{app}_items')->addColumn('marker', 'string', ['null'=>false])->create();
 }}
}}
'''
    files = {
        'info.ini': f'app = {app}\ntitle = {app}\nabout = fixture\nauthor = Tests\nversion = {version}\nsupport = 6.x\n',
        'config.json': '{}', 'manifest.json': json.dumps(manifest), 'settings.schema.json': json.dumps(schema),
        f'plugin/{app}/db/migrations/20261116000100_{app}_create.php': migration,
        f'plugin/{app}/app/controller/IndexController.php': f'''<?php
namespace plugin\\{app}\\app\\controller;
class IndexController {{ public function index() {{ return json(['fixture' => '{version}']); }} }}
''',
        f'plugin/{app}/config/app.php': "<?php return ['controller_suffix' => 'Controller', 'controller_reuse' => false];",
        'docs/deployment.md': 'Deploy your own service, then configure its backend-reachable endpoint. Docker is never managed by saipackage.'
    }
    if version != '1.0.0':
        body = "throw new \\RuntimeException('fixture failure');" if fail else f"$this->table('{app}_items')->addColumn('note', 'string', ['null'=>true])->update();"
        files[f'plugin/{app}/db/migrations/20261116000200_{app}_upgrade.php'] = f'''<?php
namespace plugin\\{app}\\db\\migrations;
use Phinx\\Migration\\AbstractMigration;
final class {cls}Upgrade extends AbstractMigration {{ public function change(): void {{ {body} }} }}
'''
    if version == '1.0.0': files[f'plugin/{app}/obsolete.txt'] = 'must disappear during upgrade'
    path = out / f'{app}-{version}.zip'
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as archive:
        for name, content in files.items():
            entry = zipfile.ZipInfo(name, (2026, 11, 16, 0, 0, 0))
            entry.external_attr = 0o100644 << 16
            archive.writestr(entry, content, compress_type=zipfile.ZIP_DEFLATED)
    print(path.name)

build('managed_dep', '1.0.0')
build('managed_ocr', '1.0.0', {'managed_dep': '^1'}, True)
build('managed_ocr', '1.1.0', {'managed_dep': '^1'}, True)
build('managed_fail', '1.1.0', fail=True)
build('managed_fail', '1.1.1')
build('managed_async', '1.0.0')
build('managed_ocr', '1.2.0', {'managed_dep': '^1'}, True)
build('managed_ocr', '1.3.0', {'managed_dep': '^1'}, True, service_version='^3')
build('managed_pre', '0.9.0-beta.1')
build('managed_pre', '0.9.0')
