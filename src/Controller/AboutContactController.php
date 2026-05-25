<?php

declare(strict_types=1);

namespace App\Controller;

use App\FormData\ContactInquiryData;
use App\Message\CreateContactInquiry;
use App\Services\IdentityProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AboutContactController extends AbstractController
{
    /**
     * No human fills four fields (name + email + subject + ≥10-char message)
     * in under 2 seconds. The threshold is deliberately generous — even a
     * power-user with the form auto-filled clears 2s — so this drops
     * scripted submissions without producing visible false positives.
     */
    private const int MINIMUM_HUMAN_FILL_SECONDS = 2;

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
        private readonly ClockInterface $clock,
        private readonly ValidatorInterface $validator,
        #[Target('contact_form')]
        private readonly RateLimiterFactoryInterface $contactFormLimiter,
    ) {
    }

    #[Route('/about/contact', name: 'about_contact', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $data = new ContactInquiryData();
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('contact_form', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $data->name = trim($request->request->getString('name'));
            $data->email = trim($request->request->getString('email'));
            $data->subject = trim($request->request->getString('subject'));
            $data->message = trim($request->request->getString('message'));
            $data->website = $request->request->getString('website');
            $renderedAtRaw = $request->request->get('renderedAt');
            $data->renderedAt = is_numeric($renderedAtRaw) ? (int) $renderedAtRaw : null;

            // 1. Honeypot — bots fill every visible-looking input. Humans
            //    can't see `website` (display:none + tabindex=-1 +
            //    aria-hidden). Pretend success, persist nothing, send nothing.
            if ('' !== $data->website) {
                return $this->redirectToRoute('about_contact', ['sent' => 1]);
            }

            // 2. Time-trap — if the hidden render-timestamp is missing or
            //    elapsed < 2s, treat as scripted and pretend-accept.
            $nowTs = $this->clock->now()->getTimestamp();
            if (null === $data->renderedAt || ($nowTs - $data->renderedAt) < self::MINIMUM_HUMAN_FILL_SECONDS) {
                return $this->redirectToRoute('about_contact', ['sent' => 1]);
            }

            // 3. Per-IP rate-limit (5/hour). Real error this time — a
            //    legitimate user being throttled needs to know.
            $limiter = $this->contactFormLimiter->create($request->getClientIp() ?? 'anonymous');
            if (!$limiter->consume()->isAccepted()) {
                $errors[] = 'You have sent too many messages. Please try again in an hour, or email jan.mikes@sendvery.com directly.';
            } else {
                $violations = $this->validator->validate($data);

                if (count($violations) > 0) {
                    foreach ($violations as $violation) {
                        $errors[] = (string) $violation->getMessage();
                    }
                } else {
                    $this->commandBus->dispatch(new CreateContactInquiry(
                        inquiryId: $this->identityProvider->nextIdentity(),
                        name: $data->name,
                        email: $data->email,
                        subject: $data->subject,
                        message: $data->message,
                        submitterIp: $request->getClientIp(),
                        userAgent: substr($request->headers->get('User-Agent', '') ?? '', 0, 512),
                    ));

                    return $this->redirectToRoute('about_contact', ['sent' => 1]);
                }
            }
        }

        // GET (or POST re-render): stamp the render timestamp so the
        // time-trap can compare against it on submit.
        if (null === $data->renderedAt) {
            $data->renderedAt = $this->clock->now()->getTimestamp();
        }

        return $this->render('about/contact.html.twig', [
            'data' => $data,
            'errors' => $errors,
            'sent' => $request->query->getBoolean('sent'),
        ]);
    }
}
