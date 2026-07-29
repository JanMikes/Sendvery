<?php

declare(strict_types=1);

namespace App\Twig;

use App\Value\BlocklistRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes blocklist metadata to Twig so an alert can say "Spamhaus ZEN —
 * queried by mailbox providers when accepting mail" with a delisting link,
 * instead of rendering `zen.spamhaus.org` as an anonymous badge.
 */
final class BlocklistExtension extends AbstractExtension
{
    public function __construct(
        private readonly BlocklistRegistry $blocklists,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('blocklist_name', $this->blocklists->name(...)),
            new TwigFunction('blocklist_blocks_delivery', $this->blocklists->blocksDelivery(...)),
            new TwigFunction('blocklist_delist_url', $this->blocklists->delistUrl(...)),
        ];
    }
}
