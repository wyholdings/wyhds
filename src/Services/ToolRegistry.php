<?php

namespace App\Services;

class ToolRegistry
{
    private array $tools;
    private array $categories;

    public function __construct(?array $tools = null, ?array $categories = null)
    {
        $this->tools = $tools ?? require __DIR__ . '/../../config/tools.php';
        $this->categories = $categories ?? require __DIR__ . '/../../config/tool_categories.php';
        $this->tools = array_map([$this, 'normalizeTool'], $this->tools);
    }

    public function all(): array
    {
        return array_values($this->tools);
    }

    public function active(): array
    {
        return array_values(array_filter($this->tools, static fn (array $tool): bool => ($tool['status'] ?? 'active') === 'active'));
    }

    public function find(string $slug): ?array
    {
        return $this->tools[$slug] ?? null;
    }

    public function categories(): array
    {
        $counts = [];
        foreach ($this->active() as $tool) {
            $category = $tool['category'] ?? 'developer';
            $counts[$category] = ($counts[$category] ?? 0) + 1;
        }

        return array_map(function (array $category, string $slug) use ($counts): array {
            $category['slug'] = $slug;
            $category['url'] = '/tools/category/' . $slug;
            $category['count'] = $counts[$slug] ?? 0;
            return $category;
        }, $this->categories, array_keys($this->categories));
    }

    public function category(string $slug): ?array
    {
        if (!isset($this->categories[$slug])) {
            return null;
        }

        $count = count($this->byCategory($slug));

        return array_merge($this->categories[$slug], [
            'slug' => $slug,
            'url' => '/tools/category/' . $slug,
            'count' => $count,
        ]);
    }

    public function byCategory(string $slug): array
    {
        return array_values(array_filter($this->active(), static fn (array $tool): bool => ($tool['category'] ?? '') === $slug));
    }

    public function popular(int $limit = 8): array
    {
        return array_slice(array_values(array_filter($this->active(), static fn (array $tool): bool => (bool)($tool['is_popular'] ?? false))), 0, $limit);
    }

    public function popularBySlugs(array $slugs, int $limit = 8): array
    {
        $popular = [];
        foreach ($slugs as $slug) {
            if (isset($this->tools[$slug]) && ($this->tools[$slug]['status'] ?? 'active') === 'active') {
                $popular[] = $this->tools[$slug];
            }
        }

        if (count($popular) < $limit) {
            foreach ($this->popular($limit) as $tool) {
                if (!in_array($tool['slug'], array_column($popular, 'slug'), true)) {
                    $popular[] = $tool;
                }
                if (count($popular) >= $limit) {
                    break;
                }
            }
        }

        return array_slice($popular, 0, $limit);
    }

    public function recent(int $limit = 8): array
    {
        return array_slice(array_values(array_filter($this->active(), static fn (array $tool): bool => (bool)($tool['is_recent'] ?? false))), 0, $limit);
    }

    public function frequent(int $limit = 8): array
    {
        return array_slice(array_values(array_filter($this->active(), static fn (array $tool): bool => (bool)($tool['is_frequent'] ?? false))), 0, $limit);
    }

    public function related(array $tool, int $limit = 6): array
    {
        $related = [];
        foreach (($tool['related'] ?? []) as $slug) {
            if (isset($this->tools[$slug])) {
                $related[] = $this->tools[$slug];
            }
        }

        if (count($related) < $limit) {
            foreach ($this->byCategory($tool['category'] ?? '') as $candidate) {
                if ($candidate['slug'] === $tool['slug']) {
                    continue;
                }
                if (!in_array($candidate['slug'], array_column($related, 'slug'), true)) {
                    $related[] = $candidate;
                }
                if (count($related) >= $limit) {
                    break;
                }
            }
        }

        return array_slice($related, 0, $limit);
    }

    public function searchIndex(): array
    {
        return array_map(static fn (array $tool): array => [
            'name' => $tool['name'],
            'slug' => $tool['slug'],
            'url' => $tool['url'],
            'category' => $tool['category'],
            'summary' => $tool['summary'],
            'keywords' => $tool['keywords'] ?? '',
        ], $this->active());
    }

    public function withViewCounts(array $tools, array $counts): array
    {
        return array_map(static function (array $tool) use ($counts): array {
            $tool['view_count'] = $counts[$tool['slug']] ?? 0;
            return $tool;
        }, $tools);
    }

    private function normalizeTool(array $tool): array
    {
        if (isset($tool['keywords']) && is_array($tool['keywords'])) {
            $tool['keywords'] = implode(', ', $tool['keywords']);
        }

        $tool['summary'] = (string)($tool['summary'] ?? $tool['description'] ?? $tool['name']);
        $tool['description'] = (string)($tool['description'] ?? $tool['summary']);
        $tool['meta_description'] = (string)($tool['meta_description'] ?? $tool['description']);
        $tool['keywords'] = (string)($tool['keywords'] ?? $tool['name']);

        return $tool;
    }
}
