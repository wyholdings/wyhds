<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateMonthlyExpensesTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('monthly_expenses');
        $table
            ->addColumn('expense_date', 'date')
            ->addColumn('person_name', 'string', ['limit' => 80])
            ->addColumn('item_name', 'string', ['limit' => 160])
            ->addColumn('amount', 'integer', ['signed' => false])
            ->addColumn('payment_account', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('cardholder_name', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('is_recurring', 'boolean', ['default' => false])
            ->addColumn('repeat_count', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['expense_date'])
            ->create();
    }
}
