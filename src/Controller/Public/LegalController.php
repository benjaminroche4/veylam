<?php

declare(strict_types=1);

namespace App\Controller\Public;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegalController extends AbstractController
{
    #[Route('/legal-notice', name: 'app_legal_notice', methods: ['GET'], options: ['sitemap' => ['priority' => 0.3, 'changefreq' => 'yearly']])]
    public function legalNotice(): Response
    {
        return $this->render('public/legal/legal_notice.html.twig');
    }

    #[Route('/privacy-policy', name: 'app_privacy_policy', methods: ['GET'], options: ['sitemap' => ['priority' => 0.3, 'changefreq' => 'yearly']])]
    public function privacyPolicy(): Response
    {
        return $this->render('public/legal/privacy_policy.html.twig');
    }

    #[Route('/terms-of-use', name: 'app_terms_of_use', methods: ['GET'], options: ['sitemap' => ['priority' => 0.3, 'changefreq' => 'yearly']])]
    public function termsOfUse(): Response
    {
        return $this->render('public/legal/terms_of_use.html.twig');
    }
}
