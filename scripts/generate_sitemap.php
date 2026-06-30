<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Services\ToolRegistry;

$baseUrl = 'https://wyhds.com';
$today = gmdate('Y-m-d\T00:00:00+00:00');
$registry = new ToolRegistry();
$existingSitemap = __DIR__ . '/../public/sitemap.xml';
$outputPath = $existingSitemap;

$urls = [
    ['loc' => '/', 'lastmod' => $today, 'priority' => '1.00'],
    ['loc' => '/about', 'lastmod' => $today, 'priority' => '0.80'],
    ['loc' => '/portfolio', 'lastmod' => $today, 'priority' => '0.80'],
    ['loc' => '/services', 'lastmod' => $today, 'priority' => '0.80'],
    ['loc' => '/tools', 'lastmod' => $today, 'priority' => '0.90'],
];

foreach ($registry->categories() as $category) {
    if (($category['count'] ?? 0) < 1) {
        continue;
    }

    $urls[] = [
        'loc' => $category['url'],
        'lastmod' => $today,
        'priority' => $category['slug'] === 'calculator' ? '0.82' : '0.70',
    ];
}

foreach ($registry->active() as $tool) {
    $priority = ($tool['category'] ?? '') === 'calculator' ? '0.78' : '0.72';
    if (!empty($tool['is_popular'])) {
        $priority = ($tool['category'] ?? '') === 'calculator' ? '0.84' : '0.78';
    }

    $urls[] = [
        'loc' => $tool['url'],
        'lastmod' => $today,
        'priority' => $priority,
    ];
}

if (is_file($existingSitemap)) {
    $xml = file_get_contents($existingSitemap) ?: '';
    if (preg_match_all('#<loc>https://wyhds\.com(/portfolio/[0-9]+)</loc>#', $xml, $matches)) {
        foreach (array_unique($matches[1]) as $portfolioPath) {
            $urls[] = [
                'loc' => $portfolioPath,
                'lastmod' => $today,
                'priority' => '0.64',
            ];
        }
    }
}

$seen = [];
$urls = array_values(array_filter($urls, static function (array $url) use (&$seen): bool {
    if (isset($seen[$url['loc']])) {
        return false;
    }

    $seen[$url['loc']] = true;
    return true;
}));

$content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$content .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

foreach ($urls as $url) {
    $content .= "  <url>\n";
    $content .= '    <loc>' . htmlspecialchars($baseUrl . $url['loc'], ENT_XML1) . "</loc>\n";
    $content .= '    <lastmod>' . htmlspecialchars($url['lastmod'], ENT_XML1) . "</lastmod>\n";
    $content .= '    <priority>' . htmlspecialchars($url['priority'], ENT_XML1) . "</priority>\n";
    $content .= "  </url>\n";
}

$content .= "</urlset>\n";

file_put_contents($outputPath, $content);

echo 'Generated ' . count($urls) . ' sitemap URLs at ' . $outputPath . PHP_EOL;
