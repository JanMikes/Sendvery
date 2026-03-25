<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BillingCancelController extends AbstractController
{
    #[Route('/app/settings/billing/cancel', name: 'dashboard_billing_cancel', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('dashboard/billing_cancel.html.twig');
    }
}
