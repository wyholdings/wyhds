<?php

namespace App\Controllers;

use RuntimeException;
use Twig\Environment;
use App\Models\WebhardLogModel;
use App\Models\WebhardShareModel;

class WebhardController
{
    private Environment $twig;
    private string $baseDir;
    private WebhardLogModel $logModel;
    private WebhardShareModel $shareModel;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $configuredPath = $_ENV['WEBHARD_PATH'] ?? '';
        $this->baseDir = $configuredPath !== '' ? rtrim($configuredPath, "\\/") : realpath(__DIR__ . '/../../public') . DIRECTORY_SEPARATOR . 'webhard';
        $this->logModel = new WebhardLogModel();
        $this->shareModel = new WebhardShareModel();
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

        [$folders, $files, $totalSize] = $this->scanDirectory($currentDir, $relativePath);
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        echo $this->twig->render('admin/webhard/index.html.twig', [
            'page_title'   => '웹하드',
            'current_path' => $relativePath,
            'parent_path'  => $this->getParentPath($relativePath),
            'folders'      => $folders,
            'files'        => $files,
            'folder_count' => count($folders),
            'file_count'   => count($files),
            'total_size'   => $totalSize,
            'total_size_human' => $this->formatBytes($totalSize),
            'breadcrumbs'  => $this->buildBreadcrumbs($relativePath),
            'flash'        => $flash,
            'shares'       => $this->shareModel->getActiveByPath($relativePath),
            'share_base_url' => $this->getBaseUrl(),
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
        $this->processFolderUpload($relativeBase, 'upload_folder');
    }

    public function uploadChunk(): void
    {
        $relativePath = $this->sanitizeRelativePath($_POST['path'] ?? '');
        $this->processChunkUpload($relativePath, 'upload_chunk');
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

        $this->logAction('download', $relativePath, 'success');
        $this->sendFile($target, basename($target));
    }

    public function preview(): void
    {
        $relativePath = $this->sanitizeRelativePath($_GET['path'] ?? '');
        $target = $this->resolvePath($relativePath);

        if (!is_file($target) || !$this->isImageFile($target)) {
            http_response_code(404);
            echo '이미지를 찾을 수 없습니다.';
            $this->logAction('preview', $relativePath, 'fail', 'not_found');
            return;
        }

        $this->logAction('preview', $relativePath, 'success');
        header('Content-Type: ' . $this->getImageMimeType($target));
        header('Content-Length: ' . filesize($target));
        header('Content-Disposition: inline; filename="' . rawurlencode(basename($target)) . '"');
        readfile($target);
        exit;
    }

    public function downloadFolder(): void
    {
        $relativePath = $this->sanitizeRelativePath($_GET['path'] ?? '');
        $target = $this->resolvePath($relativePath);

        if ($relativePath === '' || !is_dir($target)) {
            http_response_code(404);
            echo '폴더를 찾을 수 없습니다.';
            $this->logAction('download_folder', $relativePath, 'fail', 'not_found');
            return;
        }

        $zipPath = $this->createZip([$target], basename($target));
        if ($zipPath === '') {
            http_response_code(500);
            echo 'ZIP 파일을 생성하지 못했습니다.';
            $this->logAction('download_folder', $relativePath, 'fail', 'zip_failed');
            return;
        }

        $this->logAction('download_folder', $relativePath, 'success');
        $this->sendFile($zipPath, basename($target) . '.zip', 'application/zip', true);
    }

    public function downloadSelected(): void
    {
        $relativePath = $this->sanitizeRelativePath($_POST['path'] ?? '');
        $currentDir = $this->resolvePath($relativePath);
        $selected = $_POST['selected_files'] ?? [];

        if (!is_dir($currentDir)) {
            $this->flash('지정한 폴더를 찾을 수 없습니다.', true);
            $this->logAction('download_selected', $relativePath, 'fail', 'dir_missing');
            $this->redirect('');
        }

        if (!is_array($selected) || count($selected) === 0) {
            $this->flash('다운로드할 파일을 선택해 주세요.', true);
            $this->logAction('download_selected', $relativePath, 'fail', 'empty');
            $this->redirect($relativePath);
        }

        $paths = [];
        foreach ($selected as $name) {
            $fileName = $this->sanitizeSegment((string)$name);
            if ($fileName === '') {
                continue;
            }

            $filePath = $this->resolvePath($this->joinRelative($relativePath, $fileName));
            if (is_file($filePath)) {
                $paths[] = $filePath;
            }
        }

        if (count($paths) === 0) {
            $this->flash('선택한 파일을 찾을 수 없습니다.', true);
            $this->logAction('download_selected', $relativePath, 'fail', 'not_found');
            $this->redirect($relativePath);
        }

        if (count($paths) === 1) {
            $this->logAction('download_selected', $this->joinRelative($relativePath, basename($paths[0])), 'success');
            $this->sendFile($paths[0], basename($paths[0]));
        }

        $zipName = ($relativePath !== '' ? basename($relativePath) : 'webhard') . '_selected';
        $zipPath = $this->createZip($paths, $zipName);
        if ($zipPath === '') {
            $this->flash('ZIP 파일을 생성하지 못했습니다.', true);
            $this->logAction('download_selected', $relativePath, 'fail', 'zip_failed');
            $this->redirect($relativePath);
        }

        $this->logAction('download_selected', $relativePath, 'success', count($paths) . ' files');
        $this->sendFile($zipPath, $zipName . '.zip', 'application/zip', true);
    }

    public function createShare(): void
    {
        $relativePath = $this->sanitizeRelativePath($_POST['path'] ?? '');
        $target = $this->resolvePath($relativePath);

        if (!is_dir($target)) {
            $this->flash('공유할 폴더를 찾을 수 없습니다.', true);
            $this->redirect($relativePath);
        }

        $days = max(0, (int)($_POST['expires_days'] ?? 7));
        $password = trim((string)($_POST['password'] ?? ''));
        $canUpload = isset($_POST['can_upload']) ? 1 : 0;
        $canDownload = isset($_POST['can_download']) ? 1 : 0;

        if ($canUpload === 0 && $canDownload === 0) {
            $this->flash('업로드 또는 다운로드 권한을 하나 이상 선택해 주세요.', true);
            $this->redirect($relativePath);
        }

        $token = bin2hex(random_bytes(24));
        $expiresAt = $days > 0 ? date('Y-m-d H:i:s', time() + ($days * 86400)) : null;

        $this->shareModel->create([
            'token' => $token,
            'base_path' => $relativePath,
            'can_upload' => $canUpload,
            'can_download' => $canDownload,
            'password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
            'expires_at' => $expiresAt,
            'created_by' => (int)($_SESSION['admin_id'] ?? 0),
        ]);

        $this->logAction('share_create', $relativePath, 'success', $token);
        $this->flash('공유 링크를 생성했습니다: ' . $this->getBaseUrl() . '/share/webhard/' . $token);
        $this->redirect($relativePath);
    }

    public function revokeShare(): void
    {
        $relativePath = $this->sanitizeRelativePath($_POST['path'] ?? '');
        $id = (int)($_POST['share_id'] ?? 0);

        if ($id > 0) {
            $this->shareModel->revoke($id);
            $this->logAction('share_revoke', $relativePath, 'success', (string)$id);
            $this->flash('공유 링크를 해제했습니다.');
        }

        $this->redirect($relativePath);
    }

    public function share(string $token): void
    {
        $share = $this->requireShare($token, false);
        if ($share === null) {
            return;
        }

        if (!$this->isShareAuthenticated($share)) {
            echo $this->twig->render('webhard/share_password.html.twig', [
                'token' => $token,
                'error' => $_SESSION['share_error'][$token] ?? '',
            ]);
            unset($_SESSION['share_error'][$token]);
            return;
        }

        $childPath = $this->sanitizeRelativePath($_GET['path'] ?? '');
        $relativePath = $this->joinRelative($share['base_path'], $childPath);
        $currentDir = $this->resolvePath($relativePath);

        if (!is_dir($currentDir)) {
            $childPath = '';
            $relativePath = $share['base_path'];
            $currentDir = $this->resolvePath($relativePath);
        }

        [$folders, $files, $totalSize] = $this->scanSharedDirectory($currentDir, $share, $childPath);
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        echo $this->twig->render('webhard/share.html.twig', [
            'share' => $share,
            'token' => $token,
            'current_path' => $childPath,
            'parent_path' => $this->getParentPath($childPath),
            'folders' => $folders,
            'files' => $files,
            'folder_count' => count($folders),
            'file_count' => count($files),
            'total_size_human' => $this->formatBytes($totalSize),
            'breadcrumbs' => $this->buildBreadcrumbs($childPath),
            'flash' => $flash,
        ]);
    }

    public function sharePassword(string $token): void
    {
        $share = $this->requireShare($token, false);
        if ($share === null) {
            return;
        }

        $password = (string)($_POST['password'] ?? '');
        if (!empty($share['password_hash']) && password_verify($password, $share['password_hash'])) {
            $_SESSION['webhard_share_auth'][$token] = true;
            header('Location: /share/webhard/' . urlencode($token));
            exit;
        }

        $_SESSION['share_error'][$token] = '비밀번호가 올바르지 않습니다.';
        header('Location: /share/webhard/' . urlencode($token));
        exit;
    }

    public function shareDownload(string $token): void
    {
        $share = $this->requireShare($token);
        if ($share === null || !$this->assertShareDownloadAllowed($share)) {
            return;
        }

        $childPath = $this->sanitizeRelativePath($_GET['path'] ?? '');
        $target = $this->resolvePath($this->joinRelative($share['base_path'], $childPath));

        if (!is_file($target)) {
            http_response_code(404);
            echo '파일을 찾을 수 없습니다.';
            return;
        }

        $this->logAction('share_download', $this->joinRelative($share['base_path'], $childPath), 'success', $token);
        $this->sendFile($target, basename($target));
    }

    public function sharePreview(string $token): void
    {
        $share = $this->requireShare($token);
        if ($share === null || !$this->assertShareDownloadAllowed($share)) {
            return;
        }

        $childPath = $this->sanitizeRelativePath($_GET['path'] ?? '');
        $target = $this->resolvePath($this->joinRelative($share['base_path'], $childPath));

        if (!is_file($target) || !$this->isImageFile($target)) {
            http_response_code(404);
            echo '이미지를 찾을 수 없습니다.';
            return;
        }

        header('Content-Type: ' . $this->getImageMimeType($target));
        header('Content-Length: ' . filesize($target));
        header('Content-Disposition: inline; filename="' . rawurlencode(basename($target)) . '"');
        readfile($target);
        exit;
    }

    public function shareDownloadFolder(string $token): void
    {
        $share = $this->requireShare($token);
        if ($share === null || !$this->assertShareDownloadAllowed($share)) {
            return;
        }

        $childPath = $this->sanitizeRelativePath($_GET['path'] ?? '');
        $target = $this->resolvePath($this->joinRelative($share['base_path'], $childPath));

        if (!is_dir($target)) {
            http_response_code(404);
            echo '폴더를 찾을 수 없습니다.';
            return;
        }

        $name = basename($target) ?: 'webhard';
        $zipPath = $this->createZip([$target], $name);
        if ($zipPath === '') {
            http_response_code(500);
            echo 'ZIP 파일을 생성하지 못했습니다.';
            return;
        }

        $this->logAction('share_download_folder', $this->joinRelative($share['base_path'], $childPath), 'success', $token);
        $this->sendFile($zipPath, $name . '.zip', 'application/zip', true);
    }

    public function shareUpload(string $token): void
    {
        $share = $this->requireShare($token);
        if ($share === null || !$this->assertShareUploadAllowed($share)) {
            return;
        }

        $childPath = $this->sanitizeRelativePath($_POST['path'] ?? '');
        $relativePath = $this->joinRelative($share['base_path'], $childPath);
        $currentDir = $this->resolvePath($relativePath);

        if (!is_dir($currentDir) || empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->flash('파일 업로드에 실패했습니다.', true);
            header('Location: /share/webhard/' . urlencode($token) . ($childPath !== '' ? '?path=' . urlencode($childPath) : ''));
            exit;
        }

        $fileName = $this->sanitizeSegment($_FILES['file']['name'] ?? '');
        if ($fileName === '') {
            $this->flash('파일 이름이 올바르지 않습니다.', true);
            header('Location: /share/webhard/' . urlencode($token) . ($childPath !== '' ? '?path=' . urlencode($childPath) : ''));
            exit;
        }

        move_uploaded_file($_FILES['file']['tmp_name'], $currentDir . DIRECTORY_SEPARATOR . $fileName);
        $this->logAction('share_upload', $this->joinRelative($relativePath, $fileName), 'success', $token);
        header('Location: /share/webhard/' . urlencode($token) . ($childPath !== '' ? '?path=' . urlencode($childPath) : ''));
        exit;
    }

    public function shareUploadFolder(string $token): void
    {
        $share = $this->requireShare($token);
        if ($share === null || !$this->assertShareUploadAllowed($share)) {
            return;
        }

        $childPath = $this->sanitizeRelativePath($_POST['path'] ?? '');
        $this->processFolderUpload($this->joinRelative($share['base_path'], $childPath), 'share_upload_folder');
    }

    public function shareUploadChunk(string $token): void
    {
        $share = $this->requireShare($token);
        if ($share === null || !$this->assertShareUploadAllowed($share)) {
            return;
        }

        $childPath = $this->sanitizeRelativePath($_POST['path'] ?? '');
        $this->processChunkUpload($this->joinRelative($share['base_path'], $childPath), 'share_upload_chunk');
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
        $totalSize = 0;

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
                $totalSize += $meta['size'];
                $meta['url'] = $this->buildPublicUrl($itemRelative);
                $meta['is_image'] = $this->isImageFile($fullPath);
                $meta['preview_url'] = $meta['is_image'] ? $this->buildPreviewUrl($itemRelative) : '';
                $files[] = $meta;
            }
        }

        usort($folders, fn($a, $b) => strcmp($a['name'], $b['name']));
        usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));

        return [$folders, $files, $totalSize];
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

    private function sanitizeUploadRelativePath(?string $path): string
    {
        // Browser-provided webkitRelativePath uses "/" separators.
        // Avoid treating "\" bytes as separators to prevent multibyte filename truncation on Windows.
        $path = trim((string)$path, '/');
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

    private function splitRelativeFile(string $relativeFile): array
    {
        $relativeFile = trim($relativeFile, '/');
        $pos = strrpos($relativeFile, '/');
        if ($pos === false) {
            return ['', $relativeFile];
        }

        $dir = substr($relativeFile, 0, $pos);
        $name = substr($relativeFile, $pos + 1);
        return [$dir, $name];
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

        if ($normalizedTarget !== $normalizedBase && strpos($normalizedTarget, $normalizedBase . DIRECTORY_SEPARATOR) !== 0) {
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

    private function buildPreviewUrl(string $relativePath): string
    {
        return '/admin/webhard/preview?path=' . urlencode($relativePath);
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

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int)floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        return sprintf('%.2f %s', $bytes / (1024 ** $power), $units[$power]);
    }

    private function processFolderUpload(string $relativeBase, string $action): void
    {
        $currentDir = $this->resolvePath($relativeBase);

        if (!is_dir($currentDir)) {
            $this->jsonError('지정한 폴더를 찾을 수 없습니다.');
        }

        $directories = $_POST['directories'] ?? [];
        $hasFiles = !empty($_FILES['files']) && isset($_FILES['files']['error']);
        $hasDirectories = is_array($directories) && count($directories) > 0;

        if (!$hasFiles && !$hasDirectories) {
            $this->jsonError('업로드할 파일이 없습니다.');
        }

        $paths = $_POST['paths'] ?? [];
        $count = $hasFiles ? count($_FILES['files']['name']) : 0;
        $requestedCount = is_array($paths) ? count($paths) : $count;
        $success = 0;
        $failed = 0;
        $createdDirs = 0;
        $processedDirs = 0;

        if (is_array($directories)) {
            foreach ($directories as $directory) {
                $relativeDir = $this->sanitizeUploadRelativePath((string)$directory);
                if ($relativeDir === '') {
                    continue;
                }

                $targetRelativeDir = $this->joinRelative($relativeBase, $relativeDir);
                $targetDir = $this->resolvePath($targetRelativeDir);
                $exists = is_dir($targetDir);

                if (!$exists && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                    $this->logAction($action, $targetRelativeDir, 'fail', 'mkdir_failed');
                    $failed++;
                    continue;
                }

                $this->logAction($action, $targetRelativeDir, 'success', 'directory');
                $processedDirs++;
                if (!$exists) {
                    $createdDirs++;
                }
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $errorCode = (int)($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($errorCode !== UPLOAD_ERR_OK) {
                $this->logAction($action, $relativeBase, 'fail', 'upload_error:' . $errorCode);
                $failed++;
                continue;
            }

            $relativeFile = $this->sanitizeUploadRelativePath($paths[$i] ?? $_FILES['files']['name'][$i] ?? '');

            if ($relativeFile === '') {
                $this->logAction($action, $relativeBase, 'fail', 'empty_path');
                $failed++;
                continue;
            }

            [$subDir, $fileName] = $this->splitRelativeFile($relativeFile);
            $fileName = $this->sanitizeSegment($fileName);
            if ($fileName === '') {
                $this->logAction($action, $relativeBase, 'fail', 'bad_name');
                $failed++;
                continue;
            }

            $targetRelativeDir = $this->joinRelative($relativeBase, $subDir === '' ? '' : $subDir);
            $targetDir = $this->resolvePath($targetRelativeDir);

            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                $this->logAction($action, $targetRelativeDir, 'fail', 'mkdir_failed');
                $failed++;
                continue;
            }

            $destination = $targetDir . DIRECTORY_SEPARATOR . $fileName;

            if (!move_uploaded_file($_FILES['files']['tmp_name'][$i], $destination)) {
                $this->logAction($action, $this->joinRelative($targetRelativeDir, $fileName), 'fail', 'move_failed');
                $failed++;
                continue;
            }

            $success++;
            $this->logAction($action, $this->joinRelative($targetRelativeDir, $fileName), 'success');
        }

        $extra = [
            'uploaded_files' => $success,
            'failed' => $failed,
            'created_dirs' => $createdDirs,
            'processed_dirs' => $processedDirs,
            'received_files' => $count,
            'requested_files' => $requestedCount,
        ];

        if ($success === 0 && $processedDirs === 0) {
            $this->jsonError('업로드에 실패했습니다.');
        }

        if ($success === 0 && $processedDirs > 0) {
            $this->jsonSuccess(sprintf('%d개 폴더 확인, %d개 신규 생성', $processedDirs, $createdDirs), $extra);
        }

        if ($requestedCount > $count) {
            $note = sprintf('요청 %d개 중 서버 수신 %d개, ', $requestedCount, $count);
            if ($failed > 0) {
                $this->jsonSuccess($note . sprintf('%d개 업로드, %d개 실패 (웹하드 로그 확인)', $success, $failed), $extra);
            }
            $this->jsonSuccess($note . sprintf('%d개 업로드 완료 (PHP 업로드 제한 확인 필요)', $success), $extra);
        }

        if ($failed > 0) {
            $this->jsonSuccess(sprintf('%d개 업로드, %d개 실패 (웹하드 로그 확인)', $success, $failed), $extra);
        }

        $this->jsonSuccess(sprintf('%d개 파일을 업로드했습니다.', $success), $extra);
    }

    private function processChunkUpload(string $relativePath, string $action): void
    {
        $currentDir = $this->resolvePath($relativePath);

        if (!is_dir($currentDir)) {
            $this->jsonError('지정한 폴더를 찾을 수 없습니다.');
        }

        $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['upload_id'] ?? ''));
        $fileName = $this->sanitizeSegment((string)($_POST['file_name'] ?? ''));
        $relativeFilePath = $this->sanitizeUploadRelativePath((string)($_POST['relative_path'] ?? $fileName));
        $chunkIndex = (int)($_POST['chunk_index'] ?? -1);
        $totalChunks = (int)($_POST['total_chunks'] ?? 0);

        if ($uploadId === '' || $fileName === '' || $relativeFilePath === '' || $chunkIndex < 0 || $totalChunks < 1) {
            $this->jsonError('업로드 정보가 올바르지 않습니다.');
        }

        if (empty($_FILES['chunk']) || !isset($_FILES['chunk']['error']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
            $this->jsonError('파일 조각 업로드에 실패했습니다.');
        }

        $chunkDir = $this->getChunkBaseDir() . DIRECTORY_SEPARATOR . $uploadId;
        if (!is_dir($chunkDir) && !mkdir($chunkDir, 0775, true) && !is_dir($chunkDir)) {
            $this->jsonError('임시 업로드 폴더를 만들지 못했습니다.');
        }

        $chunkPath = $chunkDir . DIRECTORY_SEPARATOR . sprintf('%08d.part', $chunkIndex);
        if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkPath)) {
            $this->jsonError('파일 조각을 저장하지 못했습니다.');
        }

        if ($chunkIndex + 1 < $totalChunks) {
            $this->jsonSuccess('파일 조각을 업로드했습니다.', [
                'completed' => false,
                'chunk_index' => $chunkIndex,
                'total_chunks' => $totalChunks,
            ]);
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            if (!is_file($chunkDir . DIRECTORY_SEPARATOR . sprintf('%08d.part', $i))) {
                $this->jsonSuccess('파일 조각을 업로드했습니다.', [
                    'completed' => false,
                    'chunk_index' => $chunkIndex,
                    'total_chunks' => $totalChunks,
                ]);
            }
        }

        [$subDir, $finalName] = $this->splitRelativeFile($relativeFilePath);
        $finalName = $this->sanitizeSegment($finalName);
        if ($finalName === '') {
            $this->deleteDirectory($chunkDir);
            $this->jsonError('파일 이름이 올바르지 않습니다.');
        }

        $targetRelativeDir = $this->joinRelative($relativePath, $subDir);
        $targetDir = $this->resolvePath($targetRelativeDir);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $this->deleteDirectory($chunkDir);
            $this->jsonError('대상 폴더를 만들지 못했습니다.');
        }

        $destination = $targetDir . DIRECTORY_SEPARATOR . $finalName;
        $out = @fopen($destination, 'wb');
        if ($out === false) {
            $this->deleteDirectory($chunkDir);
            $this->jsonError('최종 파일을 만들지 못했습니다.');
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $partPath = $chunkDir . DIRECTORY_SEPARATOR . sprintf('%08d.part', $i);
            $in = @fopen($partPath, 'rb');
            if ($in === false) {
                fclose($out);
                @unlink($destination);
                $this->deleteDirectory($chunkDir);
                $this->jsonError('파일 조각을 읽지 못했습니다.');
            }

            stream_copy_to_stream($in, $out);
            fclose($in);
        }

        fclose($out);
        $this->deleteDirectory($chunkDir);
        $this->logAction($action, $this->joinRelative($targetRelativeDir, $finalName), 'success');

        $this->jsonSuccess('파일 업로드가 완료되었습니다.', [
            'completed' => true,
            'uploaded_files' => 1,
            'file_name' => $finalName,
        ]);
    }

    private function getChunkBaseDir(): string
    {
        $dir = realpath(__DIR__ . '/../../storage');
        if ($dir === false) {
            $dir = __DIR__ . '/../../storage';
        }

        $dir .= DIRECTORY_SEPARATOR . 'webhard_chunks';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private function scanSharedDirectory(string $dir, array $share, string $childPath): array
    {
        [$folders, $files, $totalSize] = $this->scanDirectory($dir, $this->joinRelative($share['base_path'], $childPath));
        $token = $share['token'];

        foreach ($folders as &$folder) {
            $folderChildPath = $this->joinRelative($childPath, $folder['name']);
            $folder['path'] = $folderChildPath;
            $folder['url'] = '/share/webhard/' . urlencode($token) . '?path=' . urlencode($folderChildPath);
            $folder['download_url'] = '/share/webhard/' . urlencode($token) . '/download-folder?path=' . urlencode($folderChildPath);
        }
        unset($folder);

        foreach ($files as &$file) {
            $fileChildPath = $this->joinRelative($childPath, $file['name']);
            $file['path'] = $fileChildPath;
            $file['url'] = '/share/webhard/' . urlencode($token) . '/download?path=' . urlencode($fileChildPath);
            $file['preview_url'] = $file['is_image'] ? '/share/webhard/' . urlencode($token) . '/preview?path=' . urlencode($fileChildPath) : '';
        }
        unset($file);

        return [$folders, $files, $totalSize];
    }

    private function requireShare(string $token, bool $requireAuth = true): ?array
    {
        $share = $this->shareModel->findValidByToken($token);
        if ($share === null) {
            http_response_code(404);
            echo '공유 링크를 찾을 수 없거나 만료되었습니다.';
            return null;
        }

        if ($requireAuth && !$this->isShareAuthenticated($share)) {
            http_response_code(403);
            echo '비밀번호 인증이 필요합니다.';
            return null;
        }

        return $share;
    }

    private function isShareAuthenticated(array $share): bool
    {
        if (empty($share['password_hash'])) {
            return true;
        }

        return !empty($_SESSION['webhard_share_auth'][$share['token']]);
    }

    private function assertShareDownloadAllowed(array $share): bool
    {
        if ((int)$share['can_download'] === 1) {
            return true;
        }

        http_response_code(403);
        echo '다운로드 권한이 없습니다.';
        return false;
    }

    private function assertShareUploadAllowed(array $share): bool
    {
        if ((int)$share['can_upload'] === 1) {
            return true;
        }

        http_response_code(403);
        echo '업로드 권한이 없습니다.';
        return false;
    }

    private function getBaseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host;
    }

    private function isImageFile(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
    }

    private function getImageMimeType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $types = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
        ];

        return $types[$extension] ?? 'application/octet-stream';
    }

    private function createZip(array $paths, string $rootName): string
    {
        if (!class_exists('ZipArchive')) {
            return '';
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'webhard_');
        if ($tmpPath === false) {
            return '';
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpPath, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpPath);
            return '';
        }

        $rootName = $this->sanitizeSegment($rootName) ?: 'webhard';
        foreach ($paths as $path) {
            if (is_file($path)) {
                $zip->addFile($path, basename($path));
                continue;
            }

            if (is_dir($path)) {
                $zip->addEmptyDir($rootName);
                $this->addDirectoryToZip($zip, $path, $rootName);
            }
        }

        $zip->close();
        return $tmpPath;
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $dir, string $zipRoot): void
    {
        $items = @scandir($dir) ?: [];
        $zipRoot = trim(str_replace('\\', '/', $zipRoot), '/');

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || strpos($item, '.') === 0) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            $zipPath = $zipRoot . '/' . $item;

            if (is_dir($path)) {
                $zip->addEmptyDir($zipPath);
                $this->addDirectoryToZip($zip, $path, $zipPath);
            } elseif (is_file($path)) {
                $zip->addFile($path, $zipPath);
            }
        }
    }

    private function sendFile(string $path, string $downloadName, string $contentType = 'application/octet-stream', bool $deleteAfter = false): void
    {
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        header('Content-Length: ' . filesize($path));
        readfile($path);

        if ($deleteAfter) {
            @unlink($path);
        }

        exit;
    }

    private function jsonError(string $message): void
    {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }

    private function jsonSuccess(string $message, array $extra = []): void
    {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => true, 'message' => $message], $extra));
        exit;
    }
}
