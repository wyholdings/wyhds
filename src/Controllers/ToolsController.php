<?php

namespace App\Controllers;

use App\Models\ToolUsageModel;
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
        $popularTools = $this->registry->popularBySlugs($usage->getPopularSlugs(8, 30), 8);
        $popularTools = $this->registry->withViewCounts($popularTools, $usage->getViewCounts(array_column($popularTools, 'slug')));

        echo $this->twig->render('tools/index.html.twig', [
            'title' => 'WY Tools | 무료 온라인 도구 모음',
            'description' => 'WY Tools는 개발, AI, PDF, 이미지, 텍스트, 계산기, 변환 도구를 한 곳에서 제공하는 무료 온라인 생산성 도구 플랫폼입니다.',
            'keywords' => 'WY Tools, 무료 온라인 도구, 개발자 도구, PDF 도구, 이미지 도구, AI 도구, 텍스트 도구, 계산기',
            'canonical_url' => 'https://wyhds.com/tools',
            'tools' => $tools,
            'popular_tools' => $popularTools,
            'recent_tools' => $this->registry->recent(),
            'frequent_tools' => $this->registry->frequent(),
            'categories' => $this->registry->categories(),
            'search_index' => $this->registry->searchIndex(),
        ]);
    }

    public function category(string $slug): void
    {
        $category = $this->registry->category($slug);

        if ($category === null) {
            http_response_code(404);
            echo $this->twig->render('errors/404.html.twig');
            return;
        }

        echo $this->twig->render('tools/category.html.twig', [
            'title' => $category['name'] . ' Tools | WY Tools',
            'description' => $category['description'] . ' WY Tools에서 무료로 사용할 수 있습니다.',
            'keywords' => $category['name'] . ' Tools, WY Tools, 무료 온라인 도구',
            'canonical_url' => 'https://wyhds.com/tools/category/' . $slug,
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
            'tool' => $tool,
            'category' => $category,
            'tools' => $this->registry->active(),
            'related_tools' => $this->registry->related($tool),
            'search_index' => $this->registry->searchIndex(),
        ]);
    }
}

?>
