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
        echo $this->twig->render('index.html.twig', [
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
