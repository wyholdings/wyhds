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
        echo $this->twig->render('portfolio/portfolio_view.html.twig', [
            'contact_form_token' => $contactToken,
            'project_id' => $id,
            'portfolio' => $this->getPortfolio((int)$id),
        ]);
    }

    public function services(): void
    {
        $contactToken = $this->ensureContactFormToken();
        echo $this->twig->render('services/services.html.twig', [
            'contact_form_token' => $contactToken,
        ]);
    }

    public function contact(): void
    {
        $contactToken = $this->ensureContactFormToken();
        $inquiryType = (string)($_GET['inquiry'] ?? '');
        $inquiryType = in_array($inquiryType, ['pro', 'business'], true) ? $inquiryType : '';
        $toolSlug = preg_replace('/[^a-z0-9-]/', '', (string)($_GET['tool'] ?? ''));
        echo $this->twig->render('contact.html.twig', [
            'contact_form_token' => $contactToken,
            'contact_inquiry_type' => $inquiryType,
            'contact_tool_slug' => $toolSlug,
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
