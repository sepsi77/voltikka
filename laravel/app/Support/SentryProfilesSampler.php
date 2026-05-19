<?php

namespace App\Support;

use Sentry\Tracing\SamplingContext;

class SentryProfilesSampler
{
    public static function sample(SamplingContext $context): ?float
    {
        if (app()->runningInConsole() && ! (bool) env('SENTRY_PROFILE_CONSOLE_ENABLED', false)) {
            return 0.0;
        }

        $transactionContext = $context->getTransactionContext();
        if ($transactionContext?->getOp() === 'queue.process' && ! (bool) env('SENTRY_PROFILE_QUEUE_ENABLED', false)) {
            return 0.0;
        }

        return env('SENTRY_PROFILES_SAMPLE_RATE') === null
            ? null
            : (float) env('SENTRY_PROFILES_SAMPLE_RATE');
    }
}
