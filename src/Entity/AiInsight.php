<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\AiInsightType;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

/**
 * A cached AI insight. Reports are immutable, so a generated explanation is
 * valid forever — caching it means re-views cost nothing and burn no quota
 * (the cache decorator sits OUTSIDE the plan/quota gate). `cache_key` is the
 * dedupe identity; the unique index is the race backstop.
 *
 * Emits no domain events, so it does not implement EntityWithEvents. All
 * properties are readonly — an insight is written once and never mutated.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_insight')]
#[ORM\UniqueConstraint(name: 'uniq_ai_insight_cache_key', columns: ['cache_key'])]
#[ORM\Index(name: 'idx_ai_insight_team', columns: ['team_id'])]
#[ORM\Index(name: 'idx_ai_insight_type_subject', columns: ['type', 'subject_id'])]
final class AiInsight
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    /**
     * Nullable because `labelSender` insights are global (IP+domain), not
     * team-scoped. CASCADE so a deleted team takes its insights with it.
     */
    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: 'team_id', nullable: true, onDelete: 'CASCADE')]
    public readonly ?Team $team;

    #[ORM\Column(type: 'string', enumType: AiInsightType::class)]
    public readonly AiInsightType $type;

    #[ORM\Column(length: 64)]
    public readonly string $subjectId;

    #[ORM\Column(length: 255)]
    public readonly string $cacheKey;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public readonly array $content;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $content
     */
    public function __construct(
        UuidInterface $id,
        ?Team $team,
        AiInsightType $type,
        string $subjectId,
        string $cacheKey,
        array $content,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->team = $team;
        $this->type = $type;
        $this->subjectId = $subjectId;
        $this->cacheKey = $cacheKey;
        $this->content = $content;
        $this->createdAt = $createdAt;
    }
}
