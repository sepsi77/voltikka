<?php

namespace Tests\Unit;

use App\Services\Caching\ContractPriceCacheConflict;
use App\Services\Caching\ContractPriceCacheStorageException;
use App\Services\ContractImport\ContractImportCompletionStopped;
use App\Support\DataFetchFailureReporter;
use Illuminate\Support\Facades\Log;
use Sentry\State\Scope;
use Tests\Concerns\CapturesSentryIssues;
use Tests\TestCase;

use function Sentry\captureMessage;
use function Sentry\configureScope;

class DataFetchFailureReporterTest extends TestCase
{
    use CapturesSentryIssues;

    public function test_capture_is_an_issue_with_safe_aggregate_context_and_isolated_scope(): void
    {
        $this->captureSentryIssues();
        Log::spy();
        configureScope(function (Scope $scope): void {
            $scope->setTag('unrelated', 'secret-token');
            $scope->setContext('http', ['url' => 'https://example.com/?token=secret-token']);
        });
        $reporter = new DataFetchFailureReporter('spot');
        $reporter->fail('acquisition', new \RuntimeException('secret-token HTTP response body'));
        $reporter->fail('acquisition', new \RuntimeException('another secret'));
        $reporter->count('fetched_records', 0);
        $reporter->report(true);

        $this->assertImportIssue('spot', 'error', ['acquisition' => 2]);
        $event = $this->sentryIssues[0];
        $this->assertSame(['data_import' => 'spot'], $event->getTags());
        $this->assertSame([
            'import' => 'spot',
            'failures' => ['acquisition' => 2],
            'counts' => ['fetched_records' => 0],
            'exception_classes' => [\RuntimeException::class],
            'reasons' => ['acquisition' => ['unexpected' => 2]],
        ], $event->getContexts()['data_fetch']);
        $this->assertArrayNotHasKey('http', $event->getContexts());
        Log::shouldHaveReceived('log')->once()->with('error', 'Data fetch failed: spot', $event->getContexts()['data_fetch']);

        captureMessage('unrelated');
        $this->assertSame('secret-token', $this->sentryIssues[1]->getTags()['unrelated']);
        $this->assertArrayNotHasKey('data_fetch', $this->sentryIssues[1]->getContexts());
        $this->assertSame([], $this->sentryIssues[1]->getFingerprint());
    }

    public function test_cache_failures_use_only_closed_reasons_in_one_issue_and_log(): void
    {
        $this->captureSentryIssues();
        Log::spy();
        $reporter = new DataFetchFailureReporter('contracts');
        foreach ([
            ContractPriceCacheConflict::evidenceChanged(),
            ContractPriceCacheConflict::generationChanged(),
            ContractPriceCacheStorageException::writeFailed(),
            ContractPriceCacheStorageException::readbackFailed(),
            new \RuntimeException('secret-token https://seller.test/?password=private SELECT raw_data'),
        ] as $exception) {
            $reporter->fail('price_cache_refresh', $exception);
        }
        $reporter->report(true);
        $this->assertImportIssue('contracts', 'error', ['price_cache_refresh' => 5]);
        $context = $this->sentryIssues[0]->getContexts()['data_fetch'];
        $this->assertSame(['evidence_changed' => 1, 'generation_changed' => 1, 'cache_write_failed' => 1, 'cache_readback_failed' => 1, 'unexpected' => 1], $context['reasons']['price_cache_refresh']);
        $this->assertStringNotContainsString('secret-token', json_encode($context));
        $this->assertStringNotContainsString('seller.test', json_encode($context));
        Log::shouldHaveReceived('log')->once()->with('error', 'Data fetch failed: contracts', $context);
    }

    public function test_completion_stop_reasons_are_closed_and_never_expose_constructor_input(): void
    {
        $this->captureSentryIssues();
        Log::spy();
        $reporter = new DataFetchFailureReporter('contracts');
        $reasons = ['publication_missing', 'superseded', 'ownership_changed', 'date_expired',
            'active_set_empty', 'waiting', 'deadline_exhausted', 'checks_exhausted', 'interrupted_execution'];
        foreach ([...$reasons, 'secret-token https://private.test/?password=secret'] as $reason) {
            $reporter->fail('daily_statistics', new ContractImportCompletionStopped($reason));
        }
        $reporter->report(true);
        $context = $this->sentryIssues[0]->getContexts()['data_fetch'];
        $this->assertSame(array_fill_keys([...$reasons, 'unexpected'], 1), $context['reasons']['daily_statistics']);
        $this->assertStringNotContainsString('secret', json_encode($context));
        $this->assertStringNotContainsString('private.test', json_encode($context));
        Log::shouldHaveReceived('log')->once()->with('error', 'Data fetch failed: contracts', $context);
    }

    public function test_success_is_silent_and_repeated_failed_runs_keep_the_same_group(): void
    {
        $this->captureSentryIssues();
        Log::spy();
        (new DataFetchFailureReporter('contracts'))->report(false);
        $this->assertSame([], $this->sentryIssues);
        Log::shouldNotHaveReceived('log');
        for ($i = 0; $i < 2; $i++) {
            $reporter = new DataFetchFailureReporter('contracts');
            $reporter->fail('acquisition');
            $reporter->report(false);
        }
        $this->assertCount(2, $this->sentryIssues);
        $this->assertSame('warning', (string) $this->sentryIssues[0]->getLevel());
        $this->assertSame($this->sentryIssues[0]->getFingerprint(), $this->sentryIssues[1]->getFingerprint());
    }
}
