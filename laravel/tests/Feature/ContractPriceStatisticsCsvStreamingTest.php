<?php

namespace Tests\Feature;

use App\Http\Controllers\ContractPriceStatisticsCsvController;
use App\Models\ContractPriceDailyStatistic;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOStatement;
use RuntimeException;
use Tests\TestCase;
use WeakReference;

class ContractPriceStatisticsCsvStreamingTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_streams_all_rows_in_the_existing_order_with_one_select(): void
    {
        config()->set('contract_statistics.annual_cost.active_method_version', 'annual_cost_as_of_v2');
        $expected = [];
        $fixtures = [];
        for ($day = 0; $day < 30; $day++) {
            foreach (['fixed', 'spot'] as $segment) {
                foreach (['annual_cost', 'energy_price'] as $metric) {
                    foreach ([2000, 5000, 18000] as $consumption) {
                        $methods = $metric === 'annual_cost'
                            ? ['annual_cost_as_of_v1', 'annual_cost_as_of_v2']
                            : ['unit_statistics_v1'];
                        foreach ($methods as $method) {
                            $date = now()->startOfYear()->addDays($day)->toDateString();
                            $annual = $metric === 'annual_cost';
                            $attributes = [
                                'stat_date' => $date,
                                'segment_key' => $segment,
                                'metric_key' => $metric,
                                'pricing_basis' => $annual ? 'canonical_calculation' : 'observed_seller_data',
                                'method_version' => $method,
                                'calculation_basis' => $annual ? 'canonical_outcome' : null,
                                'estimate_basis' => $annual ? 'forward_curve' : null,
                                'compatibility_key' => $annual ? 'audit-key' : null,
                                'basis_counts' => $annual ? ['lähde/forward' => 3] : null,
                                'consumption_kwh' => $consumption,
                                'min_value' => null,
                                'p20_value' => 0,
                                'median_value' => 123.4567,
                                'avg_value' => 234.5678,
                                'p80_value' => 345.6789,
                                'max_value' => 456.789,
                                'contract_count' => 3,
                            ];
                            $expected[] = [
                                $date, $segment, $metric, $attributes['pricing_basis'], $method,
                                $attributes['calculation_basis'] ?? '', $attributes['estimate_basis'] ?? '',
                                $attributes['compatibility_key'] ?? '', $annual ? '{"lähde/forward":3}' : '',
                                $method === 'annual_cost_as_of_v2' ? '1' : '0', (string) $consumption,
                                '', '0', '123.4567', '234.5678', '345.6789', '456.789', '3',
                            ];
                            $fixtures[] = $attributes;
                        }
                    }
                }
            }
        }
        // Insert in reverse order so primary-key order cannot satisfy the export order.
        foreach (array_reverse($fixtures) as $attributes) {
            ContractPriceDailyStatistic::create($attributes);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->get('/sahkosopimus/tilastot.csv')->assertOk();
        $body = $response->streamedContent();
        $queries = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql): bool => str_starts_with(strtolower($sql), 'select')
                && str_contains($sql, 'contract_price_daily_statistics'))->values();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('offset', strtolower($queries[0]));
        $this->assertStringNotContainsString('limit', strtolower($queries[0]));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $rows = collect(explode("\n", trim($body)))
            ->map(fn (string $line): array => str_getcsv($line, escape: ''));
        $this->assertSame([
            'date', 'segment_key', 'metric_key', 'pricing_basis', 'method_version',
            'calculation_basis', 'estimate_basis', 'compatibility_key', 'basis_counts',
            'is_active_annual_method', 'consumption_kwh', 'min', 'p20', 'median',
            'avg', 'p80', 'max', 'contract_count',
        ], $rows->first(fn (array $row): bool => $row[0] === 'date'));
        $data = $rows->filter(fn (array $row): bool => in_array($row[2] ?? null, ['annual_cost', 'energy_price'], true))->values()->all();
        $this->assertCount(540, $data);
        $this->assertSame(end($expected), end($data));
        $this->assertSame($expected, $data);
        $this->assertSame(540, ContractPriceDailyStatistic::count());
    }

    public function test_mysql_read_pdo_buffering_is_restored_after_success_and_failure(): void
    {
        if (! defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $this->markTestSkipped('The MySQL PDO attribute is not available.');
        }

        ContractPriceDailyStatistic::create([
            'stat_date' => '2026-01-01', 'segment_key' => 'spot', 'metric_key' => 'energy_price',
            'consumption_kwh' => 0, 'contract_count' => 1,
        ]);
        $original = DB::connection()->getReadPdo();
        $connection = new SQLiteConnection($original);
        $resolver = ContractPriceDailyStatistic::getConnectionResolver();
        ContractPriceDailyStatistic::setConnectionResolver(new ConnectionResolver(['' => $connection]));
        $pdo = new class($original) extends PDO
        {
            public bool $buffered = true;

            public array $settings = [];

            public ?WeakReference $statement = null;

            public function __construct(private PDO $delegate) {}

            public function getAttribute(int $attribute): mixed
            {
                return match ($attribute) {
                    PDO::ATTR_DRIVER_NAME => 'mysql',
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => $this->buffered,
                    default => $this->delegate->getAttribute($attribute),
                };
            }

            public function setAttribute(int $attribute, mixed $value): bool
            {
                if ($attribute === PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) {
                    if ($this->statement?->get() !== null) {
                        throw new RuntimeException('Statement was not released before restoration.');
                    }
                    $this->settings[] = $value;
                    $this->buffered = $value;

                    return true;
                }

                return $this->delegate->setAttribute($attribute, $value);
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if ($this->buffered) {
                    throw new RuntimeException('CSV query is still buffered.');
                }
                $statement = $this->delegate->prepare($query, $options);
                $this->statement = WeakReference::create($statement);

                return $statement;
            }
        };
        $connection->setReadPdo($pdo);
        try {
            foreach ([true, false] as $initial) {
                foreach ([false, true] as $fail) {
                    $pdo->buffered = $initial;
                    $pdo->settings = [];
                    if ($fail) {
                        ContractPriceDailyStatistic::retrieved(function (): void {
                            throw new RuntimeException('CSV hydration failure.');
                        });
                    }
                    $response = app(ContractPriceStatisticsCsvController::class)(Request::create('/sahkosopimus/tilastot.csv'));
                    ob_start();
                    try {
                        $response->sendContent();
                        $this->assertFalse($fail, 'The stream must propagate a hydration failure.');
                    } catch (RuntimeException $exception) {
                        $this->assertTrue($fail);
                        $this->assertSame('CSV hydration failure.', $exception->getMessage());
                    } finally {
                        ob_end_clean();
                        ContractPriceDailyStatistic::flushEventListeners();
                    }
                    $this->assertSame([false, $initial], $pdo->settings);
                    $this->assertSame($initial, $pdo->buffered);
                    $this->assertNull($pdo->statement->get());
                    $this->assertSame(1, (int) $original->query('select count(*) from contract_price_daily_statistics')->fetchColumn());
                }
            }
        } finally {
            ContractPriceDailyStatistic::setConnectionResolver($resolver);
        }
    }
}
