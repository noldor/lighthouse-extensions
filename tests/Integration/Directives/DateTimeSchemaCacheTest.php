<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Integration\Directives;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Config;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Nuwave\Lighthouse\Schema\AST\ASTCache;
use Nuwave\Lighthouse\Schema\TypeRegistry;
use Nuwave\Lighthouse\Schema\SchemaBuilder;
use Nuwave\Lighthouse\Schema\AST\ASTBuilder;
use Nuwave\Lighthouse\Testing\MocksResolvers;
use Nuwave\Lighthouse\Testing\UsesTestSchema;
use Nuwave\Lighthouse\Events\BuildSchemaString;
use Noldors\LighthouseExtensions\Tests\TestCase;
use Nuwave\Lighthouse\GraphQL as LighthouseGraphQL;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

final class DateTimeSchemaCacheTest extends TestCase
{
    use MakesGraphQLRequests;
    use MocksResolvers;
    use UsesTestSchema;

    public function testCachedDocumentRetainsGeneratedArgumentsAndMetadataWithRuntimeConfigurationFallback(): void
    {
        $this->schema = <<<'GRAPHQL'
            scalar DateTime @scalar(class: "Noldors\\LighthouseExtensions\\Scalars\\DateTime")
            type Query {
                date: DateTime @mock @dateTime
                custom("Application format" format: String! = "Y-m-d" @rules(apply: ["max:20"])): DateTime @mock @dateTime
            }
            GRAPHQL;
        $this->setUpTestSchema();
        $app = $this->application();
        $translator = \resolve(Translator::class);
        $translator->setFallback('en');
        $loader = $translator->getLoader();
        $this->assertInstanceOf(FileLoader::class, $loader);
        $loader->addPath(__DIR__ . '/../../Fixtures/lang');
        $app->setLocale('ru');
        $cachePath = $app->storagePath(
            'framework/cache/lighthouse-extensions-datetime-' . \bin2hex(\random_bytes(8)) . '.php',
        );
        Config::set([
            'lighthouse.schema_cache.enable' => true,
            'lighthouse.schema_cache.path' => $cachePath,
            'lighthouse-extensions.datetime_format' => 'Y-m-d H:i',
            'lighthouse-extensions.timezone' => 'UTC',
        ]);
        $builds = 0;
        Event::listen(BuildSchemaString::class, static function () use (&$builds): void {
            ++$builds;
        });
        $this->mockResolver(
            static fn () => Carbon::parse('2026-10-06T12:00:00Z'),
        );
        $cache = $app->make(ASTCache::class);

        try {
            $this->graphQL('{ date custom }')
                ->assertGraphQLErrorFree()
                ->assertJsonPath('data.date', '2026-10-06 12:00')
                ->assertJsonPath('data.custom', '2026-10-06');
            $this->assertFileExists($cachePath);
            $coldDocument = $app->make(ASTBuilder::class)->documentAST();
            $this->assertSame(1, $builds);
            $retainedServices = [
                LighthouseGraphQL::class,
                SchemaBuilder::class,
                ASTBuilder::class,
                TypeRegistry::class,
            ];

            foreach ($retainedServices as $service) {
                $app->forgetInstance($service);
            }

            $app->setLocale('en');
            Config::set([
                'lighthouse-extensions.datetime_format' => 'd/m/Y H:i',
                'lighthouse-extensions.timezone' => 'Europe/Moscow',
            ]);
            $this->graphQL('{ date custom }')
                ->assertGraphQLErrorFree()
                ->assertJsonPath('data.date', '06/10/2026 15:00')
                ->assertJsonPath('data.custom', '2026-10-06');
            $this->assertNotSame($coldDocument, $app->make(ASTBuilder::class)->documentAST());
            $this->assertSame(1, $builds);
            $query = $app->make(SchemaBuilder::class)->schema()->getQueryType();
            $this->assertNotNull($query);
            $field = $query->getField('date');
            $this->assertCount(2, $field->args);

            $descriptions = [
                'timeZone' => 'Application timezone description.',
                'format' => 'PHP date format; null uses the configured package date-time format.',
            ];

            foreach ($descriptions as $name => $description) {
                $argument = $field->getArg($name);
                $this->assertNotNull($argument);
                $this->assertSame('String', (string) $argument->getType());
                $this->assertFalse($argument->defaultValueExists());
                $this->assertSame($description, $argument->description);
            }

            $custom = $query->getField('custom')->getArg('format');
            $this->assertNotNull($custom);
            $this->assertSame('String!', (string) $custom->getType());
            $this->assertSame('Application format', $custom->description);
            $this->assertSame('Y-m-d', $custom->defaultValue);
            $this->graphQL(
                '{ custom(format: "123456789012345678901") }',
            )->assertGraphQLValidationKeys(['format']);
            $this->assertSame(1, $builds);
        } finally {
            $cache->clear();
        }
    }
}
