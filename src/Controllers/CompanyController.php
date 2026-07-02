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
        $companies = $companyModel->getAll($filters);

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
        ]);
    }

    public function export(): void
    {
        $companyModel = new CompanyModel();
        $companies = $companyModel->getAll($this->filtersFromQuery($_GET));

        $this->sendCsv('companies_' . date('Ymd_His') . '.csv', [
            ['ID', '업체명', '사업자등록번호', '타입', '계약시작일', '계약종료일', '담당자', '전화번호', '이메일', '주소', '상태', '메모', '등록일', '수정일'],
        ], array_map(static function (array $company): array {
            return [
                $company['id'] ?? '',
                $company['name'] ?? '',
                $company['business_number'] ?? '',
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

            if ($errors) {
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
            $data = $this->normalizeData($_POST);
            $errors = $this->validateData($data);

            if ($errors) {
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

    private function normalizeData(array $input): array
    {
        return [
            'name' => trim((string)($input['name'] ?? '')),
            'business_number' => trim((string)($input['business_number'] ?? '')),
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
