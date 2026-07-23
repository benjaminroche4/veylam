<?php

namespace App\Controller\Public;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'], options: ['sitemap' => ['priority' => 1.0, 'changefreq' => 'monthly']])]
    public function __invoke(): Response
    {
        return $this->render('public/home/index.html.twig');
    }
}
