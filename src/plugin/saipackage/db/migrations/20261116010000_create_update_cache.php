<?php
namespace plugin\saipackage\db\migrations;

use Phinx\Migration\AbstractMigration;

final class CreateUpdateCache extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) config('plugin.saipackage.management.table_prefix', '');
        $this->table($prefix . 'saipackage_updates', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'string', ['limit' => 191, 'null' => false])
            ->addColumn('payload', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG, 'null' => false])
            ->addColumn('revision', 'integer', ['null' => false])
            ->addColumn('updated_at', 'biginteger', ['null' => false])
            ->create();
    }
}
