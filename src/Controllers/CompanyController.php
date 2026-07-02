<?php

namespace App\Controllers;

use Twig\Environment;
use App\Models\CompanyModel;
use App\Models\ProjectModel;

class CompanyController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    //업체 목록
    public function list()
    {
        $companyModel = new CompanyModel();
        $filters = $this->filtersFromQuery($_GET);
        $allCompanies = $companyModel->getAll($filters);
        $pagination = $this->paginate($allCompanies, (int)($_GET['page'] ?? 1));
        $companies = $pagination['items'];

        foreach ($companies as &$company) {
            $company['contract_end_days'] = $this->daysUntil($company['contract_end'] ?? null);
        }
        unset($company);

        echo $this->twig->render('admin/company/list.html.twig', [
            'companies' => $companies,
            'filters' => $filters,
            'summary' => $companyModel->getSummary(),
            'managers' => $companyModel->getManagers(),
            'export_query' => $this->queryString($filters),
            'pagination' => $pagination,
        ]);
    }

    public function export(): void
    {
        $companyModel = new CompanyModel();
        $companies = $companyModel->getAll($this->filtersFromQuery($_GET));

        $this->sendCsv('companies_' . date('Ymd_His') . '.csv', [
            ['ID', '업체명', '사업자등록번호', '사업자등록증', '타입', '계약시작일', '계약종료일', '담당자', '전화번호', '이메일', '주소', '상태', '메모', '등록일', '수정일'],
        ], array_map(static function (array $company): array {
            return [
                $company['id'] ?? '',
                $company['name'] ?? '',
                $company['business_number'] ?? '',
                $company['business_license_file'] ?? '',
                $company['type'] ?? '',
                $company['contract_start'] ?? '',
                $company['contract_end'] ?? '',
                $company['manager'] ?? '',
                $company['phone'] ?? '',
                $company['email'] ?? '',
                $company['address'] ?? '',
                $company['status'] ?? '',
                $company['memo'] ?? '',
                $company['created_at'] ?? '',
                $company['updated_at'] ?? '',
            ];
        }, $companies));
    }

    //업체 등록
    public function add()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requireValidCsrf();
            $model = new CompanyModel();
            $data = $this->normalizeData($_POST);
            $errors = $this->validateData($data);
            [$uploadedPath, $uploadError] = $this->handleLicenseUpload();
            if ($uploadError) {
                $errors[] = $uploadError;
            }
            $data['business_license_file'] = $uploadedPath;

            if ($errors) {
                $this->deleteUploadedFile($uploadedPath);
                http_response_code(422);
                echo $this->twig->render('admin/company/add.html.twig', [
                    'company' => $data,
                    'errors' => $errors,
                ]);
                return;
            }

            $model->insert($data);
            header('Location: /admin/company/list');
            exit;
        }

        echo $this->twig->render('admin/company/add.html.twig');
    }

    //업체 정보 보기
    public function view($companyId)
    {
        // 모델 인스턴스 생성
        $companyModel = new CompanyModel();

        // 업체 정보 가져오기
        $company = $companyModel->getCompany($companyId);

        // 업체 정보가 없다면 404 페이지로 리디렉션
        if (!$company) {
            // 예: 오류 처리 (404 페이지로 리디렉션)
            header('Location: /404');
            exit;
        }

        $projectModel = new ProjectModel();
        $projects = $projectModel->getByCompanyId((int)$companyId);

        foreach ($projects as &$project) {
            $project['url_expiry_days'] = $this->daysUntil($project['url_expiry_date'] ?? null);
            $project['ssl_expiry_days'] = $this->daysUntil($project['ssl_expiry_date'] ?? null);
            $project['hosting_expiry_days'] = $this->daysUntil($project['hosting_expiry_date'] ?? null);
            $project['maintenance_expiry_days'] = $this->daysUntil($project['maintenance_expiry_date'] ?? null);
        }
        unset($project);

        // 업체 정보를 템플릿에 전달
        echo $this->twig->render('admin/company/view.html.twig', [
            'company' => $company,
            'projects' => $projects,
        ]);
    }

    // 업체 수정 화면 + 수정 처리
    public function edit($id)
    {
        $model = new CompanyModel();

        // POST 요청으로 데이터가 전달되면 업데이트 처리
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requireValidCsrf();
            $company = $model->getCompany($id);
            if (!$company) {
                http_response_code(404);
                echo $this->twig->render('errors/404.html.twig');
                exit;
            }

            $data = $this->normalizeData($_POST, $company['business_license_file'] ?? null);
            $errors = $this->validateData($data);
            [$uploadedPath, $uploadError] = $this->handleLicenseUpload();
            if ($uploadError) {
                $errors[] = $uploadError;
            } elseif ($uploadedPath) {
                $data['business_license_file'] = $uploadedPath;
            }

            if ($errors) {
                $this->deleteUploadedFile($uploadedPath);
                $data['id'] = $id;
                http_response_code(422);
                echo $this->twig->render('admin/company/add.html.twig', [
                    'company' => $data,
                    'errors' => $errors,
                ]);
                return;
            }

            $model->updateCompany($id, $data); // 데이터 업데이트
            header("Location: /admin/company/{$id}/view"); // 수정 후 업체 정보 보기 페이지로 리디렉션
            exit;
        }

        // GET 요청으로 데이터 조회
        $company = $model->getCompany($id); // 업체 정보 가져오기
        if (!$company) {
            // 회사 정보가 없으면 404 처리
            http_response_code(404);
            echo $this->twig->render('errors/404.html.twig');
            exit;
        }

        // 폼에 기존 데이터를 채워서 출력
        echo $this->twig->render('admin/company/add.html.twig', ['company' => $company]);
    }

    // 업체 삭제 처리
    public function delete($id)
    {
        $this->requireValidCsrf();
        $model = new CompanyModel();
        $model->deleteCompany($id);
        header("Location: /admin/company/list");
        exit;
    }

    private function filtersFromQuery(array $query): array
    {
        return [
            'q' => trim((string)($query['q'] ?? '')),
            'status' => trim((string)($query['status'] ?? '')),
            'type' => trim((string)($query['type'] ?? '')),
            'contract' => trim((string)($query['contract'] ?? '')),
            'manager' => trim((string)($query['manager'] ?? '')),
        ];
    }

    private function queryString(array $filters): string
    {
        return http_build_query(array_filter($filters, static function ($value): bool {
            return $value !== '' && $value !== null && $value !== 0;
        }));
    }

    private function sendCsv(string $filename, array $headerRows, array $rows): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");

        foreach ($headerRows as $header) {
            fputcsv($output, $header);
        }

        foreach ($rows as $row) {
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    private function paginate(array $items, int $page, int $perPage = 10): array
    {
        $total = count($items);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));

        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'query' => $this->queryString($this->filtersFromQuery($_GET)),
        ];
    }

    private function handleLicenseUpload(): array
    {
        if (!isset($_FILES['business_license_file']) || ($_FILES['business_license_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }

        $file = $_FILES['business_license_file'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return [null, '사업자등록증 파일 업로드에 실패했습니다.'];
        }

        if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
            return [null, '사업자등록증 파일은 10MB 이하만 업로드할 수 있습니다.'];
        }

        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            return [null, '사업자등록증 파일은 PDF, JPG, PNG, WEBP만 가능합니다.'];
        }

        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/company_licenses';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $targetPath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
            return [null, '사업자등록증 파일 저장에 실패했습니다.'];
        }

        return ['/uploads/company_licenses/' . $filename, null];
    }

    private function deleteUploadedFile(?string $relativePath): void
    {
        if (!$relativePath) {
            return;
        }

        $absolutePath = dirname(__DIR__, 2) . '/public' . $relativePath;
        if (is_file($absolutePath)) {
            unlink($absolutePath);
        }
    }

    private function normalizeData(array $input, ?string $currentLicenseFile = null): array
    {
        return [
            'name' => trim((string)($input['name'] ?? '')),
            'business_number' => trim((string)($input['business_number'] ?? '')),
            'business_license_file' => $currentLicenseFile,
            'type' => $input['type'] ?? 'client',
            'contract_start' => $this->nullableDate($input['contract_start'] ?? null),
            'contract_end' => $this->nullableDate($input['contract_end'] ?? null),
            'manager' => trim((string)($input['manager'] ?? '')),
            'phone' => trim((string)($input['phone'] ?? '')),
            'email' => trim((string)($input['email'] ?? '')),
            'address' => trim((string)($input['address'] ?? '')),
            'status' => $input['status'] ?? 'active',
            'memo' => trim((string)($input['memo'] ?? '')),
        ];
    }

    private function validateData(array $data): array
    {
        $errors = [];

        if ($data['name'] === '') {
            $errors[] = '업체명을 입력해주세요.';
        }

        if (!in_array($data['type'], ['client', 'partner', 'internal'], true)) {
            $errors[] = '업체 타입을 확인해주세요.';
        }

        if (!in_array($data['status'], ['active', 'inactive', 'hold'], true)) {
            $errors[] = '상태값을 확인해주세요.';
        }

        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = '이메일 형식을 확인해주세요.';
        }

        if ($data['contract_start'] && $data['contract_end'] && $data['contract_start'] > $data['contract_end']) {
            $errors[] = '계약 종료일은 계약 시작일 이후여야 합니다.';
        }

        return $errors;
    }

    private function requireValidCsrf(): void
    {
        if (!\verify_csrf_token($_POST['csrf_token'] ?? '')) {
            http_response_code(400);
            echo 'Invalid CSRF token.';
            exit;
        }
    }

    private function nullableDate($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '0000-00-00') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function daysUntil(?string $date): ?int
    {
        if (!$date || $date === '0000-00-00') {
            return null;
        }

        try {
            $target = new \DateTimeImmutable($date);
        } catch (\Exception $e) {
            return null;
        }

        $today = new \DateTimeImmutable('today');
        $diff = $today->diff($target);
        return (int)$diff->format('%r%a');
    }
}
