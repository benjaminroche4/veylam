<?php

declare(strict_types=1);

namespace App\Contact\MessageHandler;

use App\Contact\Message\SendContactEmailsMessage;
use App\Shared\Enum\EmailAddress;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final class SendContactEmailsHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendContactEmailsMessage $message): void
    {
        $sender = new Address(EmailAddress::Contact->value, 'Veylam');

        $adminEmail = new TemplatedEmail()
            ->from($sender)
            ->to(EmailAddress::Contact->value)
            ->replyTo(new Address($message->email, $message->name))
            ->subject($this->translator->trans('contact.email.admin.subject'))
            ->htmlTemplate('emails/contact_admin.html.twig')
            ->context([
                'contact_name' => $message->name,
                'contact_email' => $message->email,
                'contact_message' => $message->message,
            ]);

        $clientEmail = new TemplatedEmail()
            ->from($sender)
            ->to(new Address($message->email, $message->name))
            ->subject($this->translator->trans('contact.email.client.subject'))
            ->htmlTemplate('emails/contact_client.html.twig')
            ->context([
                'contact_name' => $message->name,
            ]);

        foreach ([$adminEmail, $clientEmail] as $email) {
            try {
                $this->mailer->send($email);
            } catch (TransportExceptionInterface $exception) {
                // A failed email must never surface to the visitor
                $this->logger->error('A contact email could not be sent.', [
                    'exception' => $exception,
                ]);
            }
        }
    }
}
