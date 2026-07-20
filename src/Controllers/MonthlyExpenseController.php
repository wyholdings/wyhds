<?php

namespace App\Controllers;

use App\Models\MonthlyExpenseModel;
use Twig\Environment;

class MonthlyExpenseController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function list(): void
    {
        $year = (int)($_GET['year'] ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('n'));
        $year = $year >= 2024 && $year <= 2100 ? $year : (int)date('Y');
        $month = $month >= 1 && $month <= 12 ? $month : (int)date('n');
        $model = new MonthlyExpenseModel();

        echo $this->twig->render('admin/money/monthly_expenses.html.twig', [
            'expenses' => $model->getByMonth($year, $month), 'summary' => $model->getSummary($year, $month),
            'year' => $year, 'month' => $month, 'years' => range((int)date('Y'), 2024), 'months' => range(1, 12),
        ]);
    }

    public function add(): void { $this->save(); }

    public function update(): void { $this->save((int)($_POST['id'] ?? 0)); }

    public function delete(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $this->json(false, '잘못된 요청');
        }
        $this->json((new MonthlyExpenseModel())->delete($id), '삭제하지 못했습니다.');
    }

    private function save(int $id = 0): void
    {
        $data = [
            'expense_date' => trim((string)($_POST['expense_date'] ?? '')), 'person_name' => trim((string)($_POST['person_name'] ?? '')),
            'item_name' => trim((string)($_POST['item_name'] ?? '')), 'amount' => (int)($_POST['amount'] ?? 0),
            'payment_account' => trim((string)($_POST['payment_account'] ?? '')), 'cardholder_name' => trim((string)($_POST['cardholder_name'] ?? '')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'is_recurring' => isset($_POST['is_recurring']) && $_POST['is_recurring'] === '1',
            'repeat_count' => max(1, min(120, (int)($_POST['repeat_count'] ?? 1))),
        ];
        if (!$data['is_recurring']) {
            $data['repeat_count'] = 1;
        }
        if (!$data['expense_date'] || !$data['person_name'] || !$data['item_name'] || $data['amount'] <= 0) {
            $this->json(false, '날짜, 사람, 항목, 금액을 입력해 주세요.');
        }
        $model = new MonthlyExpenseModel();
        $success = $id > 0 ? $model->update($id, $data) : $model->add($data);
        $this->json($success, $success ? '' : '저장하지 못했습니다.');
    }

    private function json(bool $success, string $message = ''): void
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
