<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

final class TeamFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->hasField('team') && !$targetEntity->hasAssociation('team')) {
            return '';
        }

        try {
            $teamId = $this->getParameter('team_id');
        } catch (\InvalidArgumentException) {
            return '';
        }

        $columnName = $targetEntity->hasAssociation('team')
            ? $targetEntity->getSingleAssociationJoinColumnName('team')
            : $targetEntity->getColumnName('team');

        return sprintf('%s.%s = %s', $targetTableAlias, $columnName, $teamId);
    }
}
