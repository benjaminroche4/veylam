<?php

declare(strict_types=1);

namespace App\Contact\Message;

final readonly class SendContactEmailsMessage
{
    public function __construct(
        public string $name,
        public string $email,
        public string $message,
    ) {
    }
}
