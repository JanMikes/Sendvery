<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Reduces a PTR hostname to the domain somebody actually registered —
 * `mxb-2-904.seznam.cz` to `seznam.cz`, `eu.cloud-sec-av.com` to
 * `cloud-sec-av.com` (DEC-059 §3.2).
 *
 * This is the identity key for a sender, and it deliberately works with no
 * curated mapping at all: neither `cloud-sec-av.com` nor `inkyphishfence.com`
 * appears in OrganizationMapper's list, and no hardcoded list will ever be
 * complete. Fifteen rotating Seznam addresses collapse to one sender purely
 * because they share a registrable domain.
 *
 * Limitation, accepted deliberately: this uses a small embedded public-suffix
 * list rather than pulling in a full Public Suffix List dependency. It covers
 * the two-label ccTLD suffixes that mail infrastructure realistically uses
 * (.co.uk, .com.au, .co.jp, …). An unlisted multi-label suffix degrades to the
 * last two labels — so a host under an exotic suffix groups by
 * `something.<suffix-tail>` instead of its true registrable domain. That is a
 * slightly coarser grouping, never a wrong identity: two different registrants
 * under such a suffix would merge, which is a visible cosmetic problem rather
 * than a silent security one. Add entries here when a real case appears.
 */
final readonly class RegistrableDomainExtractor
{
    /**
     * Public suffixes made of more than one label, as a set for O(1) lookup.
     *
     * @var array<string, true>
     */
    private const array MULTI_LABEL_SUFFIXES = [
        // United Kingdom
        'co.uk' => true, 'org.uk' => true, 'me.uk' => true, 'ltd.uk' => true,
        'plc.uk' => true, 'net.uk' => true, 'sch.uk' => true, 'ac.uk' => true,
        'gov.uk' => true, 'nhs.uk' => true, 'police.uk' => true, 'mod.uk' => true,
        // Australia
        'com.au' => true, 'net.au' => true, 'org.au' => true, 'edu.au' => true,
        'gov.au' => true, 'asn.au' => true, 'id.au' => true,
        // New Zealand
        'co.nz' => true, 'net.nz' => true, 'org.nz' => true, 'govt.nz' => true,
        'ac.nz' => true, 'school.nz' => true, 'geek.nz' => true,
        // South Africa
        'co.za' => true, 'org.za' => true, 'net.za' => true, 'web.za' => true,
        'gov.za' => true, 'ac.za' => true,
        // Brazil
        'com.br' => true, 'net.br' => true, 'org.br' => true, 'gov.br' => true,
        'edu.br' => true,
        // Japan
        'co.jp' => true, 'ne.jp' => true, 'or.jp' => true, 'ac.jp' => true,
        'go.jp' => true, 'ad.jp' => true, 'ed.jp' => true, 'gr.jp' => true,
        'lg.jp' => true,
        // South Korea
        'co.kr' => true, 'ne.kr' => true, 'or.kr' => true, 're.kr' => true,
        'go.kr' => true, 'ac.kr' => true, 'pe.kr' => true,
        // China
        'com.cn' => true, 'net.cn' => true, 'org.cn' => true, 'gov.cn' => true,
        'edu.cn' => true, 'ac.cn' => true,
        // India
        'co.in' => true, 'net.in' => true, 'org.in' => true, 'gen.in' => true,
        'firm.in' => true, 'ind.in' => true, 'gov.in' => true, 'ac.in' => true,
        'edu.in' => true,
        // Latin America
        'com.mx' => true, 'org.mx' => true, 'net.mx' => true, 'gob.mx' => true,
        'edu.mx' => true, 'com.ar' => true, 'net.ar' => true, 'org.ar' => true,
        'gob.ar' => true, 'edu.ar' => true, 'com.co' => true, 'net.co' => true,
        'com.pe' => true, 'com.ec' => true, 'com.uy' => true, 'com.ve' => true,
        // Europe
        'com.tr' => true, 'net.tr' => true, 'org.tr' => true, 'gov.tr' => true,
        'edu.tr' => true, 'com.ua' => true, 'net.ua' => true, 'org.ua' => true,
        'gov.ua' => true, 'edu.ua' => true, 'in.ua' => true, 'kiev.ua' => true,
        'com.ru' => true, 'net.ru' => true, 'org.ru' => true, 'com.pl' => true,
        'net.pl' => true, 'org.pl' => true, 'gov.pl' => true, 'edu.pl' => true,
        'com.es' => true, 'org.es' => true, 'nom.es' => true, 'gob.es' => true,
        'edu.es' => true, 'com.gr' => true, 'net.gr' => true, 'org.gr' => true,
        'co.at' => true, 'or.at' => true, 'ac.at' => true, 'gv.at' => true,
        'co.hu' => true, 'com.cy' => true, 'com.pt' => true, 'com.ro' => true,
        // Middle East, Africa, Asia-Pacific
        'co.il' => true, 'net.il' => true, 'org.il' => true, 'gov.il' => true,
        'ac.il' => true, 'co.id' => true, 'net.id' => true, 'or.id' => true,
        'web.id' => true, 'go.id' => true, 'ac.id' => true, 'sch.id' => true,
        'co.th' => true, 'in.th' => true, 'go.th' => true, 'ac.th' => true,
        'net.th' => true, 'or.th' => true, 'com.vn' => true, 'net.vn' => true,
        'org.vn' => true, 'gov.vn' => true, 'edu.vn' => true, 'com.ph' => true,
        'net.ph' => true, 'org.ph' => true, 'gov.ph' => true, 'edu.ph' => true,
        'com.sg' => true, 'net.sg' => true, 'org.sg' => true, 'gov.sg' => true,
        'edu.sg' => true, 'com.hk' => true, 'net.hk' => true, 'org.hk' => true,
        'gov.hk' => true, 'edu.hk' => true, 'idv.hk' => true, 'com.tw' => true,
        'net.tw' => true, 'org.tw' => true, 'gov.tw' => true, 'edu.tw' => true,
        'com.my' => true, 'net.my' => true, 'org.my' => true, 'gov.my' => true,
        'edu.my' => true, 'co.ke' => true, 'com.ng' => true, 'com.pk' => true,
        'com.bd' => true, 'com.sa' => true, 'com.eg' => true, 'co.ma' => true,
    ];

    /**
     * Longest suffix we will consider. Every entry above is two labels; the
     * loop starts one higher so a future three-label entry works without any
     * other change.
     */
    private const int MAX_SUFFIX_LABELS = 3;

    public function extract(string $hostname): ?string
    {
        $normalized = trim(strtolower(trim($hostname)), '.');

        if ('' === $normalized) {
            return null;
        }

        // Reject anything that is not a plain hostname. Reverse DNS answers are
        // attacker-influenceable in principle (whoever controls the PTR zone
        // writes them), and this value becomes a grouping key and a display
        // label, so garbage never gets past here.
        if (1 !== preg_match('/^[a-z0-9][a-z0-9.-]*$/', $normalized)) {
            return null;
        }

        // An IP literal is not a hostname; gethostbyaddr() echoing the address
        // back is the usual source of these.
        if (false !== filter_var($normalized, FILTER_VALIDATE_IP)) {
            return null;
        }

        // A name that is itself a public suffix has no registrable part.
        if (isset(self::MULTI_LABEL_SUFFIXES[$normalized])) {
            return null;
        }

        $labels = explode('.', $normalized);

        if (in_array('', $labels, true) || count($labels) < 2) {
            return null;
        }

        // Leave at least one label for the registered name itself.
        $maxSuffixLabels = min(self::MAX_SUFFIX_LABELS, count($labels) - 1);

        for ($suffixLabels = $maxSuffixLabels; $suffixLabels >= 2; --$suffixLabels) {
            $candidate = implode('.', array_slice($labels, -$suffixLabels));

            if (isset(self::MULTI_LABEL_SUFFIXES[$candidate])) {
                return implode('.', array_slice($labels, -($suffixLabels + 1)));
            }
        }

        return implode('.', array_slice($labels, -2));
    }
}
