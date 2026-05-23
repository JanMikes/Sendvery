<?php

declare(strict_types=1);

namespace App\Services\OgImage;

use App\Exceptions\OgImageContentNotFoundException;
use App\Query\GetDomainHealthHistory;
use App\Value\OgImageContent;
use Psr\Log\LoggerInterface;

final readonly class HealthOgImageContentResolver
{
    public function __construct(
        private GetDomainHealthHistory $getDomainHealthHistory,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(string $slug): OgImageContent
    {
        $snapshot = $this->getDomainHealthHistory->findByShareHash($slug);

        if (null === $snapshot) {
            throw new OgImageContentNotFoundException(sprintf('Unknown health share hash "%s".', $slug));
        }

        $domain = $this->getDomainHealthHistory->getDomainNameByShareHash($slug);
        if (null === $domain) {
            // Data-integrity escape hatch — the snapshot existed but the
            // joined monitored_domain row didn't. Should be impossible
            // given the FK, but if it happens, log and degrade rather
            // than break the social card.
            $this->logger->warning('OG health card: domain name lookup returned null', ['share_hash' => $slug]);
        }
        $domainName = $domain['domain_name'] ?? 'Domain';

        [$r, $g, $b] = self::gradeRgb($snapshot->grade);

        return new OgImageContent(
            title: $domainName,
            subtitle: 'Domain Health Score: '.$snapshot->score.'/100',
            badgeText: 'Grade '.$snapshot->grade,
            badgeRgbR: $r,
            badgeRgbG: $g,
            badgeRgbB: $b,
        );
    }

    /**
     * @return array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    private static function gradeRgb(string $grade): array
    {
        return match ($grade) {
            'A' => [22, 163, 74],   // green-600
            'B' => [37, 99, 235],   // blue-600
            'C' => [217, 119, 6],   // amber-600
            default => [220, 38, 38], // red-600 — D, F, anything unexpected
        };
    }
}
