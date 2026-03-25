<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\DomainHealthScore;
use App\Value\Dns\HealthCategory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DomainHealthScoreTest extends TestCase
{
    #[Test]
    public function grade_color_returns_correct_class(): void
    {
        self::assertSame('text-success', (new DomainHealthScore('A', 95, []))->gradeColor());
        self::assertSame('text-info', (new DomainHealthScore('B', 80, []))->gradeColor());
        self::assertSame('text-warning', (new DomainHealthScore('C', 60, []))->gradeColor());
        self::assertSame('text-error', (new DomainHealthScore('D', 40, []))->gradeColor());
        self::assertSame('text-error', (new DomainHealthScore('F', 10, []))->gradeColor());
    }

    #[Test]
    public function health_category_is_constructed(): void
    {
        $cat = new HealthCategory('SPF', 85, 'pass');

        self::assertSame('SPF', $cat->name);
        self::assertSame(85, $cat->score);
        self::assertSame('pass', $cat->status);
    }
}
