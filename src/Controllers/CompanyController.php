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
        ]);
    }

    //업체 등록
    public function add()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $model = new CompanyModel();

            $data = [
                'name'             => $_POST['name'] ?? null,
                'business_number'  => $_POST['business_number'] ?? null,
                'type'             => $_POST['type'] ?? 'client',
                'contract_start'   => $_POST['contract_start'] ?? null,
                'contract_end'     => $_POST['contract_end'] ?? null,
                'manager'          => $_POST['manager'] ?? null,
                'phone'            => $_POST['phone'] ?? null,
                'email'            => $_POST['email'] ?? null,
                'address'          => $_POST['address'] ?? null,
                'status'           => $_POST['status'] ?? 'active',
                'memo'             => $_POST['memo'] ?? null,
            ];

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
            $data = $_POST;
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
        ];
    }

    private function daysUntil(?string $date): ?int
    {
        if (!$date) {
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
