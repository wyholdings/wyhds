<?php

namespace App\Controllers;

use App\Models\ToolUsageModel;
use App\Models\ToolRelatedClickModel;
use App\Models\ToolEventModel;
use App\Services\ToolRegistry;
use Twig\Environment;

class ToolsController
{
    private Environment $twig;
    private ToolRegistry $registry;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->registry = new ToolRegistry();
    }

    public function index(): void
    {
        $tools = $this->registry->active();
        $usage = new ToolUsageModel();
        $popularSlugs = array_values(array_unique(array_merge(
            $usage->getPopularSlugs(8, 30),
            ['annual-salary-net-calculator', 'withholding-3-3-calculator', 'loan-calculator', 'pyeong-calculator', 'word-counter', 'merge-pdf', 'margin-calculator', 'website-scope-estimator']
        )));
        $popularTools = $this->registry->popularBySlugs($popularSlugs, 8);
        $popularTools = $this->registry->withViewCounts($popularTools, $usage->getViewCounts(array_column($popularTools, 'slug')));
        $workRecommendations = [
            [
                'title' => '급여·세금·생활 계산',
                'description' => '급여 정산, 원천징수, 대출과 면적을 빠르게 확인하세요.',
                'tools' => $this->toolsBySlugs(['annual-salary-net-calculator', 'withholding-3-3-calculator', 'loan-calculator', 'pyeong-calculator']),
            ],
            [
                'title' => '판매·견적·사업 관리',
                'description' => '판매 수익과 견적 금액, 월별 현금흐름을 업무 기준으로 정리하세요.',
                'tools' => $this->toolsBySlugs(['integrated-selling-margin-calculator', 'quote-amount-designer', 'freelancer-cashflow-planner', 'website-scope-estimator']),
            ],
            [
                'title' => '문서·파일 반복 작업',
                'description' => 'PDF와 이미지 파일을 브라우저에서 빠르게 처리하세요.',
                'tools' => $this->toolsBySlugs(['pdf-batch-processor', 'merge-pdf', 'compress-pdf', 'image-compress']),
            ],
        ];

        echo $this->twig->render('tools/index.html.twig', [
            'title' => 'WY Tools | 무료 온라인 도구 모음',
            'description' => 'WY Tools는 개발, AI, PDF, 이미지, 텍스트, 계산기, 변환 도구를 한 곳에서 제공하는 무료 온라인 생산성 도구 플랫폼입니다.',
            'keywords' => 'WY Tools, 무료 온라인 도구, 개발자 도구, PDF 도구, 이미지 도구, AI 도구, 텍스트 도구, 계산기',
            'canonical_url' => 'https://wyhds.com/tools',
            'is_tools_page' => true,
            'tools' => $tools,
            'popular_tools' => $popularTools,
            'recent_tools' => $this->registry->recent(),
            'frequent_tools' => $this->registry->frequent(),
            'work_recommendations' => $workRecommendations,
            'categories' => $this->registry->categories(),
            'search_index' => $this->registry->searchIndex(),
        ]);
    }

    private function toolsBySlugs(array $slugs): array
    {
        $tools = [];
        foreach ($slugs as $slug) {
            $tool = $this->registry->find($slug);
            if ($tool !== null && ($tool['status'] ?? 'active') === 'active') {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    public function category(string $slug): void
    {
        $category = $this->registry->category($slug);

        if ($category === null) {
            http_response_code(404);
            echo $this->twig->render('errors/404.html.twig');
            return;
        }

        $title = $category['name'] . ' Tools | WY Tools';
        $description = $category['description'] . ' WY Tools에서 무료로 사용할 수 있습니다.';
        $keywords = $category['name'] . ' Tools, WY Tools, 무료 온라인 도구';

        if ($slug === 'calculator') {
            $title = '무료 계산기 모음 - 연봉 실수령액·3.3%·대출 이자·마진율 계산 | WY Tools';
            $description = '연봉 실수령액, 3.3% 원천징수, 대출 이자, 마진율, 부가세, 주휴수당, 퇴직금 계산기를 한 곳에서 무료로 사용할 수 있습니다.';
            $keywords = '무료 계산기, 연봉 실수령액 계산기, 3.3% 계산기, 대출 이자 계산기, 마진율 계산기, 부가세 계산기, 주휴수당 계산기';
        }

        echo $this->twig->render('tools/category.html.twig', [
            'title' => $title,
            'description' => $description,
            'keywords' => $keywords,
            'canonical_url' => 'https://wyhds.com/tools/category/' . $slug,
            'is_tools_page' => true,
            'category' => $category,
            'categories' => $this->registry->categories(),
            'tools' => $this->registry->byCategory($slug),
            'search_index' => $this->registry->searchIndex(),
        ]);
    }

    public function show(string $slug): void
    {
        $tool = $this->registry->find($slug);

        if ($tool === null) {
            http_response_code(404);
            echo $this->twig->render('errors/404.html.twig');
            return;
        }

        $usage = new ToolUsageModel();
        $usage->recordView($slug);
        $tool = $this->registry->withViewCounts([$tool], $usage->getViewCounts([$slug]))[0];
        $category = $this->registry->category($tool['category'] ?? 'developer');

        echo $this->twig->render('tools/show.html.twig', [
            'title' => $tool['meta_title'],
            'description' => $tool['meta_description'],
            'keywords' => $tool['keywords'],
            'canonical_url' => 'https://wyhds.com/tools/' . $slug,
            'is_tools_page' => true,
            'tool' => $tool,
            'category' => $category,
            'tools' => $this->registry->active(),
            'related_tools' => $this->registry->related($tool),
            'search_index' => $this->registry->searchIndex(),
        ]);
    }

    public function relatedClick(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        $source = trim((string)($_POST['source'] ?? ''));
        $target = trim((string)($_POST['target'] ?? ''));
        $context = trim((string)($_POST['context'] ?? 'related'));

        if ($source === '' || $target === '') {
            http_response_code(400);
            echo json_encode(['success' => false]);
            return;
        }

        if ($this->registry->find($source) === null || $this->registry->find($target) === null) {
            http_response_code(404);
            echo json_encode(['success' => false]);
            return;
        }

        $model = new ToolRelatedClickModel();
        $model->record(
            $source,
            $target,
            $context !== '' ? substr($context, 0, 60) : 'related',
            (string)($_POST['path'] ?? ''),
            (string)($_SERVER['HTTP_REFERER'] ?? ''),
            session_id(),
            $this->resolveClientIp()
        );

        echo json_encode(['success' => true]);
    }

    public function event(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        $toolSlug = trim((string)($_POST['tool_slug'] ?? ''));
        $eventName = trim((string)($_POST['event_name'] ?? ''));
        $context = trim((string)($_POST['context'] ?? ''));
        $allowedEvents = [
            'tool_start', 'tool_complete', 'copy', 'download', 'share', 'favorite', 'premium_cta', 'business_inquiry',
        ];

        if ($toolSlug === '' || !in_array($eventName, $allowedEvents, true) || $this->registry->find($toolSlug) === null) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            return;
        }

        $sessionHash = session_id() !== '' ? hash('sha256', session_id()) : '';
        (new ToolEventModel())->record($toolSlug, $eventName, substr($context, 0, 60), $sessionHash);
        echo json_encode(['success' => true]);
    }

    private function resolveClientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $ip = trim(explode(',', $candidate)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '';
    }
}

?>
