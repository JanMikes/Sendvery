<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\BetaAccessRequest;
use App\Tests\WebTestCase;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Mime\Email;

final class RequestBetaAccessTest extends WebTestCase
{
    #[Test]
    public function pageReturns200(): void
    {
        $client = self::createClient();
        $client->request('GET', '/request-access');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Request beta access');
        self::assertSelectorExists('input[name="email"]');
        self::assertSelectorExists('input[name="name"]');
        self::assertSelectorExists('select[name="plan"]');
    }

    #[Test]
    public function planQueryParamPreselectsPlan(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/request-access?plan=business');

        self::assertResponseIsSuccessful();
        self::assertSame('business', $crawler->filter('select[name="plan"] option[selected]')->attr('value'));
    }

    #[Test]
    public function unknownPlanQueryFallsBackToPersonal(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/request-access?plan=enterprise');

        self::assertResponseIsSuccessful();
        self::assertSame('personal', $crawler->filter('select[name="plan"] option[selected]')->attr('value'));
    }

    #[Test]
    public function submitValidRequestPersistsAndSendsEmails(): void
    {
        $client = self::createClient();
        $email = 'request-'.Uuid::uuid7()->toString().'@example.com';

        $client->request('POST', '/request-access', [
            'name' => 'Jane Doe',
            'email' => $email,
            'company' => 'Acme Corp',
            'plan' => 'business',
            'domain_count' => '12',
            'message' => 'Need to monitor all our domains',
            'source' => 'pricing',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Thanks');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $entity = $em->getRepository(BetaAccessRequest::class)->findOneBy(['email' => $email]);
        self::assertNotNull($entity);
        self::assertSame('Jane Doe', $entity->name);
        self::assertSame('Acme Corp', $entity->company);
        self::assertSame(SubscriptionPlan::Business, $entity->requestedPlan);
        self::assertSame(12, $entity->domainCount);
        self::assertSame('Need to monitor all our domains', $entity->message);
        self::assertSame('pricing', $entity->source);

        self::assertEmailCount(2);

        $messages = self::getMailerMessages();
        $byRecipient = [];
        foreach ($messages as $message) {
            assert($message instanceof Email);
            $byRecipient[$message->getTo()[0]->getAddress()] = $message;
        }

        self::assertArrayHasKey('requests@sendvery.test', $byRecipient);
        self::assertStringContainsString('Jane Doe', (string) $byRecipient['requests@sendvery.test']->getSubject());
        self::assertStringContainsString('business', (string) $byRecipient['requests@sendvery.test']->getSubject());

        self::assertArrayHasKey($email, $byRecipient);
        self::assertSame('We received your Sendvery beta access request', $byRecipient[$email]->getSubject());
    }

    #[Test]
    public function submitMinimalRequestSucceeds(): void
    {
        $client = self::createClient();
        $email = 'minimal-'.Uuid::uuid7()->toString().'@example.com';

        $client->request('POST', '/request-access', [
            'name' => 'Solo',
            'email' => $email,
            'plan' => 'personal',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Thanks');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $entity = $em->getRepository(BetaAccessRequest::class)->findOneBy(['email' => $email]);
        self::assertNotNull($entity);
        self::assertNull($entity->company);
        self::assertNull($entity->domainCount);
        self::assertNull($entity->message);
        self::assertSame(SubscriptionPlan::Personal, $entity->requestedPlan);
    }

    #[Test]
    public function submitInvalidEmailShowsError(): void
    {
        $client = self::createClient();
        $client->request('POST', '/request-access', [
            'name' => 'Test',
            'email' => 'not-an-email',
            'plan' => 'personal',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-error');
    }

    #[Test]
    public function submitEmptyNameShowsError(): void
    {
        $client = self::createClient();
        $client->request('POST', '/request-access', [
            'name' => '',
            'email' => 'test@example.com',
            'plan' => 'personal',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-error');
    }
}
