<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testItRendersTheHomepage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // The homepage is a fullscreen scene: the h1 stays for SEO but is visually hidden
        self::assertSelectorExists('h1.sr-only');
        self::assertSelectorExists('[data-controller="three-background"]');
    }

    public function testItExposesSeoMetadata(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('link[rel="canonical"]');
        self::assertSelectorExists('meta[name="description"]');
        self::assertSelectorExists('script[type="application/ld+json"]');

        $title = $crawler->filter('title')->text();
        self::assertStringContainsString('Veylam', $title);
        self::assertLessThanOrEqual(60, mb_strlen($title));

        $description = $crawler->filter('meta[name="description"]')->attr('content');
        self::assertGreaterThanOrEqual(120, mb_strlen($description));
        self::assertLessThanOrEqual(160, mb_strlen($description));
    }
}
