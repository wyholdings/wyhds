<?php

namespace App\Controllers;

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
        ]);
    }

    public function services(): void
    {
        $contactToken = $this->ensureContactFormToken();
        echo $this->twig->render('services/services.html.twig', [
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
}

?>
