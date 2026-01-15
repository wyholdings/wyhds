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
        echo $this->twig->render('index.html.twig');
    }

    public function portfolio(): void
    {
        echo $this->twig->render('portfolio/portfolio.html.twig');
    }

    public function services(): void
    {
        echo $this->twig->render('services/services.html.twig');
    }
}

?>
