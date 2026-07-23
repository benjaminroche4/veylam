<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SitemapTest extends WebTestCase
{
    public function testItExposesAllPublicPagesInTheSitemap(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/sitemap.default.xml', (string) $client->getResponse()->getContent());

        $client->request('GET', '/sitemap.default.xml');

        self::assertResponseIsSuccessful();

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('/contact', $content);
        self::assertStringContainsString('/legal-notice', $content);
        self::assertStringContainsString('/privacy-policy', $content);
        self::assertStringContainsString('/terms-of-use', $content);
    }

    public function testItSendsSecurityHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $headers = $client->getResponse()->headers;

        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertNotNull($headers->get('Referrer-Policy'));
        self::assertNotNull($headers->get('Permissions-Policy'));
        self::assertNotNull($headers->get('Content-Security-Policy-Report-Only'));
    }
}
