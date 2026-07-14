<?php

namespace App\Controllers;

use App\Models\PortfolioModel;
use Throwable;
use Twig\Environment;

class HomeController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): void
    {
        $contactToken = $this->ensureContactFormToken();
        $template = (($_GET['mode'] ?? '') === 'dev') ? 'index_dev.html.twig' : 'index.html.twig';
        echo $this->twig->render($template, [
            'contact_form_token' => $contactToken,
        ]);
    }

    public function portfolio(): void
    {
        $contactToken = $this->ensureContactFormToken();
        echo $this->twig->render('portfolio/portfolio.html.twig', [
            'contact_form_token' => $contactToken,
            'portfolios' => $this->getPortfolios(),
        ]);
    }

    function portfolioView($id): void
    {
        $contactToken = $this->ensureContactFormToken();
        $portfolio = $this->getPortfolio((int)$id);
        $title = $portfolio ? ($portfolio['title'] . ' 구축 사례 | 우용디앤에스') : '구축 사례 | 우용디앤에스';
        $description = $portfolio
            ? trim((string)($portfolio['subtitle'] ?: $portfolio['description']))
            : '우용디앤에스의 웹사이트·자동화 구축 사례를 확인해 보세요.';
        echo $this->twig->render('portfolio/portfolio_view.html.twig', [
            'contact_form_token' => $contactToken,
            'project_id' => $id,
            'portfolio' => $portfolio,
            'title' => $title,
            'description' => $description,
            'canonical_url' => '/portfolio/' . (int)$id,
        ]);
    }

    public function services(): void
    {
        $contactToken = $this->ensureContactFormToken();
        echo $this->twig->render('services/services.html.twig', [
            'title' => '홈페이지·행사 시스템 패키지 | 서비스·가격 안내 - 우용디앤에스',
            'description' => '스타트 홈페이지, 기업·학회 홈페이지, 행사·운영 시스템 패키지의 기본 범위와 예상 비용을 확인하고 맞춤 견적을 문의하세요.',
            'keywords' => '홈페이지 제작 패키지, 기업 홈페이지 제작 비용, 학회 홈페이지 제작, 행사 등록 시스템, 홈페이지 견적',
            'canonical_url' => 'https://wyhds.com/services',
            'contact_form_token' => $contactToken,
        ]);
    }

    public function automationDiagnosis(): void
    {
        $contactToken = $this->ensureContactFormToken();
        echo $this->twig->render('automation_diagnosis.html.twig', [
            'title' => '업무 자동화 진단 | 반복 업무·CSV·PDF·견적 관리 자동화 - 우용디앤에스',
            'description' => '반복 업무와 주간 소요 시간을 선택해 업무 자동화 우선순위, 권장 구축 범위, 상담 요청 요약을 확인하세요.',
            'keywords' => '업무 자동화 진단, 엑셀 자동화, CSV 자동화, PDF 자동화, 업무 시스템 구축, 관리자 페이지 개발',
            'canonical_url' => 'https://wyhds.com/automation-diagnosis',
            'contact_form_token' => $contactToken,
        ]);
    }

    public function contact(): void
    {
        $contactToken = $this->ensureContactFormToken();
        $inquiryType = (string)($_GET['inquiry'] ?? '');
        $inquiryType = in_array($inquiryType, ['pro', 'business'], true) ? $inquiryType : '';
        $toolSlug = preg_replace('/[^a-z0-9-]/', '', (string)($_GET['tool'] ?? ''));
        $packages = [
            'starter-site' => '스타트 홈페이지 패키지',
            'business-site' => '기업·학회 홈페이지 패키지',
            'event-system' => '행사·운영 시스템 패키지',
        ];
        $packageKey = preg_replace('/[^a-z-]/', '', (string)($_GET['package'] ?? ''));
        echo $this->twig->render('contact.html.twig', [
            'contact_form_token' => $contactToken,
            'contact_inquiry_type' => $inquiryType,
            'contact_tool_slug' => $toolSlug,
            'contact_package' => $packages[$packageKey] ?? '',
        ]);
    }

    public function about(): void
    {
        $contactToken = $this->ensureContactFormToken();
        echo $this->twig->render('about/about.html.twig', [
            'contact_form_token' => $contactToken,
        ]);
    }

    public function demo(): void
    {
        echo $this->twig->render('demo/base.html.twig', [
            'site_title' => '학회 Base Demo',
            'event_name' => '2026 한국학회 연례학술대회',
            'event_date' => '2026.10.22 - 2026.10.24',
            'event_place' => '서울 COEX',
        ]);
    }

    private function ensureContactFormToken(): string
    {
        if (empty($_SESSION['contact_form_token'])) {
            $_SESSION['contact_form_token'] = bin2hex(random_bytes(16));
        }

        $_SESSION['contact_form_issued_at'] = time();

        return $_SESSION['contact_form_token'];
    }

    private function getPortfolios(): array
    {
        try {
            return (new PortfolioModel())->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    private function getPortfolio(int $id): ?array
    {
        try {
            return (new PortfolioModel())->find($id);
        } catch (Throwable $e) {
            return null;
        }
    }
}

?>
