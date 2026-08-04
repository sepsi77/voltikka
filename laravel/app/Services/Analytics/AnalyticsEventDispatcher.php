<?php

namespace App\Services\Analytics;

final class AnalyticsEventDispatcher
{
    public function __construct(
        private readonly ContractOrderClickHandler $contractOrderClickHandler,
    ) {}

    /** @param array<string, mixed> $envelope */
    public function dispatch(AnalyticsEventName $eventName, array $envelope): void
    {
        match ($eventName) {
            AnalyticsEventName::ContractOrderClick => $this->contractOrderClickHandler->handle($envelope),
        };
    }
}
