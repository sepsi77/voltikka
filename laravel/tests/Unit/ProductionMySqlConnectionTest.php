<?php

namespace Tests\Unit;

use App\Services\DevelopmentDatabase\DatabaseSyncException;
use App\Services\DevelopmentDatabase\ProductionMySqlConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProductionMySqlConnectionTest extends TestCase
{
    private const RAILWAY_VARIABLES = [
        'MYSQL_PUBLIC_URL' => 'mysql://root:secret@proxy.example:1234/railway',
        'MYSQLHOST' => 'mysql.railway.internal',
        'MYSQLPORT' => '3306',
        'MYSQLUSER' => 'root',
        'MYSQLPASSWORD' => 'secret',
        'MYSQLDATABASE' => 'railway',
        'RAILWAY_TCP_PROXY_DOMAIN' => 'proxy.example',
        'RAILWAY_TCP_PROXY_PORT' => '1234',
    ];

    protected function tearDown(): void
    {
        putenv('PRODUCTION_MYSQL_URL');

        foreach (array_keys(self::RAILWAY_VARIABLES) as $name) {
            putenv($name);
        }

        parent::tearDown();
    }

    public function test_it_fails_closed_without_the_source_url(): void
    {
        putenv('PRODUCTION_MYSQL_URL');

        $this->expectException(DatabaseSyncException::class);
        $this->expectExceptionMessage('PRODUCTION_MYSQL_URL is not set. Run scripts/sync-production-database.sh.');

        (new ProductionMySqlConnection)->connect();
    }

    public function test_it_ignores_the_old_railway_variables(): void
    {
        putenv('PRODUCTION_MYSQL_URL');

        foreach (self::RAILWAY_VARIABLES as $name => $value) {
            putenv("{$name}={$value}");
        }

        $this->expectException(DatabaseSyncException::class);
        $this->expectExceptionMessage('PRODUCTION_MYSQL_URL is not set.');

        (new ProductionMySqlConnection)->connect();
    }

    #[DataProvider('invalidUrls')]
    public function test_it_rejects_an_invalid_source_url(string $url, string $message): void
    {
        putenv("PRODUCTION_MYSQL_URL={$url}");

        $this->expectException(DatabaseSyncException::class);
        $this->expectExceptionMessage($message);

        (new ProductionMySqlConnection)->connect();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidUrls(): array
    {
        return [
            'not a URL' => ['http:///', 'PRODUCTION_MYSQL_URL is not a valid MySQL URL.'],
            'wrong scheme' => ['https://voltikka:secret@127.0.0.1:3307/voltikka', 'PRODUCTION_MYSQL_URL is not a valid MySQL URL.'],
            'no password' => ['mysql://voltikka@127.0.0.1:3307/voltikka', 'PRODUCTION_MYSQL_URL is not a valid MySQL URL.'],
            'no port' => ['mysql://voltikka:secret@127.0.0.1/voltikka', 'PRODUCTION_MYSQL_URL is not a valid MySQL URL.'],
            'no database' => ['mysql://voltikka:secret@127.0.0.1:3307/', 'PRODUCTION_MYSQL_URL does not contain a database name.'],
        ];
    }

    public function test_it_decodes_the_tunnel_url_into_pdo_parameters(): void
    {
        putenv('PRODUCTION_MYSQL_URL=mysql://voltikka:'.rawurlencode('p@ss:w/rd#?%').'@127.0.0.1:53306/voltikka');

        $parameters = (new ReflectionMethod(ProductionMySqlConnection::class, 'connectionParameters'))
            ->invoke(new ProductionMySqlConnection);

        $this->assertSame([
            'mysql:host=127.0.0.1;port=53306;dbname=voltikka;charset=utf8mb4',
            'voltikka',
            'p@ss:w/rd#?%',
        ], $parameters);
    }

    public function test_a_connection_failure_does_not_expose_the_password(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is not enabled.');
        }

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        fclose($server);
        putenv("PRODUCTION_MYSQL_URL=mysql://voltikka:do-not-print@127.0.0.1:{$port}/voltikka");

        try {
            (new ProductionMySqlConnection)->connect();
            $this->fail('The connection to a closed port must fail.');
        } catch (DatabaseSyncException $exception) {
            $this->assertSame('The production database connection failed.', $exception->getMessage());
        }
    }
}
