<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests;

use Override;
use LogicException;
use Illuminate\Foundation\Application;
use Nuwave\Lighthouse\Auth\AuthServiceProvider;
use Nuwave\Lighthouse\LighthouseServiceProvider;
use Nuwave\Lighthouse\Testing\TestSchemaProvider;
use Nuwave\Lighthouse\Testing\TestingServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Nuwave\Lighthouse\GlobalId\GlobalIdServiceProvider;
use Nuwave\Lighthouse\Schema\Source\SchemaSourceProvider;
use Nuwave\Lighthouse\Validation\ValidationServiceProvider;
use Noldors\LighthouseExtensions\LighthouseExtensionsServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->application()->bind(SchemaSourceProvider::class, static function (): TestSchemaProvider {
            $schema = 'type Query { noop: Boolean @mock }';

            return new TestSchemaProvider($schema);
        });
    }

    protected function application(): Application
    {
        return $this->app ?? throw new LogicException('The test application is not bootstrapped.');
    }

    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            LighthouseServiceProvider::class,
            AuthServiceProvider::class,
            GlobalIdServiceProvider::class,
            ValidationServiceProvider::class,
            LighthouseExtensionsServiceProvider::class,
            TestingServiceProvider::class,
        ];
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('app.locale', 'en');
        $app['config']->set('lighthouse.schema_cache.enable', false);
        $app['config']->set('lighthouse.query_cache.enable', false);
        $app['config']->set('lighthouse.namespaces.models', ['Noldors\\LighthouseExtensions\\Tests\\Fixtures']);
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => \getenv('DB_HOST') ?: 'postgres',
            'port' => \getenv('DB_PORT') ?: '5432',
            'database' => \getenv('DB_DATABASE') ?: 'extensions_testing',
            'username' => \getenv('DB_USERNAME') ?: 'packages',
            'password' => \getenv('DB_PASSWORD') ?: 'packages',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }
}
