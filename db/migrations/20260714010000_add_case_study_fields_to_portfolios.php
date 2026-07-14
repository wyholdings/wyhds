<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCaseStudyFieldsToPortfolios extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('portfolios');

        if (!$table->hasColumn('case_problem')) {
            $table->addColumn('case_problem', 'text', ['null' => true, 'after' => 'description']);
        }
        if (!$table->hasColumn('case_scope')) {
            $table->addColumn('case_scope', 'text', ['null' => true, 'after' => 'case_problem']);
        }
        if (!$table->hasColumn('case_result')) {
            $table->addColumn('case_result', 'text', ['null' => true, 'after' => 'case_scope']);
        }

        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('portfolios');
        foreach (['case_result', 'case_scope', 'case_problem'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }
        $table->update();
    }
}
