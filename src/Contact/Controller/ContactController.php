<?php

declare(strict_types=1);

namespace App\Contact\Controller;

use App\Contact\Entity\Contact;
use App\Contact\Form\ContactType;
use App\Contact\Message\SendContactEmailsMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class ContactController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly RateLimiterFactoryInterface $formContactLimiter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'], options: ['sitemap' => ['priority' => 0.8, 'changefreq' => 'monthly']])]
    public function __invoke(Request $request): Response
    {
        $contact = new Contact();
        $form = $this->createForm(ContactType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // Honeypot filled: pretend everything went fine, never reveal the detection
            if ('' !== trim((string) $form->get('website')->getData())) {
                return $this->successResponse($request);
            }

            $limit = $this->formContactLimiter->create($request->getClientIp() ?? 'anonymous')->consume();
            if (!$limit->isAccepted()) {
                $form->addError(new FormError($this->translator->trans('contact.form.rate_limited')));

                return $this->formResponse($request, $form);
            }

            if ($form->isValid()) {
                $this->entityManager->persist($contact);
                $this->entityManager->flush();

                $this->bus->dispatch(new SendContactEmailsMessage(
                    (string) $contact->getName(),
                    (string) $contact->getEmail(),
                    (string) $contact->getMessage(),
                ));

                return $this->successResponse($request);
            }

            return $this->formResponse($request, $form);
        }

        $session = $request->getSession();
        $success = $session instanceof FlashBagAwareSessionInterface
            && [] !== $session->getFlashBag()->get('contact_success');

        $response = $this->render('public/contact/index.html.twig', [
            'form' => $form,
            'success' => $success,
        ]);

        if ($success) {
            // The no-JS confirmation page must never be cached, [o2switch] LiteSpeed included
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
        }

        return $response;
    }

    private function successResponse(Request $request): Response
    {
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('public/contact/success.stream.html.twig');
        }

        $this->addFlash('contact_success', true);

        return $this->redirectToRoute('app_contact');
    }

    private function formResponse(Request $request, FormInterface $form): Response
    {
        // [o2switch] Validation errors ship with status 200: LiteSpeed intercepts 4xx on shared hosting
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('public/contact/form.stream.html.twig', [
                'form' => $form,
            ]);
        }

        return $this->render('public/contact/index.html.twig', [
            'form' => $form,
            'success' => false,
        ]);
    }
}
