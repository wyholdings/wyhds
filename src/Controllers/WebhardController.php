<?php

namespace App\Controllers;

use RuntimeException;
use Twig\Environment;
use App\Models\WebhardLogModel;

class WebhardController
{
    private Environment $twig;
    private string $baseDir;
    private WebhardLogModel $logModel;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $configuredPath = $_ENV['WEBHARD_PATH'] ?? '';
        $this->baseDir = $configuredPath !== '' ? rtrim($configuredPath, "\\/") : realpath(__DIR__ . '/../../public') . DIRECTORY_SEPARATOR . 'webhard';
        $this->logModel = new WebhardLogModel();
        $this->ensureBaseDir();
    }

    public function index(): void
    {
        $relativePath = $this->sanitizeRelativePath($_GET['path'] ?? '');
        $currentDir = $this->resolvePath($relativePath);

        if (!is_dir($currentDir)) {
            $this->flash('지정한 폴더를 찾을 수 없습니다. 루트로 이동합니다.', true);
            $this->redirect('');
        }

        [$folders, $files] = $this->scanDirectory($currentDir, $relativePath);
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        echo $this->twig->render('admin/webhard/index.html.twig', [
            'page_title'   => '웹하드',
            'current_path' => $relativePath,
            'parent_path'  => $this->getParentPath($relativePath),
            'folders'      => $folders,
            'files'        => $files,
            'breadcrumbs'  => $this->buildBreadcrumbs($relativePath),
            'flash'        => $flash,
        ]);
    }

    public function createFolder(): void
    {
        $relativePath = $this->sanitizeRelativePath($_POST['path'] ?? '');
        $folderName = $this->sanitizeSegment($_POST['folder_name'] ?? '');

        if ($folderName === '') {
            $this->flash('폴더 이름을 입력해 주세요.', true);
            $this->redirect($relativePath);
        }

        $targetDir = $this->resolvePath($this->joinRelative($relativePath, $folderName));

        if (file_exists($targetDir)) {
            $this->flash('같은 이름의 폴더가 이미 있습니다.', true);
            $this->logAction('create_folder', $this->joinRelative($relativePath, $folderName), 'fail', 'exists');
            $this->redirect($relativePath);
        }

        if (!mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $this->flash('폴더를 생성하지 못했습니다.', true);
            $this->logAction('create_folder', $this->joinRelative($relativePath, $folderName), 'fail', 'mkdir_failed');
            $this->redirect($relativePath);
        }

        $this->logAction('create_folder', $this->joinRelative($relativePath, $folderName), 'success');
        $this->flash('폴더를 생성했습니다.');
        $this->redirect($relativePath);
    }

    public function rename(): void
    {
        $relativePath = $this->sanitizeRelativePath($_POST['path'] ?? '');
        $oldName = $this->sanitizeSegment($_POST['old_name'] ?? '');
        $newName = $this->sanitizeSegment($_POST['new_name'] ?? '');

        if ($oldName === '' || $newName === '') {
            $this->flash('변경할 이름을 입력해 주세요.', true);
            $this->logAction('rename', $this->joinRelative($relativePath, $oldName), 'fail', 'empty');
            $this->redirect($relativePath);
        }

        $oldPath = $this->resolvePath($this->joinRelative($relativePath, $oldName));
        $newPath = $this->resolvePath($this->joinRelative($relativePath, $newName));

        if (!file_exists($oldPath)) {
            $this->flash('대상 항목을 찾을 수 없습니다.', true);
            $this->logAction('rename', $this->joinRelative($relativePath, $oldName), 'fail', 'not_found');
            $this->redirect($relativePath);
        }

        if (file_exists($newPath)) {
            $this->flash('이미 같은 이름이 존재합니다.', true);
            $this->logAction('rename', $this->joinRelative($relativePath, $oldName), 'fail', 'target_exists');
            $this->redirect($relativePath);
        }

        if (!@rename($oldPath, $newPath)) {
            $this->flash('이름을 변경하지 못했습니다.', true);
            $this->logAction('rename', $this->joinRelative($relativePath, $oldName), 'fail', 'rename_error');
            $this->redirect($relativePath);
        }

        $this->logAction('rename', $this->joinRelative($relativePath, $oldName), 'success', 'to:' . $newName);
        $this->flash('이름을 변경했습니다.');
        $this->redirect($relativePath);
    }

    public function delete(): void
    {
        $relativePath = $this->sanitizeRelativePath($_POST['path'] ?? '');
        $targetName = $this->sanitizeSegment($_POST['target_name'] ?? '');

        if ($targetName === '') {
            $this->flash('삭제할 대상을 선택해 주세요.', true);
            $this->logAction('delete', $this->joinRelative($relativePath, $targetName), 'fail', 'empty');
            $this->redirect($relativePath);
        }

        $targetPath = $this->resolvePath($this->joinRelative($relativePath, $targetName));

        if ($targetPath === $this->baseDir) {
            $this->flash('루트 폴더는 삭제할 수 없습니다.', true);
            $this->logAction('delete', $this->joinRelative($relativePath, $targetName), 'fail', 'base_dir');
            $this->redirect($relativePath);
        }

        if (!file_exists($targetPath)) {
            $this->flash('삭제할 대상을 찾을 수 없습니다.', true);
            $this->logAction('delete', $this->joinRelative($relativePath, $targetName), 'fail', 'not_found');
            $this->redirect($relativePath);
        }

        if (is_dir($targetPath)) {
            $this->deleteDirectory($targetPath);
        } else {
            @unlink($targetPath);
        }

        $this->logAction('delete', $this->joinRelative($relativePath, $targetName), 'success');
        $this->flash('삭제가 완료되었습니다.');
        $this->redirect($relativePath);
    }

    public function upload(): void
    {
        $relativePath = $this->sanitizeRelativePath($_POST['path'] ?? '');
        $currentDir = $this->resolvePath($relativePath);

        if (!is_dir($currentDir)) {
            $this->flash('지정한 폴더를 찾을 수 없습니다.', true);
            $this->logAction('upload', $relativePath, 'fail', 'dir_missing');
            $this->redirect('');
        }

        if (empty($_FILES['file']) || !isset($_FILES['file']['error']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->flash('파일 업로드에 실패했습니다.', true);
            $this->logAction('upload', $relativePath, 'fail', 'upload_error');
            $this->redirect($relativePath);
        }

        $fileName = $this->sanitizeSegment($_FILES['file']['name'] ?? '');

        if ($fileName === '') {
            $this->flash('파일 이름이 올바르지 않습니다.', true);
            $this->logAction('upload', $relativePath, 'fail', 'bad_name');
            $this->redirect($relativePath);
        }

        $destination = $currentDir . DIRECTORY_SEPARATOR . $fileName;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
            $this->flash('파일을 저장하지 못했습니다.', true);
            $this->logAction('upload', $this->joinRelative($relativePath, $fileName), 'fail', 'move_failed');
            $this->redirect($relativePath);
        }

        $this->logAction('upload', $this->joinRelative($relativePath, $fileName), 'success');
        $this->flash('파일을 업로드했습니다.');
        $this->redirect($relativePath);
    }

    public function uploadFolder(): void
    {
        $relativeBase = $this->sanitizeRelativePath($_POST['path'] ?? '');
        $currentDir = $this->resolvePath($relativeBase);

        if (!is_dir($currentDir)) {
            $this->jsonError('지정한 폴더를 찾을 수 없습니다.');
        }

        if (empty($_FILES['files']) || !isset($_FILES['files']['error'])) {
            $this->jsonError('업로드할 파일이 없습니다.');
        }

        $paths = $_POST['paths'] ?? [];
        $count = count($_FILES['files']['name']);
        $success = 0;

        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
                $this->logAction('upload_folder', $relativeBase, 'fail', 'upload_error');
                continue;
            }

            $relativeFile = $paths[$i] ?? $_FILES['files']['name'][$i] ?? '';
            $relativeFile = $this->sanitizeRelativePath($relativeFile);

            if ($relativeFile === '') {
                $this->logAction('upload_folder', $relativeBase, 'fail', 'empty_path');
                continue;
            }

            $fileName = basename($relativeFile);
            $subDir = trim(dirname($relativeFile), '.');
            $targetRelativeDir = $this->joinRelative($relativeBase, $subDir === '' ? '' : $subDir);
            $targetDir = $this->resolvePath($targetRelativeDir);

            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                $this->logAction('upload_folder', $targetRelativeDir, 'fail', 'mkdir_failed');
                continue;
            }

            $destination = $targetDir . DIRECTORY_SEPARATOR . $fileName;

            if (!move_uploaded_file($_FILES['files']['tmp_name'][$i], $destination)) {
                $this->logAction('upload_folder', $this->joinRelative($targetRelativeDir, $fileName), 'fail', 'move_failed');
                continue;
            }

            $success++;
            $this->logAction('upload_folder', $this->joinRelative($targetRelativeDir, $fileName), 'success');
        }

        if ($success === 0) {
            $this->jsonError('업로드에 실패했습니다.');
        }

        $this->jsonSuccess(sprintf('%d개 파일을 업로드했습니다.', $success));
    }

    public function download(): void
    {
        $relativePath = $this->sanitizeRelativePath($_GET['path'] ?? '');
        $target = $this->resolvePath($relativePath);

        if (!is_file($target)) {
            http_response_code(404);
            echo '파일을 찾을 수 없습니다.';
            $this->logAction('download', $relativePath, 'fail', 'not_found');
            return;
        }

        $filename = basename($target);
        $this->logAction('download', $relativePath, 'success');
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . filesize($target));
        readfile($target);
        exit;
    }

    public function logs(): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;
        $logs = $this->logModel->getLogs($perPage, $offset);
        $total = $this->logModel->countLogs();
        $totalPages = max(1, (int)ceil($total / $perPage));

        echo $this->twig->render('admin/webhard/logs.html.twig', [
            'page_title'   => '웹하드 로그',
            'logs'         => $logs,
            'page'         => $page,
            'total_pages'  => $totalPages,
            'total'        => $total,
            'per_page'     => $perPage,
        ]);
    }

    private function scanDirectory(string $dir, string $relativePath): array
    {
        $items = @scandir($dir) ?: [];
        $folders = [];
        $files = [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (strpos($item, '.') === 0) {
                continue;
            }

            $fullPath = $dir . DIRECTORY_SEPARATOR . $item;
            $itemRelative = $this->joinRelative($relativePath, $item);
            $meta = [
                'name'     => $item,
                'path'     => $itemRelative,
                'modified' => date('Y-m-d H:i', filemtime($fullPath)),
            ];

            if (is_dir($fullPath)) {
                $meta['type'] = 'folder';
                $meta['url'] = $this->buildAdminUrl($itemRelative);
                $folders[] = $meta;
            } else {
                $meta['type'] = 'file';
                $meta['size'] = filesize($fullPath);
                $meta['url'] = $this->buildPublicUrl($itemRelative);
                $files[] = $meta;
            }
        }

        usort($folders, fn($a, $b) => strcmp($a['name'], $b['name']));
        usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));

        return [$folders, $files];
    }

    private function buildBreadcrumbs(string $relativePath): array
    {
        $crumbs = [
            ['label' => '루트', 'path' => ''],
        ];

        if ($relativePath === '') {
            return $crumbs;
        }

        $segments = explode('/', $relativePath);
        $current = '';

        foreach ($segments as $segment) {
            $current = $this->joinRelative($current, $segment);
            $crumbs[] = ['label' => $segment, 'path' => $current];
        }

        return $crumbs;
    }

    private function getParentPath(string $relativePath): string
    {
        if ($relativePath === '') {
            return '';
        }

        $parts = explode('/', $relativePath);
        array_pop($parts);

        return implode('/', $parts);
    }

    private function sanitizeRelativePath(?string $path): string
    {
        $path = str_replace('\\', '/', $path ?? '');
        $path = trim($path, '/');
        $segments = array_filter(explode('/', $path), 'strlen');
        $safeSegments = [];

        foreach ($segments as $segment) {
            $segment = $this->sanitizeSegment($segment);

            if ($segment === '' || $segment === '..') {
                continue;
            }

            $safeSegments[] = $segment;
        }

        return implode('/', $safeSegments);
    }

    private function sanitizeSegment(string $segment): string
    {
        $segment = trim($segment);
        $segment = str_replace(['/', '\\', "\0"], '', $segment);
        return $segment;
    }

    private function joinRelative(string $base, string $segment): string
    {
        $base = trim($base, "/\\");
        $segment = trim($segment, "/\\");

        if ($base === '') {
            return $segment;
        }

        if ($segment === '') {
            return $base;
        }

        return $base . '/' . $segment;
    }

    private function resolvePath(string $relativePath): string
    {
        $relativePath = $this->sanitizeRelativePath($relativePath);
        $fullPath = rtrim($this->baseDir, DIRECTORY_SEPARATOR);

        if ($relativePath !== '') {
            $fullPath .= DIRECTORY_SEPARATOR . $relativePath;
        }

        $normalizedBase = $this->normalizePath($this->baseDir);
        $normalizedTarget = $this->normalizePath($fullPath);

        if (strpos($normalizedTarget, $normalizedBase) !== 0) {
            throw new RuntimeException('허용되지 않은 경로입니다.');
        }

        return $fullPath;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $parts = [];

        foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        $prefix = '';

        if (preg_match('#^[A-Za-z]:#', $path)) {
            $prefix = substr($path, 0, 2) . DIRECTORY_SEPARATOR;
        } elseif (strpos($path, DIRECTORY_SEPARATOR) === 0) {
            $prefix = DIRECTORY_SEPARATOR;
        }

        return $prefix . implode(DIRECTORY_SEPARATOR, $parts);
    }

    private function deleteDirectory(string $dir): void
    {
        $items = @scandir($dir) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    private function buildPublicUrl(string $relativePath): string
    {
        return '/admin/webhard/download?path=' . urlencode($relativePath);
    }

    private function buildAdminUrl(string $relativePath): string
    {
        return '/admin/webhard' . ($relativePath !== '' ? '?path=' . urlencode($relativePath) : '');
    }

    private function flash(string $message, bool $error = false): void
    {
        $_SESSION['flash'] = [
            'message' => $message,
            'error'   => $error,
        ];
    }

    private function redirect(string $relativePath): void
    {
        $location = '/admin/webhard';

        if ($relativePath !== '') {
            $location .= '?path=' . urlencode($relativePath);
        }

        header('Location: ' . $location);
        exit;
    }

    private function ensureBaseDir(): void
    {
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0775, true);
        }

    }

    private function logAction(string $action, string $relativePath, string $status, string $detail = ''): void
    {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $this->logModel->insertLog($action, $relativePath, $status, $detail, $adminId, $ip);
    }

    private function jsonError(string $message): void
    {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }

    private function jsonSuccess(string $message): void
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $message]);
        exit;
    }
}
