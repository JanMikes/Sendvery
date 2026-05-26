<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Exceptions\InvalidDkimSelectorException;
use App\Message\SetDomainDkimSelector;
use App\Services\DashboardContext;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SetDomainDkimSelectorController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/domains/{id}/dkim-selector', name: 'dashboard_domain_set_dkim_selector', methods: ['POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('domain_dkim_selector', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $selector = $request->request->get('selector');
        $selectorString = is_string($selector) ? $selector : null;

        try {
            $this->commandBus->dispatch(new SetDomainDkimSelector(
                domainId: Uuid::fromString($id),
                teamId: $this->dashboardContext->getTeamId()->toString(),
                selector: $selectorString,
            ));
        } catch (HandlerFailedException $e) {
            foreach ($e->getWrappedExceptions() as $wrapped) {
                if ($wrapped instanceof InvalidDkimSelectorException) {
                    $this->addFlash('error', $wrapped->getMessage());

                    return $this->redirectToRoute('dashboard_domain_detail', ['id' => $id]);
                }
                if ($wrapped instanceof \RuntimeException) {
                    throw $this->createNotFoundException('Domain not found.');
                }
            }

            throw $e;
        }

        $normalised = null === $selectorString || '' === trim($selectorString) ? null : trim($selectorString);

        if (null === $normalised) {
            $this->addFlash('success', 'DKIM selector cleared. Sendvery will brute-force selectors from the canonical registry again on the next DNS check.');
        } else {
            $this->addFlash('success', sprintf('DKIM selector set to "%s". Sendvery re-checked DNS using this selector.', $normalised));
        }

        return $this->redirectToRoute('dashboard_domain_detail', ['id' => $id]);
    }
}
