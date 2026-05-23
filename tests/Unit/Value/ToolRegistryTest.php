<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Services\OgImage\ToolOgImageContentResolver;
use App\Value\ToolRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    #[Test]
    public function everyRegisteredSlugResolvesThroughContentResolver(): void
    {
        // Catches registry/template drift — every slug used in the
        // `og_image` block overrides in templates/tools/* must map to
        // a resolvable OgImageContent.
        $resolver = new ToolOgImageContentResolver();
        foreach (ToolRegistry::TOOLS as $tool) {
            $content = $resolver->resolve($tool['slug']);
            self::assertSame($tool['title'], $content->title);
            self::assertSame($tool['category'], $content->badgeText);
        }
    }

    #[Test]
    public function slugsAreUrlSafeAtRouterLevel(): void
    {
        foreach (ToolRegistry::TOOLS as $tool) {
            self::assertMatchesRegularExpression('/\A[a-zA-Z0-9_-]+\z/', $tool['slug']);
        }
    }

    #[Test]
    public function exposesEntriesForEveryShippedToolPage(): void
    {
        $slugs = array_map(static fn (array $tool): string => $tool['slug'], ToolRegistry::TOOLS);

        self::assertSame(
            [
                'dmarc-checker',
                'spf-checker',
                'dkim-checker',
                'mx-checker',
                'email-auth-checker',
                'domain-health',
                'blacklist-checker',
                'dns-monitoring',
            ],
            $slugs,
        );
    }

    #[Test]
    public function everyEntryHasTitleAndCategory(): void
    {
        foreach (ToolRegistry::TOOLS as $tool) {
            self::assertArrayHasKey('title', $tool);
            self::assertArrayHasKey('category', $tool);
            self::assertGreaterThan(0, strlen($tool['title']));
            self::assertGreaterThan(0, strlen($tool['category']));
        }
    }
}
