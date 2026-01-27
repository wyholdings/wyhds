<?php

namespace App\Controllers;

use App\Models\VisitorLogModel;
use Twig\Environment;

class VisitorLogController
{
    private Environment $twig;
    private VisitorLogModel $logModel;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->logModel = new VisitorLogModel();
    }

    public function leave(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        $id = (int)($_POST['id'] ?? 0);
        $duration = (int)($_POST['duration'] ?? 0);

        if ($id <= 0 || $duration <= 0) {
            echo json_encode(['success' => false]);
            return;
        }

        if ($duration > 86400) {
            $duration = 86400;
        }

        $this->logModel->updateDuration($id, $duration);
        echo json_encode(['success' => true]);
    }

    public function list(): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $keyword = trim((string)($_GET['q'] ?? ''));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;
        $logs = $this->logModel->getLogs($perPage, $offset, $keyword);
        $total = $this->logModel->countLogs($keyword);
        $totalPages = max(1, (int)ceil($total / $perPage));

        echo $this->twig->render('admin/visitor/logs.html.twig', [
            'page_title'  => '접속 로그',
            'logs'        => $logs,
            'page'        => $page,
            'total_pages' => $totalPages,
            'total'       => $total,
            'per_page'    => $perPage,
            'keyword'     => $keyword,
        ]);
    }
}

?>
