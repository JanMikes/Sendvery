<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\ScriptsDnsRecords;
use App\Tests\WebTestCase;
use App\Value\DmarcPolicy;
use App\Value\Dns\DmarcSetupMode;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Switching a domain to managed DMARC while it still publishes its own `_dmarc`
 * TXT record leaves the zone in a state where the CNAME we hand over cannot
 * work: RFC 1034 §3.6.2 forbids a CNAME from coexisting with any other record
 * at the same name.
 *
 * The surfaces used to hand over the CNAME and say nothing about it — "it does
 * not check there is existing dmarc txt and does not instruct me to delete it".
 * These tests pin down that every page asking for the CNAME names the record in
 * the way, shows its value, and orders the two edits.
 */
final class ManagedDmarcConflictingTxtTest extends WebTestCase
{
    use ScriptsDnsRecords;

    #[Test]
    public function theHealthPageAsksForTheDeletionBeforeTheCnameAndShowsTheRecordToDelete(): void
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->plan('pro')->build();
        assert(null !== $persona->domain);

        $domainName = $persona->domain->domain;
        $this->makeManagedPending($persona->domain->id->toString());
        $this->scriptDns()->withTxt('_dmarc.'.$domainName, 'v=DMARC1; p=quarantine; rua=mailto:me@'.$domainName);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s/health', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        self::assertCount(
            1,
            $crawler->filter('[data-testid="guided-setup-prerequisite"]'),
            'The record blocking the CNAME must be a step of its own, not a footnote.',
        );
        self::assertSame(
            'Delete the existing record',
            trim($crawler->filter('[data-testid="guided-dns-prerequisite-action"]')->text()),
            'The verb has to be the one the user performs in their DNS panel.',
        );
        self::assertSame(
            'v=DMARC1; p=quarantine; rua=mailto:me@'.$domainName,
            trim($crawler->filter('[data-testid="guided-dns-prerequisite-current"]')->text()),
            'Showing the exact value is what lets the user delete the right row.',
        );

        // Both edits, in order: the CNAME is still handed over below the deletion.
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Step 1', $body);
        self::assertStringContainsString('Step 2', $body);
        self::assertStringContainsString(
            'Swap 1 DNS record — delete the TXT, add the CNAME',
            $body,
            'The headline is all some users read before switching to their DNS provider.',
        );
        self::assertStringContainsString($domainName.'._dmarc.', $body, 'The CNAME target still has to be on the page.');
    }

    #[Test]
    public function aManagedDomainWithNothingInTheWayIsStillAskedForOneRecordOnly(): void
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->plan('pro')->build();
        assert(null !== $persona->domain);

        $this->makeManagedPending($persona->domain->id->toString());

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s/health', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('[data-testid="guided-setup-prerequisite"]'),
            'With no record in the way we must not invent a deletion.',
        );
        self::assertStringContainsString('Add 1 DNS record — CNAME', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function theManagedDmarcCardOnTheDomainPageNamesTheSameBlockingRecord(): void
    {
        // The detail page carries a second CNAME instruction in the managed card.
        // Silent there and explicit on the health page would be the same bug in
        // a different place.
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->plan('pro')->build();
        assert(null !== $persona->domain);

        $domainName = $persona->domain->domain;
        $this->makeManagedPending($persona->domain->id->toString(), hostedRecordId: 'cf-1');
        $this->scriptDns()->withTxt('_dmarc.'.$domainName, 'v=DMARC1; p=none; rua=mailto:me@'.$domainName);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $warning = $crawler->filter('[data-testid="managed-dmarc-conflicting-txt"]');
        self::assertCount(1, $warning, 'The CNAME-pending card must name the record blocking the CNAME.');
        self::assertStringContainsString('v=DMARC1; p=none; rua=mailto:me@'.$domainName, $warning->text());
    }

    /**
     * A domain on the managed path with an unverified CNAME, plus one landed DNS
     * check so the surface has verdicts to show rather than the pending panel.
     */
    private function makeManagedPending(string $domainId, ?string $hostedRecordId = null): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $domain = $em->find(MonitoredDomain::class, Uuid::fromString($domainId));
        assert(null !== $domain);

        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $domain->managedPolicyP = DmarcPolicy::None;
        $domain->cloudflareHostedDmarcRecordId = $hostedRecordId;

        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: null,
            isValid: false,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        );
        $check->popEvents();
        $em->persist($check);
        $em->flush();
    }
}
