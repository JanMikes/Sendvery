<?php

declare(strict_types=1);

namespace App\Controller;

use App\FormData\BetaAccessRequestData;
use App\Message\RequestBetaAccess;
use App\Services\IdentityProvider;
use App\Value\SubscriptionPlan;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RequestBetaAccessController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/request-access', name: 'request_beta_access', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $data = new BetaAccessRequestData();
        $data->requestedPlan = SubscriptionPlan::tryFrom($request->query->getString('plan')) ?? SubscriptionPlan::Personal;
        $source = $request->isMethod('POST')
            ? ($request->request->getString('source') ?: 'request-access')
            : $request->query->getString('source', 'request-access');

        $errors = [];
        $success = false;

        if ($request->isMethod('POST')) {
            $data->email = trim($request->request->getString('email'));
            $data->name = trim($request->request->getString('name'));
            $company = trim($request->request->getString('company'));
            $data->company = '' !== $company ? $company : null;
            $data->requestedPlan = SubscriptionPlan::tryFrom($request->request->getString('plan')) ?? SubscriptionPlan::Personal;
            $domainCountValue = $request->request->getString('domain_count');
            $data->domainCount = '' !== $domainCountValue ? (int) $domainCountValue : null;
            $messageValue = trim($request->request->getString('message'));
            $data->message = '' !== $messageValue ? $messageValue : null;

            $violations = $this->validator->validate($data);

            if (count($violations) > 0) {
                foreach ($violations as $violation) {
                    $errors[] = (string) $violation->getMessage();
                }
            } else {
                $this->commandBus->dispatch(new RequestBetaAccess(
                    requestId: $this->identityProvider->nextIdentity(),
                    email: $data->email,
                    name: $data->name,
                    company: $data->company,
                    requestedPlan: $data->requestedPlan,
                    domainCount: $data->domainCount,
                    message: $data->message,
                    source: $source,
                ));

                $success = true;
            }
        }

        return $this->render('request_access/form.html.twig', [
            'data' => $data,
            'errors' => $errors,
            'success' => $success,
            'plans' => array_values(array_filter(
                SubscriptionPlan::cases(),
                static fn (SubscriptionPlan $plan): bool => SubscriptionPlan::Unlimited !== $plan,
            )),
            'source' => $source,
        ]);
    }
}
