<?php

namespace App\Controllers;

use Twig\Environment;
use App\Models\MoneyModel;

class MoneyController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    //문의 목록
    public function list()
    {   
        $type = $_GET['type'] ?? '수입';
        $member_name = $_GET['member_name'] ?? 'cwy';
        $member_name_text = $member_name === 'cwy' ? '우용' : '민재';
        $year = $_GET['year'] ?? date('Y');

        $moneyModel = new MoneyModel();
        $monies = $moneyModel->getAll($member_name, $type, $year);
        $total_money = $moneyModel->getSumByMember($member_name, $type, $year);

        $current_year = (int)date('Y');
        $years = [];
        for ($y = $current_year; $y >= 2024; $y--) {
            $years[] = $y;
        }
        
        echo $this->twig->render('admin/money/list.html.twig', [
            'monies' => $monies,
            'total_money' => $total_money,
            'member_name' => $member_name,
            'type' => $type,
            'member_name_text' => $member_name_text,
            'year' => $year,
            'years' => $years,
        ]);
    }

    public function add() {
        $member_name = $_POST['member_name'] ?? 'cwy';
        $type = $_POST['type'] ?? '';
        $amount = intval($_POST['amount'] ?? 0);
        $vat = intval($_POST['vat'] ?? 0);
        $date = $_POST['date'] ?? '';
        $company = $_POST['company'] ?? '';
        $product = $_POST['product'] ?? '';

        if (!$type || !$amount || !$date) {
            return $this->jsonResponse(false, '필수 항목 누락');
        }

        $moneyModel = new MoneyModel();
        $id = $moneyModel->add($type, $amount, $vat, $date, $company, $product, $member_name);

        if ($id) {
            $this->jsonResponse(true, '', [
                'id' => $id,
                'type' => $type,
                'amount' => $amount,
                'vat' => $vat,
                'total' => $amount + $vat,
                'date' => $date,
                'company' => $company,
                'product' => $product
            ]);
        } else {
            $this->jsonResponse(false, 'DB 저장 실패');
        }
    }

    protected function jsonResponse($success, $message = '', $data = []) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }

    public function delete()
    {
        $id = intval($_POST['id'] ?? 0);

        if (!$id) {
            $this->jsonResponse(false, '잘못된 요청');
        }

        $moneyModel = new MoneyModel();
        $success = $moneyModel->delete($id);

        if ($success) {
            $this->jsonResponse(true);
        } else {
            $this->jsonResponse(false, 'DB 삭제 실패');
        }
    }

}
