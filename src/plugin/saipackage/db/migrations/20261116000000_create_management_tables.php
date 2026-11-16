<?php
namespace plugin\saipackage\db\migrations;

use Phinx\Migration\AbstractMigration;
use Saithink\Saipackage\management\Store;

final class CreateManagementTables extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) config('plugin.saipackage.management.table_prefix', '');
        foreach (Store::TABLES as $name) {
            $this->table($prefix . 'saipackage_' . $name, ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'string', ['limit' => 191, 'null' => false])
                ->addColumn('payload', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG, 'null' => false])
                ->addColumn('revision', 'integer', ['null' => false])
                ->addColumn('updated_at', 'biginteger', ['null' => false])
                ->create();
        }
    }
}
