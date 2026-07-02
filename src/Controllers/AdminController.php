<?php

namespace App\Controllers;

use Twig\Environment;
use App\Models\AdminModel;
use App\Models\CompanyModel;
use App\Models\InquiryModel;
use App\Models\ProjectModel;
use App\Models\ToolUsageModel;
use App\Models\ToolRelatedClickModel;
use App\Models\VisitorLogModel;
use App\Services\ToolRegistry;
use Throwable;

class AdminController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    // 대시보드
    public function dashboard(): void
    {
        $companyModel = new CompanyModel();
        $projectModel = new ProjectModel();
        $inquiryModel = new InquiryModel();
        $expiringProjects = $projectModel->getExpiringSoon(8, 30);
        $expiredProjects = $projectModel->getExpiredItems(8);
        $holdProjects = $projectModel->getByStatus('hold', 8);
        $recentProjects = $projectModel->getRecent(6);
        $contractDueCompanies = $companyModel->getContractDueSoon(8, 30);
        $expiredCompanies = $companyModel->getExpiredContracts(8);
        $holdCompanies = $companyModel->getByStatus('hold', 8);
        $recentCompanies = $companyModel->getRecent(6);

        foreach ($expiringProjects as &$project) {
            $project['nearest_expiry_days'] = $this->daysUntil($project['nearest_expiry_date'] ?? null);
        }
        unset($project);

        foreach ($expiredProjects as &$project) {
            $project['nearest_expiry_days'] = $this->daysUntil($project['nearest_expiry_date'] ?? null);
        }
        unset($project);

        foreach ($contractDueCompanies as &$company) {
            $company['contract_end_days'] = $this->daysUntil($company['contract_end'] ?? null);
        }
        unset($company);

        foreach ($expiredCompanies as &$company) {
            $company['contract_end_days'] = $this->daysUntil($company['contract_end'] ?? null);
        }
        unset($company);

        $companySummary = $companyModel->getSummary();
        $projectSummary = $projectModel->getSummary();
        $inquirySummary = $inquiryModel->getSummary();

        echo $this->twig->render('admin/dashboard.html.twig', [
            'page_title' => '관리자 대시보드',
            'company_summary' => $companySummary,
            'project_summary' => $projectSummary,
            'inquiry_summary' => $inquirySummary,
            'work_summary' => [
                'project_attention' => $projectSummary['expired'] + $projectSummary['due_30'] + $projectSummary['hold'],
                'company_attention' => $companySummary['expired'] + $companySummary['due_30'] + $companySummary['hold'],
                'new_inquiries' => $inquirySummary['recent_7'],
            ],
            'expiring_projects' => $expiringProjects,
            'expired_projects' => $expiredProjects,
            'hold_projects' => $holdProjects,
            'recent_projects' => $recentProjects,
            'contract_due_companies' => $contractDueCompanies,
            'expired_companies' => $expiredCompanies,
            'hold_companies' => $holdCompanies,
            'recent_companies' => $recentCompanies,
            'recent_inquiries' => $inquiryModel->getRecent(6),
        ]);
    }

    public function toolAnalytics(): void
    {
        $days = max(1, min(365, (int)($_GET['days'] ?? 30)));
        $registry = new ToolRegistry();
        $toolsBySlug = [];
        foreach ($registry->active() as $tool) {
            $toolsBySlug[$tool['slug']] = $tool;
        }

        $data = [
            'summary' => ['visits' => 0, 'sessions' => 0, 'avg_duration' => 0, 'search_visits' => 0],
            'total_tool_views' => 0,
            'top_tools' => [],
            'landing_pages' => [],
            'search_sources' => [],
            'daily_visits' => [],
            'daily_views' => [],
            'related_clicks' => [],
            'related_daily_clicks' => [],
            'total_related_clicks' => 0,
            'error' => null,
        ];

        try {
            $usage = new ToolUsageModel();
            $visitors = new VisitorLogModel();
            $relatedClicks = new ToolRelatedClickModel();

            $data['summary'] = $visitors->getToolTrafficSummary($days);
            $data['total_tool_views'] = $usage->getTotalViews($days);
            $data['top_tools'] = array_map(static function (array $row) use ($toolsBySlug): array {
                $slug = (string)$row['tool_slug'];
                $tool = $toolsBySlug[$slug] ?? null;
                return [
                    'slug' => $slug,
                    'name' => $tool['name'] ?? $slug,
                    'category' => $tool['category'] ?? '',
                    'url' => $tool['url'] ?? '/tools/' . $slug,
                    'views' => (int)$row['views'],
                    'last_view_date' => $row['last_view_date'] ?? '',
                ];
            }, $usage->getTopTools(20, $days));

            $data['landing_pages'] = array_map(static function (array $row) use ($toolsBySlug): array {
                $path = (string)$row['path'];
                $slug = basename($path);
                $tool = $toolsBySlug[$slug] ?? null;
                return [
                    'path' => $path,
                    'slug' => $slug,
                    'name' => $tool['name'] ?? $path,
                    'category' => $tool['category'] ?? '',
                    'visits' => (int)$row['visits'],
                    'search_visits' => (int)$row['search_visits'],
                    'avg_duration' => (int)$row['avg_duration'],
                    'last_visited_at' => $row['last_visited_at'] ?? '',
                ];
            }, $visitors->getTopToolLandingPages(20, $days));

            $data['search_sources'] = $visitors->getSearchRefererSummary(10, $days);
            $data['daily_visits'] = $visitors->getToolDailyVisits(14);
            $data['daily_views'] = $usage->getDailyViews(14);
            $data['total_related_clicks'] = $relatedClicks->getTotalClicks($days);
            $data['related_clicks'] = array_map(static function (array $row) use ($toolsBySlug): array {
                $sourceSlug = (string)$row['source_tool_slug'];
                $targetSlug = (string)$row['target_tool_slug'];
                return [
                    'source_slug' => $sourceSlug,
                    'target_slug' => $targetSlug,
                    'source_name' => $toolsBySlug[$sourceSlug]['name'] ?? $sourceSlug,
                    'target_name' => $toolsBySlug[$targetSlug]['name'] ?? $targetSlug,
                    'source_url' => $toolsBySlug[$sourceSlug]['url'] ?? '/tools/' . $sourceSlug,
                    'target_url' => $toolsBySlug[$targetSlug]['url'] ?? '/tools/' . $targetSlug,
                    'clicks' => (int)$row['clicks'],
                    'sessions' => (int)$row['sessions'],
                    'last_clicked_at' => $row['last_clicked_at'] ?? '',
                ];
            }, $relatedClicks->getSummary(20, $days));
            $data['related_daily_clicks'] = $relatedClicks->getDailyClicks(14);
        } catch (Throwable $e) {
            $data['error'] = $e->getMessage();
        }

        echo $this->twig->render('admin/tools/analytics.html.twig', array_merge($data, [
            'page_title' => 'WY Tools 통계',
            'days' => $days,
        ]));
    }

    // 로그인 폼
    public function loginForm(): void
    {
        echo $this->twig->render('admin/auth/login.html.twig');
    }

    //로그인
    public function login() {
        $username = str_replace(' ', '', $_POST['username'] ?? '');
        $password = str_replace(' ', '', $_POST['password'] ?? '');

        $adminModel = new AdminModel();
        $admin = $adminModel->getByUsername($username);
        
        //로그인 성공 시
        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            echo json_encode(['success' => true, 'message' => '로그인 성공']);
            exit;
        }

        //로그인 실패
        echo json_encode(['success' => false, 'message' => '아이디 또는 비밀번호가 틀렸습니다.']);
        exit;
    }

    //로그아웃
    public function logout() {
        session_destroy();
        header('Location: /admin/login');
        exit;
    }

    private function daysUntil(?string $date): ?int
    {
        if (!$date || $date === '0000-00-00') {
            return null;
        }

        try {
            $target = new \DateTimeImmutable($date);
        } catch (Throwable $e) {
            return null;
        }

        $today = new \DateTimeImmutable('today');
        $diff = $today->diff($target);
        return (int)$diff->format('%r%a');
    }
}
