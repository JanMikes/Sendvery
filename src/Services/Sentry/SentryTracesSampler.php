<?php

declare(strict_types=1);

namespace App\Services\Sentry;

use Sentry\Tracing\SamplingContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Sentry traces_sampler with on-demand profiling activation.
 *
 * Default sample rate is low / zero (configured via SENTRY_TRACES_SAMPLE_RATE). Hitting any URL
 * with `?_profile=<SENTRY_PROFILING_SECRET>` flips the sampler to 1.0 for the current session,
 * letting us profile a real user flow in production without raising the global rate. `?_profile=off`
 * turns it back off. Parent-sampled traces (incoming distributed tracing) are always honored.
 */
final readonly class SentryTracesSampler
{
    public function __construct(
        private string $profilingSecret,
        private float $defaultTracesSampleRate,
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(): \Closure
    {
        return function (SamplingContext $context): float {
            $request = $this->requestStack->getCurrentRequest();

            if (null === $request) {
                return $this->defaultTracesSampleRate;
            }

            $queryValue = $request->query->get('_profile');
            $session = $request->hasSession() ? $request->getSession() : null;

            if ('' !== $this->profilingSecret && $queryValue === $this->profilingSecret) {
                $session?->set('_sentry_profiler_enabled', $this->profilingSecret);

                return 1.0;
            }

            if ('off' === $queryValue) {
                $session?->remove('_sentry_profiler_enabled');

                return $this->defaultTracesSampleRate;
            }

            if ('' !== $this->profilingSecret && $session?->get('_sentry_profiler_enabled') === $this->profilingSecret) {
                return 1.0;
            }

            if (true === $context->getParentSampled()) {
                return 1.0;
            }

            return $this->defaultTracesSampleRate;
        };
    }
}
