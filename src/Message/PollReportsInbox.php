<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Tells the worker to pull a batch of new emails from the central
 * reports@sendvery.com inbox. Dispatched by the every-5-minutes cron.
 */
final readonly class PollReportsInbox
{
    public function __construct()
    {
    }
}
