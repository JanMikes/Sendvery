<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class QuarantineTest extends WebTestCase
{
    /**
     * @return array{
     *     client: KernelBrowser,
     *     em: EntityManagerInterface,
     *     user: User,
     *     team: Team,
     *     domain: ?MonitoredDomain,
     *     mailbox: ?MailboxConnection,
     *     envelope: ReceivedReportEmail,
     *     quarantine: QuarantinedDmarcReport,
     *     domainName: string
     * }
     */
    private function bootClientWithQuarantinedReport(
        QuarantineReason $reason,
        bool $createMonitoredDomain = true,
        bool $createTeamMailbox = false,
        string $teamPlan = 'free',
    ): array {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'quarantine-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Quarantine Test',
            slug: 'quarantine-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: $teamPlan,
        );
        $team->popEvents();
        $em->persist($team);

        $em->persist(new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        ));

        $domainName = 'q-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';

        // For UnknownDomain reason we still create the matching monitored_domain
        // (representing the user "having added it after the report arrived")
        // so the team-scoping rule lets us see the quarantine row.
        $domain = null;
        if ($createMonitoredDomain) {
            $domain = new MonitoredDomain(
                id: Uuid::uuid7(),
                team: $team,
                domain: $domainName,
                createdAt: new \DateTimeImmutable(),
            );
            $domain->popEvents();
            $em->persist($domain);
        } else {
            // OnboardingRedirectListener bounces every /app/* request back to
            // onboarding unless the user already owns at least one
            // monitored_domain. For the "mailbox-only visibility" tests we
            // explicitly do NOT want a domain *matching the quarantine row*,
            // so seed an unrelated placeholder domain that satisfies the
            // onboarding gate without affecting the visibility query.
            $placeholder = new MonitoredDomain(
                id: Uuid::uuid7(),
                team: $team,
                domain: 'placeholder-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test',
                createdAt: new \DateTimeImmutable(),
            );
            $placeholder->popEvents();
            $em->persist($placeholder);
        }

        // For the mailbox-driven visibility path (`unknown_domain` + the report
        // came in via the team's own mailbox), seed a MailboxConnection and
        // attach it to the envelope.
        $mailbox = null;
        if ($createTeamMailbox) {
            $mailbox = new MailboxConnection(
                id: Uuid::uuid7(),
                team: $team,
                type: MailboxType::ImapUser,
                host: 'imap.example.com',
                port: 993,
                encryptedUsername: 'enc',
                encryptedPassword: 'enc',
                encryption: MailboxEncryption::Ssl,
                createdAt: new \DateTimeImmutable(),
            );
            $mailbox->popEvents();
            $em->persist($mailbox);
        }

        // Real-looking EML so the reprocess-flow handler can fully parse the
        // attachment instead of failing at MIME extraction.
        $envelopeMsgId = '<envelope-'.Uuid::uuid7()->toString().'@test>';
        $rawEml = $this->buildEmlWithDmarcReport($domainName, $envelopeMsgId);

        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: null === $mailbox ? ReportSource::CentralInbox : ReportSource::ByoMailbox,
            messageId: $envelopeMsgId,
            fromAddress: 'noreply-dmarc@google.com',
            subject: 'Report Domain: '.$domainName,
            receivedAt: new \DateTimeImmutable('-2 hours'),
            ingestedAt: new \DateTimeImmutable('-2 hours'),
            sizeBytes: strlen($rawEml),
            rawEml: $rawEml,
            mailboxConnection: $mailbox,
        );
        $em->persist($envelope);

        $xml = $this->buildDmarcXml($domainName, $envelopeMsgId);
        $compressed = gzencode($xml);
        assert(false !== $compressed);

        $quarantine = new QuarantinedDmarcReport(
            id: Uuid::uuid7(),
            receivedEmail: $envelope,
            domainName: $domainName,
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply-dmarc-support@google.com',
            dateRangeBegin: new \DateTimeImmutable('@1700000000'),
            dateRangeEnd: new \DateTimeImmutable('@1700086400'),
            quarantinedAt: new \DateTimeImmutable('-1 hour'),
            expiresAt: new \DateTimeImmutable('+60 days'),
            reason: $reason,
            reportXmlGz: $compressed,
        );
        $em->persist($quarantine);

        $em->flush();

        $client->loginUser($user);

        return [
            'client' => $client,
            'em' => $em,
            'user' => $user,
            'team' => $team,
            'domain' => $domain,
            'mailbox' => $mailbox,
            'envelope' => $envelope,
            'quarantine' => $quarantine,
            'domainName' => $domainName,
        ];
    }

    private function buildDmarcXml(string $policyDomain, string $messageId): string
    {
        $reportId = trim($messageId, '<>');

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <feedback>
              <report_metadata>
                <org_name>google.com</org_name>
                <email>noreply-dmarc-support@google.com</email>
                <report_id>{$reportId}</report_id>
                <date_range><begin>1700000000</begin><end>1700086400</end></date_range>
              </report_metadata>
              <policy_published>
                <domain>{$policyDomain}</domain>
                <p>none</p>
              </policy_published>
              <record>
                <row>
                  <source_ip>1.2.3.4</source_ip>
                  <count>1</count>
                  <policy_evaluated><disposition>none</disposition><dkim>pass</dkim><spf>pass</spf></policy_evaluated>
                </row>
                <identifiers><header_from>{$policyDomain}</header_from></identifiers>
                <auth_results><dkim><domain>{$policyDomain}</domain><result>pass</result></dkim></auth_results>
              </record>
            </feedback>
            XML;
    }

    private function buildEmlWithDmarcReport(string $policyDomain, string $messageId): string
    {
        $xml = $this->buildDmarcXml($policyDomain, $messageId);
        $base64Xml = chunk_split(base64_encode($xml), 76, "\r\n");
        $boundary = 'b-'.bin2hex(random_bytes(8));

        return implode("\r\n", [
            'From: noreply-dmarc-support@google.com',
            'To: reports@sendvery.test',
            "Subject: Report Domain: $policyDomain",
            "Message-ID: $messageId",
            'Date: Fri, 22 May 2026 08:00:00 +0000',
            'MIME-Version: 1.0',
            "Content-Type: multipart/mixed; boundary=\"$boundary\"",
            '',
            "--$boundary",
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Aggregate report attached.',
            '',
            "--$boundary",
            'Content-Type: application/xml; name="report.xml"',
            'Content-Disposition: attachment; filename="report.xml"',
            'Content-Transfer-Encoding: base64',
            '',
            $base64Xml,
            "--$boundary--",
            '',
        ]);
    }

    /**
     * Bootstrap an authenticated session and write a CSRF token directly into
     * it. Mirrors the helper in AlertActionsTest — the token manager
     * de-randomizes submitted values; a raw token (no `.`s) falls through
     * `derandomize` unchanged.
     */
    private function csrfToken(KernelBrowser $client, string $id): string
    {
        $client->request('GET', '/app/quarantine');

        $cookie = $client->getCookieJar()->get('MOCKSESSID') ?? $client->getCookieJar()->get('PHPSESSID');
        self::assertNotNull($cookie, 'Session cookie not set after warm-up GET.');

        $token = bin2hex(random_bytes(16));

        $factory = self::getContainer()->get('session.factory');
        assert($factory instanceof \Symfony\Component\HttpFoundation\Session\SessionFactoryInterface);

        $session = $factory->createSession();
        $session->setId($cookie->getValue());
        $session->start();
        $session->set('_csrf/'.$id, $token);
        $session->save();

        return $token;
    }

    private function createEmptyAuthenticatedClient(): KernelBrowser
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'quarantine-empty-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Empty Team',
            slug: 'empty-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $em->persist(new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        ));

        // OnboardingRedirectListener requires at least one monitored_domain
        // for the user — otherwise every /app/* request bounces to the
        // onboarding flow.
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'empty-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        $em->flush();

        $client->loginUser($user);

        return $client;
    }

    public function testListRendersQuarantinedRow(): void
    {
        $data = $this->bootClientWithQuarantinedReport(QuarantineReason::UnverifiedDomain);

        $data['client']->request('GET', '/app/quarantine');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Quarantine');
        self::assertSelectorTextContains('body', $data['domainName']);
        self::assertSelectorTextContains('body', 'Unverified domain');
    }

    public function testListShowsEmptyStateAndNoRecentLinkForFreshTeam(): void
    {
        $client = $this->createEmptyAuthenticatedClient();

        $client->request('GET', '/app/quarantine');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No reports in quarantine');
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString('View most recent report', $content);
    }

    public function testListEmptyStateLinksToMostRecentReportWhenAvailable(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'recent-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Recent Team',
            slug: 'recent-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $em->persist(new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        ));

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'recent-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        // Seed one DmarcReport so the empty state's "View most recent report"
        // link has something to point at.
        $report = new \App\Entity\DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'report-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $domain->domain,
            policyAdkim: \App\Value\DmarcAlignment::Relaxed,
            policyAspf: \App\Value\DmarcAlignment::Relaxed,
            policyP: \App\Value\DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable('-1 day'),
        );
        $em->persist($report);

        $em->flush();

        $client->loginUser($user);

        $client->request('GET', '/app/quarantine');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No reports in quarantine');
        self::assertSelectorTextContains('body', 'View most recent report');
    }

    public function testDetailRendersForOwnedQuarantineRow(): void
    {
        $data = $this->bootClientWithQuarantinedReport(QuarantineReason::UnverifiedDomain);

        $data['client']->request('GET', '/app/quarantine/'.$data['quarantine']->id->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Envelope details');
        self::assertSelectorTextContains('body', $data['domainName']);
        self::assertSelectorTextContains('body', 'Reprocess now');
    }

    public function testDetailReturns404ForUnknownId(): void
    {
        $data = $this->bootClientWithQuarantinedReport(QuarantineReason::UnverifiedDomain);

        $data['client']->request('GET', '/app/quarantine/'.Uuid::uuid7()->toString());

        self::assertResponseStatusCodeSame(404);
    }

    public function testDetailReturns404ForCrossTenantRow(): void
    {
        // Boot a session for team A.
        $data = $this->bootClientWithQuarantinedReport(QuarantineReason::UnverifiedDomain);

        // Create a quarantine row for team B (a totally separate team that
        // owns its own monitored_domain). Team A must not be able to read it.
        $em = $data['em'];
        $foreignTeam = new Team(
            id: Uuid::uuid7(),
            name: 'Foreign',
            slug: 'foreign-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $foreignTeam->popEvents();
        $em->persist($foreignTeam);

        $foreignDomainName = 'foreign-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';
        $foreignDomain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $foreignTeam,
            domain: $foreignDomainName,
            createdAt: new \DateTimeImmutable(),
        );
        $foreignDomain->popEvents();
        $em->persist($foreignDomain);

        $foreignEnvelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::CentralInbox,
            messageId: '<foreign-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'Foreign report',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 1,
            rawEml: 'x',
        );
        $em->persist($foreignEnvelope);

        $compressed = gzencode('<feedback/>');
        assert(false !== $compressed);

        $foreignQuarantine = new QuarantinedDmarcReport(
            id: Uuid::uuid7(),
            receivedEmail: $foreignEnvelope,
            domainName: $foreignDomainName,
            externalReportId: 'ext-foreign',
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            dateRangeBegin: new \DateTimeImmutable('@1700000000'),
            dateRangeEnd: new \DateTimeImmutable('@1700086400'),
            quarantinedAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+30 days'),
            reason: QuarantineReason::UnverifiedDomain,
            reportXmlGz: $compressed,
        );
        $em->persist($foreignQuarantine);
        $em->flush();

        $data['client']->request('GET', '/app/quarantine/'.$foreignQuarantine->id->toString());

        self::assertResponseStatusCodeSame(404);
    }

    public function testDetailShowsAddDomainFormForUnknownReason(): void
    {
        $data = $this->bootClientWithQuarantinedReport(QuarantineReason::UnknownDomain);

        $data['client']->request('GET', '/app/quarantine/'.$data['quarantine']->id->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Add '.$data['domainName']);
    }

    public function testDetailHidesAddDomainFormForPlanOverageReason(): void
    {
        $data = $this->bootClientWithQuarantinedReport(QuarantineReason::PlanOverage);

        $data['client']->request('GET', '/app/quarantine/'.$data['quarantine']->id->toString());

        self::assertResponseIsSuccessful();
        $content = $data['client']->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString('Add '.$data['domainName'], $content);
    }

    public function testDetailShowsPlanOverageBanner(): void
    {
        $data = $this->bootClientWithQuarantinedReport(QuarantineReason::PlanOverage);

        $data['client']->request('GET', '/app/quarantine/'.$data['quarantine']->id->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', "This report is over this month's cap");
        self::assertSelectorTextContains('body', 'View billing');
    }

    public function testReprocessWithoutCsrfReturns403(): void
    {
        $data = $this->bootClientWithQuarantinedReport(QuarantineReason::UnverifiedDomain);

        $data['client']->request('POST', '/app/quarantine/'.$data['quarantine']->id->toString().'/reprocess');

        self::assertResponseStatusCodeSame(403);
    }

    public function testReprocessHappyPathRedirectsAndDeletesRow(): void
    {
        $data = $this->bootClientWithQuarantinedReport(QuarantineReason::UnverifiedDomain);
        $token = $this->csrfToken($data['client'], 'quarantine_reprocess');
        $quarantineId = $data['quarantine']->id;

        $data['client']->request('POST', '/app/quarantine/'.$quarantineId->toString().'/reprocess', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/app/quarantine');

        $data['em']->clear();
        $stillThere = $data['em']->find(QuarantinedDmarcReport::class, $quarantineId);
        self::assertNull($stillThere);
    }

    public function testReprocessReturns404ForCrossTenantRow(): void
    {
        $data = $this->bootClientWithQuarantinedReport(QuarantineReason::UnverifiedDomain);
        $token = $this->csrfToken($data['client'], 'quarantine_reprocess');

        // Forge a foreign quarantine row.
        $em = $data['em'];
        $foreignTeam = new Team(
            id: Uuid::uuid7(),
            name: 'Foreign R',
            slug: 'foreign-r-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $foreignTeam->popEvents();
        $em->persist($foreignTeam);

        $foreignDomainName = 'fr-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';
        $foreignDomain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $foreignTeam,
            domain: $foreignDomainName,
            createdAt: new \DateTimeImmutable(),
        );
        $foreignDomain->popEvents();
        $em->persist($foreignDomain);

        $foreignEnvelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::CentralInbox,
            messageId: '<fr-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'x@y',
            subject: 'x',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 1,
            rawEml: 'x',
        );
        $em->persist($foreignEnvelope);

        $compressed = gzencode('<feedback/>');
        assert(false !== $compressed);

        $foreignQuarantine = new QuarantinedDmarcReport(
            id: Uuid::uuid7(),
            receivedEmail: $foreignEnvelope,
            domainName: $foreignDomainName,
            externalReportId: 'ext',
            reporterOrg: 'g',
            reporterEmail: 'g@g',
            dateRangeBegin: new \DateTimeImmutable('@1700000000'),
            dateRangeEnd: new \DateTimeImmutable('@1700086400'),
            quarantinedAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+30 days'),
            reason: QuarantineReason::UnverifiedDomain,
            reportXmlGz: $compressed,
        );
        $em->persist($foreignQuarantine);
        $em->flush();

        $data['client']->request('POST', '/app/quarantine/'.$foreignQuarantine->id->toString().'/reprocess', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAddDomainWithoutCsrfReturns403(): void
    {
        // We use the default helper (matching monitored_domain present) so
        // the team passes the onboarding-redirect gate. The CSRF check is
        // the first thing the controller does, so any well-formed POST
        // without a token returns 403 regardless of the row's visibility.
        $data = $this->bootClientWithQuarantinedReport(QuarantineReason::UnknownDomain);

        $data['client']->request('POST', '/app/quarantine/'.$data['quarantine']->id->toString().'/add-domain');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAddDomainHappyPathCreatesDomainAndRedirects(): void
    {
        // For UnknownDomain we need the team to be able to *see* the row
        // first — which per our team-scoping requires a monitored_domain
        // matching the quarantined domain_name. The helper creates that.
        $data = $this->bootClientWithQuarantinedReport(QuarantineReason::UnknownDomain);
        // The architect plan's race-handling branch covers this case: the
        // team already owns the domain, so the controller skips AddDomain
        // and just dispatches ReleaseQuarantinedReportsForDomain.
        $token = $this->csrfToken($data['client'], 'quarantine_add_domain');

        $data['client']->request('POST', '/app/quarantine/'.$data['quarantine']->id->toString().'/add-domain', [
            '_csrf_token' => $token,
        ]);

        $response = $data['client']->getResponse();
        self::assertResponseRedirects();
        self::assertNotNull($data['domain']);
        // Team already owned the matching MonitoredDomain (via helper), so
        // the controller hits the race-handling branch and redirects to that
        // domain's detail page rather than the newly-created one.
        self::assertSame(
            '/app/domains/'.$data['domain']->id->toString(),
            $response->headers->get('Location'),
        );

        $data['em']->clear();
        // The release handler runs synchronously, dispatches one
        // ProcessDmarcReport per quarantine row and removes the row.
        $stillThere = $data['em']->find(QuarantinedDmarcReport::class, $data['quarantine']->id);
        self::assertNull($stillThere);
    }

    public function testAddDomainCreatesNewMonitoredDomainWhenTeamDoesNotOwnIt(): void
    {
        // The realistic "fresh add-domain" path: the team has NO matching
        // monitored_domain yet — visibility comes purely from owning the
        // mailbox that received the report. The controller must dispatch
        // AddDomain (creating a brand-new MonitoredDomain) AND
        // ReleaseQuarantinedReportsForDomain so the parked report flows in.
        // Upgrade to the `personal` plan so the placeholder domain (added by
        // the helper to satisfy the onboarding gate) doesn't exhaust the
        // free-plan single-domain quota — otherwise the controller would hit
        // the plan-limit guard and redirect back to the quarantine detail
        // instead of dispatching AddDomain.
        $data = $this->bootClientWithQuarantinedReport(
            QuarantineReason::UnknownDomain,
            createMonitoredDomain: false,
            createTeamMailbox: true,
            teamPlan: 'personal',
        );
        self::assertNull($data['domain']);

        $token = $this->csrfToken($data['client'], 'quarantine_add_domain');

        $data['client']->request('POST', '/app/quarantine/'.$data['quarantine']->id->toString().'/add-domain', [
            '_csrf_token' => $token,
        ]);

        $response = $data['client']->getResponse();
        self::assertResponseRedirects();

        $location = $response->headers->get('Location');
        self::assertIsString($location);
        self::assertMatchesRegularExpression('#^/app/domains/[0-9a-f-]{36}$#', $location);

        $data['em']->clear();

        // The freshly-created MonitoredDomain row exists for this team and is
        // referenced by the redirect target.
        $createdId = basename($location);
        $created = $data['em']->find(MonitoredDomain::class, Uuid::fromString($createdId));
        self::assertNotNull($created);
        self::assertSame($data['domainName'], $created->domain);
        self::assertTrue($created->team->id->equals($data['team']->id));

        // The chained ReleaseQuarantinedReportsForDomain ran synchronously and
        // removed the quarantine row.
        $stillThere = $data['em']->find(QuarantinedDmarcReport::class, $data['quarantine']->id);
        self::assertNull($stillThere);
    }

    public function testListShowsRowVisibleOnlyViaTeamMailbox(): void
    {
        // No matching MonitoredDomain — visibility must come entirely from
        // mailbox ownership. The list page should still surface the row so
        // the user can act on it.
        $data = $this->bootClientWithQuarantinedReport(
            QuarantineReason::UnknownDomain,
            createMonitoredDomain: false,
            createTeamMailbox: true,
        );

        $data['client']->request('GET', '/app/quarantine');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $data['domainName']);
        self::assertSelectorTextContains('body', 'Unknown domain');
    }

    public function testDetailRendersForRowVisibleOnlyViaTeamMailbox(): void
    {
        $data = $this->bootClientWithQuarantinedReport(
            QuarantineReason::UnknownDomain,
            createMonitoredDomain: false,
            createTeamMailbox: true,
        );

        $data['client']->request('GET', '/app/quarantine/'.$data['quarantine']->id->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $data['domainName']);
        // The "Add this domain" CTA must be available since the reason is
        // UnknownDomain.
        self::assertSelectorTextContains('body', 'Add '.$data['domainName']);
    }

    public function testAddDomainReturns404WhenReasonIsNotUnknownDomain(): void
    {
        $data = $this->bootClientWithQuarantinedReport(QuarantineReason::PlanOverage);
        $token = $this->csrfToken($data['client'], 'quarantine_add_domain');

        $data['client']->request('POST', '/app/quarantine/'.$data['quarantine']->id->toString().'/add-domain', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAddDomainReturns404ForCrossTenantRow(): void
    {
        $data = $this->bootClientWithQuarantinedReport(QuarantineReason::UnknownDomain);
        $token = $this->csrfToken($data['client'], 'quarantine_add_domain');

        // Quarantine row that lives under a different team's domain.
        $em = $data['em'];
        $foreignTeam = new Team(
            id: Uuid::uuid7(),
            name: 'Foreign A',
            slug: 'foreign-a-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $foreignTeam->popEvents();
        $em->persist($foreignTeam);

        $foreignDomainName = 'fa-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';
        $foreignDomain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $foreignTeam,
            domain: $foreignDomainName,
            createdAt: new \DateTimeImmutable(),
        );
        $foreignDomain->popEvents();
        $em->persist($foreignDomain);

        $foreignEnvelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::CentralInbox,
            messageId: '<fa-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'x@y',
            subject: 'x',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 1,
            rawEml: 'x',
        );
        $em->persist($foreignEnvelope);

        $compressed = gzencode('<feedback/>');
        assert(false !== $compressed);

        $foreignQuarantine = new QuarantinedDmarcReport(
            id: Uuid::uuid7(),
            receivedEmail: $foreignEnvelope,
            domainName: $foreignDomainName,
            externalReportId: 'ext',
            reporterOrg: 'g',
            reporterEmail: 'g@g',
            dateRangeBegin: new \DateTimeImmutable('@1700000000'),
            dateRangeEnd: new \DateTimeImmutable('@1700086400'),
            quarantinedAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+30 days'),
            reason: QuarantineReason::UnknownDomain,
            reportXmlGz: $compressed,
        );
        $em->persist($foreignQuarantine);
        $em->flush();

        $data['client']->request('POST', '/app/quarantine/'.$foreignQuarantine->id->toString().'/add-domain', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testSidebarShowsBadgeWhenQuarantineHasRows(): void
    {
        $data = $this->bootClientWithQuarantinedReport(QuarantineReason::UnverifiedDomain);

        $data['client']->request('GET', '/app/quarantine');

        self::assertResponseIsSuccessful();
        // The badge contains exactly "1" (one row seeded).
        self::assertSelectorTextContains('.badge.badge-warning', '1');
    }

    public function testSidebarOmitsBadgeWhenQuarantineEmpty(): void
    {
        $client = $this->createEmptyAuthenticatedClient();

        $client->request('GET', '/app/quarantine');

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        // No sidebar badge — the Quarantine link itself is still there.
        self::assertStringContainsString('Quarantine', $content);
        self::assertStringNotContainsString('badge badge-xs badge-warning ml-auto', $content);
    }

    public function testQuarantineRouteRequiresAuthentication(): void
    {
        $client = self::createClient();

        $client->request('GET', '/app/quarantine');

        self::assertResponseRedirects();
    }
}
