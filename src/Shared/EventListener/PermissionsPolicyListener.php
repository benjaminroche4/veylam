<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Nelmio does not cover Permissions-Policy: sent here on every response.
 */
#[AsEventListener]
final class PermissionsPolicyListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getResponse()->headers->set(
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), microphone=(), payment=(), usb=()',
        );
    }
}
