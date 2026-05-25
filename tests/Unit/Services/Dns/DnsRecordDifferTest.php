<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Results\Dns\DnsRecordDiff;
use App\Results\Dns\DnsRecordDiffSegment;
use App\Services\Dns\DnsRecordDiffer;
use App\Value\Dns\DnsRecordDiffKind;
use App\Value\DnsCheckType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the token-level diff grammar for {@see DnsRecordDiffer} (TASK-127).
 *
 * Each test exercises ONE protocol because the differ branches per
 * `DnsCheckType` and the tokenisation rules differ — SPF is whitespace,
 * DMARC is `;`-delimited key=value, DKIM is one opaque block, MX is
 * line-delimited priority+value rows.
 */
final class DnsRecordDifferTest extends TestCase
{
    private function makeDiffer(): DnsRecordDiffer
    {
        return new DnsRecordDiffer();
    }

    /**
     * Collapse the segment list to a compact `[kind:text, …]` representation
     * for readable assertions. The full DTO equality would be noisy — kind
     * + text is the contract callers care about.
     *
     * @return list<string>
     */
    private function summarize(DnsRecordDiff $diff): array
    {
        return array_map(
            static fn (DnsRecordDiffSegment $s): string => sprintf('%s:%s', $s->kind->value, $s->text),
            $diff->segments,
        );
    }

    #[Test]
    public function dmarcPolicyFlipMarksOldRemovedAndNewAdded(): void
    {
        $diff = $this->makeDiffer()->diff(
            DnsCheckType::Dmarc,
            'v=DMARC1; p=none; rua=mailto:reports@example.com',
            'v=DMARC1; p=quarantine; rua=mailto:reports@example.com',
        );

        // After compaction, adjacent same-kind segments merge — so the leading
        // `v=DMARC1` collapses with its trailing separator into one unchanged
        // run, and similarly `; rua=…` collapses with its leading separator.
        // What matters is: `p=none` is a standalone Removed segment and
        // `p=quarantine` is a standalone Added segment, in that order.
        self::assertSame(
            [
                'unchanged:v=DMARC1; ',
                'removed:p=none',
                'unchanged:; ',
                'added:p=quarantine',
                'unchanged:; rua=mailto:reports@example.com',
            ],
            $this->summarize($diff),
        );
        self::assertTrue($diff->hasChanges());
        self::assertSame('v=DMARC1; p=none; rua=mailto:reports@example.com', $diff->previousRecord);
        self::assertSame('v=DMARC1; p=quarantine; rua=mailto:reports@example.com', $diff->currentRecord);
    }

    #[Test]
    public function dmarcAddedTagRendersAsAddedOnly(): void
    {
        $diff = $this->makeDiffer()->diff(
            DnsCheckType::Dmarc,
            'v=DMARC1; p=quarantine',
            'v=DMARC1; p=quarantine; pct=50',
        );

        self::assertSame(
            [
                'unchanged:v=DMARC1; p=quarantine; ',
                'added:pct=50',
            ],
            $this->summarize($diff),
        );
        self::assertTrue($diff->hasChanges());
    }

    #[Test]
    public function dmarcIdenticalRecordHasNoChanges(): void
    {
        $diff = $this->makeDiffer()->diff(
            DnsCheckType::Dmarc,
            'v=DMARC1; p=reject',
            'v=DMARC1; p=reject',
        );

        self::assertSame(
            ['unchanged:v=DMARC1; p=reject'],
            $this->summarize($diff),
        );
        self::assertFalse($diff->hasChanges());
    }

    #[Test]
    public function spfAddedIncludeRendersAsAddedToken(): void
    {
        $diff = $this->makeDiffer()->diff(
            DnsCheckType::Spf,
            'v=spf1 include:_spf.google.com ~all',
            'v=spf1 include:_spf.google.com include:spf.mandrillapp.com ~all',
        );

        self::assertSame(
            [
                'unchanged:v=spf1 include:_spf.google.com ~all ',
                'added:include:spf.mandrillapp.com',
            ],
            $this->summarize($diff),
        );
        self::assertTrue($diff->hasChanges());
    }

    #[Test]
    public function spfRemovedIp4TokenIsMarkedRemoved(): void
    {
        $diff = $this->makeDiffer()->diff(
            DnsCheckType::Spf,
            'v=spf1 ip4:192.0.2.0/24 include:_spf.google.com ~all',
            'v=spf1 include:_spf.google.com ~all',
        );

        self::assertSame(
            [
                'unchanged:v=spf1 ',
                'removed:ip4:192.0.2.0/24',
                'unchanged: include:_spf.google.com ~all',
            ],
            $this->summarize($diff),
        );
        self::assertTrue($diff->hasChanges());
    }

    #[Test]
    public function spfPolicyFlipShowsBothTokens(): void
    {
        // `~all` → `-all` is one of the most consequential SPF changes — the
        // differ must render both inline (removed soft-fail, added hard-fail).
        $diff = $this->makeDiffer()->diff(
            DnsCheckType::Spf,
            'v=spf1 include:_spf.google.com ~all',
            'v=spf1 include:_spf.google.com -all',
        );

        self::assertSame(
            [
                'unchanged:v=spf1 include:_spf.google.com ',
                'removed:~all',
                'unchanged: ',
                'added:-all',
            ],
            $this->summarize($diff),
        );
    }

    #[Test]
    public function dkimWholeRecordReplacedWhenKeyRotates(): void
    {
        // DKIM is opaque on purpose — diffing inside `p=<base64>` would be
        // pointless noise. Any change rotates the whole record.
        $diff = $this->makeDiffer()->diff(
            DnsCheckType::Dkim,
            'v=DKIM1; k=rsa; p=AAAAB3NzaC1yc2EAAAADAQABAAABAQ',
            'v=DKIM1; k=rsa; p=ZZZZB3NzaC1yc2EAAAADAQABAAABAQ',
        );

        self::assertSame(
            [
                'removed:v=DKIM1; k=rsa; p=AAAAB3NzaC1yc2EAAAADAQABAAABAQ',
                'unchanged: ',
                'added:v=DKIM1; k=rsa; p=ZZZZB3NzaC1yc2EAAAADAQABAAABAQ',
            ],
            $this->summarize($diff),
        );
        self::assertTrue($diff->hasChanges());
    }

    #[Test]
    public function dkimIdenticalRecordRendersAsSingleUnchangedBlock(): void
    {
        $record = 'v=DKIM1; k=rsa; p=AAAAB3NzaC1yc2EAAAADAQABAAABAQ';
        $diff = $this->makeDiffer()->diff(DnsCheckType::Dkim, $record, $record);

        self::assertSame(
            [sprintf('unchanged:%s', $record)],
            $this->summarize($diff),
        );
        self::assertFalse($diff->hasChanges());
    }

    #[Test]
    public function mxPriorityChangeMarksOldLineRemovedAndNewLineAdded(): void
    {
        $diff = $this->makeDiffer()->diff(
            DnsCheckType::Mx,
            "10 aspmx.l.google.com\n20 alt1.aspmx.l.google.com",
            "5 aspmx.l.google.com\n20 alt1.aspmx.l.google.com",
        );

        self::assertSame(
            [
                'removed:10 aspmx.l.google.com',
                "unchanged:\n20 alt1.aspmx.l.google.com\n",
                'added:5 aspmx.l.google.com',
            ],
            $this->summarize($diff),
        );
        self::assertTrue($diff->hasChanges());
    }

    #[Test]
    public function nullPreviousRecordTreatedAsEmptyAndAllTokensAdded(): void
    {
        // Defensive: dns_check_result.previous_raw_record is nullable when the
        // baseline observation had no prior. The differ must NOT crash on null.
        $diff = $this->makeDiffer()->diff(
            DnsCheckType::Dmarc,
            null,
            'v=DMARC1; p=quarantine',
        );

        self::assertSame(
            [
                'added:v=DMARC1',
                'unchanged:; ',
                'added:p=quarantine',
            ],
            $this->summarize($diff),
        );
        self::assertTrue($diff->hasChanges());
        self::assertSame('', $diff->previousRecord);
    }

    #[Test]
    public function nullCurrentRecordTreatedAsEmptyAndAllTokensRemoved(): void
    {
        // Mirror case: the record was deleted from DNS entirely.
        $diff = $this->makeDiffer()->diff(
            DnsCheckType::Spf,
            'v=spf1 -all',
            null,
        );

        // The unchanged separator between the two removed tokens stays in the
        // middle (compaction only merges adjacent SAME-kind segments), so the
        // render shows the whole record struck through with a literal gap.
        self::assertSame(
            [
                'removed:v=spf1',
                'unchanged: ',
                'removed:-all',
            ],
            $this->summarize($diff),
        );
        self::assertTrue($diff->hasChanges());
        self::assertSame('', $diff->currentRecord);
    }

    #[Test]
    public function diffKindEnumExposesExpectedValues(): void
    {
        // Locks the enum values referenced by the Twig macro (TASK-127).
        // The template uses `segment.kind.value == 'added'/'removed'`; a
        // rename here would silently break the highlight rendering.
        self::assertSame('unchanged', DnsRecordDiffKind::Unchanged->value);
        self::assertSame('added', DnsRecordDiffKind::Added->value);
        self::assertSame('removed', DnsRecordDiffKind::Removed->value);
    }

    #[Test]
    public function emptyToEmptyProducesNoSegments(): void
    {
        // Pathological case — both sides are missing. Defensive against a
        // controller wiring bug that calls the differ when neither side has
        // a record at all.
        $diff = $this->makeDiffer()->diff(DnsCheckType::Dmarc, null, null);

        self::assertSame([], $diff->segments);
        self::assertFalse($diff->hasChanges());
    }
}
