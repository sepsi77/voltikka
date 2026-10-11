<?php

namespace Tests\Feature;

use App\Support\DatabaseConnectionLost;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Illuminate\View\ViewException;
use PDO;
use PDOException;
use RuntimeException;
use Sentry\Event;
use Sentry\EventHint;
use Tests\TestCase;

class DatabaseConnectionLostTest extends TestCase
{
    private function refused(): QueryException
    {
        return new QueryException('mysql', 'select 1', [], new PDOException('SQLSTATE[HY000] [2002] Connection refused'));
    }

    public function test_unreachable_database_renders_uncached_finnish_503(): void
    {
        Route::get('/test-database-refused', fn () => throw $this->refused());

        $this->get('/test-database-refused')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Voltikka on tilapäisesti poissa käytöstä.');
    }

    public function test_lost_connection_wrapped_by_view_rendering_still_renders_503(): void
    {
        Route::get('/test-database-view', fn () => throw new ViewException('rendering failed', 0, 1, 'view.blade.php', 0, $this->refused()));

        $this->get('/test-database-view')->assertStatus(503);
    }

    public function test_faulty_query_and_non_database_socket_errors_keep_normal_500(): void
    {
        Route::get('/test-database-syntax', fn () => throw new QueryException('mysql', 'select', [], new PDOException('SQLSTATE[42000]: Syntax error')));
        Route::get('/test-http-refused', fn () => throw new RuntimeException('cURL error 7: Connection refused; reset by peer'));

        $this->get('/test-database-syntax')->assertStatus(500);
        $this->get('/test-http-refused')->assertStatus(500);
    }

    public function test_sentry_groups_lost_connection_events_into_one_issue(): void
    {
        $goneAway = new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');

        $grouped = DatabaseConnectionLost::beforeSend(Event::createEvent(), EventHint::fromArray(['exception' => $goneAway]));
        $other = DatabaseConnectionLost::beforeSend(Event::createEvent(), EventHint::fromArray(['exception' => new RuntimeException('x')]));
        $noHint = DatabaseConnectionLost::beforeSend(Event::createEvent(), null);

        $this->assertSame([DatabaseConnectionLost::FINGERPRINT], $grouped->getFingerprint());
        $this->assertSame([], $other->getFingerprint());
        $this->assertSame([], $noHint->getFingerprint());
        $this->assertSame([DatabaseConnectionLost::class, 'beforeSend'], config('sentry.before_send'));
    }

    public function test_mysql_connect_timeout_is_below_the_request_time_limit(): void
    {
        $this->assertSame(3, config('database.connections.mysql.options')[PDO::ATTR_TIMEOUT] ?? null);
    }
}
