<?php

declare(strict_types=1);

namespace App\Tests\Contact;

use App\Contact\Message\SendContactEmailsMessage;
use App\Contact\MessageHandler\SendContactEmailsHandler;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\BodyRenderer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SendContactEmailsHandlerTest extends KernelTestCase
{
    public function testItRendersAndSendsAdminAndClientEmails(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        // Rendering the real Twig templates catches template and context errors
        // that the in-memory transport of the functional tests never exercises
        $mailer = new class(new BodyRenderer($container->get(Environment::class))) implements MailerInterface {
            /** @var list<TemplatedEmail> */
            public array $sent = [];

            public function __construct(private readonly BodyRendererInterface $renderer)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                \assert($message instanceof TemplatedEmail);
                $this->renderer->render($message);
                $this->sent[] = $message;
            }
        };

        $handler = new SendContactEmailsHandler(
            $mailer,
            $container->get(TranslatorInterface::class),
            new NullLogger(),
        );

        $handler(new SendContactEmailsMessage(
            'Jane Doe',
            'jane@example.com',
            'Hello, I would like to talk about a partnership.',
        ));

        self::assertCount(2, $mailer->sent);

        [$adminEmail, $clientEmail] = $mailer->sent;

        self::assertSame('contact@veylam.eu', $adminEmail->getTo()[0]->getAddress());
        self::assertSame('jane@example.com', $adminEmail->getReplyTo()[0]->getAddress());
        self::assertStringContainsString('Jane Doe', (string) $adminEmail->getHtmlBody());
        self::assertStringContainsString('partnership', (string) $adminEmail->getHtmlBody());

        self::assertSame('jane@example.com', $clientEmail->getTo()[0]->getAddress());
        self::assertSame('contact@veylam.eu', $clientEmail->getFrom()[0]->getAddress());
        self::assertStringContainsString('Jane Doe', (string) $clientEmail->getHtmlBody());
    }
}
