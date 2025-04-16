<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCompaniesTable extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        $this->table('companies')
        ->addColumn('name', 'string', ['limit' => 255])
        ->addColumn('business_number', 'string', ['limit' => 20, 'null' => true])
        ->addColumn('type', 'enum', ['values' => ['partner', 'client', 'internal'], 'default' => 'client', 'null' => true])
        ->addColumn('contract_start', 'date', ['null' => true])
        ->addColumn('contract_end', 'date', ['null' => true])
        ->addColumn('manager', 'string', ['limit' => 100, 'null' => true])
        ->addColumn('phone', 'string', ['limit' => 50, 'null' => true])
        ->addColumn('email', 'string', ['limit' => 100, 'null' => true])
        ->addColumn('address', 'string', ['limit' => 255, 'null' => true])
        ->addColumn('status', 'enum', ['values' => ['active', 'inactive', 'hold'], 'default' => 'active', 'null' => true])
        ->addColumn('memo', 'text', ['null' => true])
        ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
        ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
        ->create();
    }
}
