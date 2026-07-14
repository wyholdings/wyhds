<?php

namespace App\Controllers;

use App\Models\PortfolioModel;
use Throwable;
use Twig\Environment;

class PortfolioController
{
    private Environment $twig;
    private array $uploadErrors = [];

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function list(): void
    {
        try {
            $portfolioModel = new PortfolioModel();
            $portfolios = $portfolioModel->all();
            $error = null;
        } catch (Throwable $e) {
            $portfolios = [];
            $error = $e->getMessage();
        }

        echo $this->twig->render('admin/portfolio/list.html.twig', [
            'portfolios' => $portfolios,
            'error' => $error,
        ]);
    }

    public function add(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = $this->requestData();
            $data['thumbnail_image'] = $this->uploadImage('thumbnail_image') ?: '';
            $data['body_image'] = $this->uploadImage('body_image') ?: '';

            if ($this->uploadErrors) {
                echo $this->twig->render('admin/portfolio/form.html.twig', [
                    'mode' => 'add',
                    'portfolio' => $data,
                    'upload_errors' => $this->uploadErrors,
                ]);
                return;
            }

            $portfolioModel = new PortfolioModel();
            $portfolioModel->create($data);
            $this->redirect('/admin/portfolio/list');
        }

        echo $this->twig->render('admin/portfolio/form.html.twig', [
            'mode' => 'add',
            'portfolio' => [],
            'upload_errors' => [],
        ]);
    }

    public function view($id): void
    {
        $portfolioModel = new PortfolioModel();
        $portfolio = $portfolioModel->find((int)$id);
        if (!$portfolio) {
            http_response_code(404);
            echo 'Portfolio not found';
            return;
        }

        echo $this->twig->render('admin/portfolio/view.html.twig', [
            'portfolio' => $portfolio,
        ]);
    }

    public function edit($id): void
    {
        $portfolioModel = new PortfolioModel();
        $portfolio = $portfolioModel->find((int)$id);
        if (!$portfolio) {
            http_response_code(404);
            echo 'Portfolio not found';
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = $this->requestData();
            $data['thumbnail_image'] = $this->uploadImage('thumbnail_image') ?: ($portfolio['thumbnail_image'] ?? '');
            $data['body_image'] = $this->uploadImage('body_image') ?: ($portfolio['body_image'] ?? '');

            if ($this->uploadErrors) {
                $data['id'] = $portfolio['id'];
                echo $this->twig->render('admin/portfolio/form.html.twig', [
                    'mode' => 'edit',
                    'portfolio' => $data,
                    'upload_errors' => $this->uploadErrors,
                ]);
                return;
            }

            $portfolioModel->update((int)$id, $data);
            $this->redirect('/admin/portfolio/list');
        }

        echo $this->twig->render('admin/portfolio/form.html.twig', [
            'mode' => 'edit',
            'portfolio' => $portfolio,
            'upload_errors' => [],
        ]);
    }

    public function delete($id): void
    {
        $portfolioModel = new PortfolioModel();
        $portfolioModel->delete((int)$id);
        $this->redirect('/admin/portfolio/list');
    }

    private function requestData(): array
    {
        return [
            'title' => $_POST['title'] ?? '',
            'subtitle' => $_POST['subtitle'] ?? '',
            'description' => $_POST['description'] ?? '',
            'case_problem' => $_POST['case_problem'] ?? '',
            'case_scope' => $_POST['case_scope'] ?? '',
            'case_result' => $_POST['case_result'] ?? '',
            'site_link' => $_POST['site_link'] ?? '',
            'client' => $_POST['client'] ?? '',
            'project_date' => $_POST['project_date'] ?? '',
            'keywords' => $_POST['keywords'] ?? '',
        ];
    }

    private function uploadImage(string $fieldName): ?string
    {
        if (!isset($_FILES[$fieldName]) || (int)($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $errorCode = (int)$_FILES[$fieldName]['error'];
        if ($errorCode !== UPLOAD_ERR_OK) {
            $this->uploadErrors[] = $this->uploadErrorMessage($fieldName, $errorCode);
            return null;
        }

        if (empty($_FILES[$fieldName]['tmp_name']) || !is_uploaded_file($_FILES[$fieldName]['tmp_name'])) {
            $this->uploadErrors[] = $this->labelForUpload($fieldName) . ' 업로드 임시 파일을 확인할 수 없습니다.';
            return null;
        }

        $extension = strtolower(pathinfo((string)$_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($extension, $allowed, true)) {
            $this->uploadErrors[] = $this->labelForUpload($fieldName) . '는 jpg, jpeg, png, gif, webp 파일만 업로드할 수 있습니다.';
            return null;
        }

        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/portfolio';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            $this->uploadErrors[] = '업로드 폴더를 생성할 수 없습니다: ' . $uploadDir;
            return null;
        }

        if (!is_writable($uploadDir)) {
            $this->uploadErrors[] = '업로드 폴더에 쓰기 권한이 없습니다: ' . $uploadDir;
            return null;
        }

        $filename = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $target = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $target)) {
            $this->uploadErrors[] = $this->labelForUpload($fieldName) . ' 파일을 업로드 폴더로 이동하지 못했습니다.';
            return null;
        }

        return '/uploads/portfolio/' . $filename;
    }

    private function uploadErrorMessage(string $fieldName, int $errorCode): string
    {
        $label = $this->labelForUpload($fieldName);
        $messages = [
            UPLOAD_ERR_INI_SIZE => "{$label} 파일이 서버 업로드 제한(upload_max_filesize)을 초과했습니다.",
            UPLOAD_ERR_FORM_SIZE => "{$label} 파일이 폼 업로드 제한을 초과했습니다.",
            UPLOAD_ERR_PARTIAL => "{$label} 파일이 일부만 업로드되었습니다.",
            UPLOAD_ERR_NO_TMP_DIR => "{$label} 업로드 임시 폴더가 없습니다.",
            UPLOAD_ERR_CANT_WRITE => "{$label} 파일을 디스크에 쓸 수 없습니다.",
            UPLOAD_ERR_EXTENSION => "{$label} 업로드가 PHP 확장에 의해 중단되었습니다.",
        ];

        return $messages[$errorCode] ?? "{$label} 업로드 중 오류가 발생했습니다. 오류 코드: {$errorCode}";
    }

    private function labelForUpload(string $fieldName): string
    {
        return $fieldName === 'thumbnail_image' ? '썸네일 이미지' : 'body 이미지';
    }

    private function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }
}
