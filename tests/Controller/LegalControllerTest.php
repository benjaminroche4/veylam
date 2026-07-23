<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LegalControllerTest extends WebTestCase
{
    public function testItDisplaysTheLegalNoticePage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/legal-notice');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Legal notice');
    }

    public function testItDisplaysThePrivacyPolicyPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/privacy-policy');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Privacy policy');
    }

    public function testItDisplaysTheTermsOfUsePage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/terms-of-use');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Terms of Use');
    }

    public function testLegalPagesLinkFromTheFooter(): void
    {
        $client = static::createClient();
        $client->request('GET', '/contact');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer a[href="/legal-notice"]');
        self::assertSelectorExists('footer a[href="/privacy-policy"]');
        self::assertSelectorExists('footer a[href="/terms-of-use"]');
    }
}
