<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BillingSuccessController extends AbstractController
{
    #[Route('/app/settings/billing/success', name: 'dashboard_billing_success', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('dashboard/billing_success.html.twig');
    }
}
