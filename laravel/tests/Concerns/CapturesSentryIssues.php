<?php

namespace Tests\Concerns;

use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;

trait CapturesSentryIssues
{
    /** @var list<Event> */
    private array $sentryIssues = [];

    private function captureSentryIssues(): void
    {
        $previous = SentrySdk::getCurrentHub();
        $client = ClientBuilder::create([
            'dsn' => 'https://public@example.com/1',
            'default_integrations' => false,
            'before_send' => function (Event $event): ?Event {
                $this->sentryIssues[] = $event;

                return null; // Exercise the real SDK without sending network traffic.
            },
        ])->getClient();
        SentrySdk::setCurrentHub(new Hub($client));
        $this->beforeApplicationDestroyed(fn () => SentrySdk::setCurrentHub($previous));
    }

    private function assertImportIssue(string $import, string $level, array $failures): void
    {
        $this->assertCount(1, $this->sentryIssues);
        $event = $this->sentryIssues[0];
        $this->assertSame(['data-fetch-failure', $import], $event->getFingerprint());
        $this->assertSame($level, (string) $event->getLevel());
        $this->assertSame($failures, $event->getContexts()['data_fetch']['failures']);
        $this->assertSame([], $event->getExceptions());
    }
}
