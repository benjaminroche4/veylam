<?php

declare(strict_types=1);

namespace App\Tests\Flow;

use App\Contact\Entity\Contact;
use App\Contact\Message\SendContactEmailsMessage;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class ContactFlowTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // The test database persists between runs: start every test from a clean table
        static::getContainer()->get('doctrine')->getConnection()->executeStatement('DELETE FROM contact');
    }

    public function testItDisplaysTheContactForm(): void
    {
        $this->client->request('GET', '/contact');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#contact-form form');
        self::assertSelectorExists('input[name="contact[name]"]');
        self::assertSelectorExists('input[name="contact[email]"]');
        self::assertSelectorExists('textarea[name="contact[message]"]');
    }

    public function testItAcceptsValidSubmissionWithTurboStream(): void
    {
        $this->submitContact([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'message' => 'Hello, I would like to talk about a partnership.',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('target="contact-form"', (string) $this->client->getResponse()->getContent());

        $contacts = static::getContainer()->get('doctrine')->getRepository(Contact::class)->findBy(['email' => 'jane@example.com']);
        self::assertCount(1, $contacts);

        self::assertCount(1, $this->dispatchedContactMessages());
    }

    public function testItRejectsContactSubmissionWithInvalidEmail(): void
    {
        $this->submitContact([
            'name' => 'Jane Doe',
            'email' => 'not-an-email',
            'message' => 'Hello, I would like to talk about a partnership.',
        ]);

        // [o2switch] Validation errors may ship as 200 instead of 422
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 422]);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="contact-form"', $content);
        self::assertStringContainsString('Please enter a valid email address.', $content);

        self::assertCount(0, $this->dispatchedContactMessages());
    }

    public function testItSilentlyAcceptsHoneypotSubmission(): void
    {
        $this->submitContact([
            'name' => 'Bot Bot',
            'email' => 'bot@example.com',
            'message' => 'Spam message that should never be stored.',
            'website' => 'https://spam.example.com',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('target="contact-form"', (string) $this->client->getResponse()->getContent());

        $contacts = static::getContainer()->get('doctrine')->getRepository(Contact::class)->findBy(['email' => 'bot@example.com']);
        self::assertCount(0, $contacts);

        self::assertCount(0, $this->dispatchedContactMessages());
    }

    public function testItRejectsContactSubmissionWhenRateLimited(): void
    {
        $this->client->disableReboot();

        /** @var RateLimiterFactoryInterface $limiterFactory */
        $limiterFactory = static::getContainer()->get('limiter.form_contact');
        $limiterFactory->create('127.0.0.1')->consume(1000);

        $this->submitContact([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'message' => 'Hello, I would like to talk about a partnership.',
        ]);

        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 422]);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Too many messages were sent from your connection.', $content);

        self::assertCount(0, $this->dispatchedContactMessages());
    }

    public function testItFallsBackToRedirectWithoutTurbo(): void
    {
        $crawler = $this->client->request('GET', '/contact');
        $form = $crawler->filter('#contact-form form')->form([
            'contact[name]' => 'Jane Doe',
            'contact[email]' => 'jane-nojs@example.com',
            'contact[message]' => 'Hello, I would like to talk about a partnership.',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/contact');
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('no-cache', $this->client->getResponse()->headers->get('X-LiteSpeed-Cache-Control'));
        self::assertStringContainsString('Message sent', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @param array<string, string> $fields
     */
    private function submitContact(array $fields): void
    {
        $crawler = $this->client->request('GET', '/contact');
        $token = $crawler->filter('input[name="contact[_token]"]')->attr('value');

        $this->client->request('POST', '/contact', [
            'contact' => array_merge($fields, ['_token' => (string) $token]),
        ], [], [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
        ]);
    }

    /**
     * @return list<object>
     */
    private function dispatchedContactMessages(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.sync');

        return array_values(array_filter(
            array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message) => $message instanceof SendContactEmailsMessage,
        ));
    }
}
