<?php

declare(strict_types=1);

namespace App\Value;

enum FeedbackType: string
{
    case Bug = 'bug';
    case FeatureRequest = 'feature_request';
    case General = 'general';
}
