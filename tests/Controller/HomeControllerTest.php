<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testItRendersTheHomepage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1');
        self::assertSelectorTextContains('header', 'Veylam');
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
