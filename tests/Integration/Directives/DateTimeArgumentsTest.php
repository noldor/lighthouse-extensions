<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Integration\Directives;

use Illuminate\Support\Carbon;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Attributes\TestWith;
use Nuwave\Lighthouse\Schema\SchemaBuilder;
use Nuwave\Lighthouse\Testing\MocksResolvers;
use Nuwave\Lighthouse\Testing\UsesTestSchema;
use Noldors\LighthouseExtensions\Tests\TestCase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Nuwave\Lighthouse\Exceptions\DefinitionException;

final class DateTimeArgumentsTest extends TestCase
{
    use MakesGraphQLRequests;
    use MocksResolvers;
    use UsesTestSchema;

    public function testDirectiveAddsBothArgumentsToSchemaAndAcceptsThemWithoutManualDeclarations(): void
    {
        $this->schema = <<<'GRAPHQL'
            scalar DateTime @scalar(class: "Noldors\\LighthouseExtensions\\Scalars\\DateTime")
            type Query { date: DateTime @mock @dateTime }
            GRAPHQL;
        $this->setUpTestSchema();
        $this->mockResolver(
            static fn () => Carbon::parse('2026-10-06T12:00:00Z'),
        );
        $this->graphQL(
            '{ date(timeZone: "Europe/Moscow", format: "Y-m-d H:i") }',
        )->assertGraphQLErrorFree()->assertJsonPath('data.date', '2026-10-06 15:00');
        $query = $this->application()->make(SchemaBuilder::class)->schema()->getQueryType();
        $this->assertNotNull($query);
        $field = $query->getField('date');

        $descriptions = [
            'timeZone' => 'Timezone used for formatting; null uses the configured package timezone.',
            'format' => 'PHP date format; null uses the configured package date-time format.',
        ];

        foreach ($descriptions as $name => $description) {
            $argument = $field->getArg($name);
            $this->assertNotNull($argument);
            $this->assertSame('String', (string) $argument->getType());
            $this->assertFalse($argument->defaultValueExists());
            $this->assertSame($description, $argument->description);
        }
        $this->graphQL(
            '{ __type(name: "Query") { fields { name args { name type { kind name } } } } }',
        )->assertGraphQLErrorFree()->assertJsonPath('data.__type.fields.0.args.0.name', 'timeZone')
            ->assertJsonPath('data.__type.fields.0.args.1.name', 'format');
    }

    #[TestWith([
        'ru',
        'Application timezone description.',
        'PHP date format; null uses the configured package date-time format.',
    ])]
    #[TestWith([
        'de',
        'Timezone used for formatting; null uses the configured package timezone.',
        'Application format description.',
    ])]
    public function testIntrospectionUsesHostTranslationsWithFallbackAndPreservesAuthoredDescriptions(
        string $locale,
        string $timeZoneDescription,
        string $formatDescription,
    ): void {
        $translator = \resolve(Translator::class);
        $translator->setFallback('en');
        $loader = $translator->getLoader();
        $this->assertInstanceOf(FileLoader::class, $loader);
        $loader->addPath(__DIR__ . '/../../Fixtures/lang');
        $this->application()->setLocale($locale);
        $this->schema = <<<'GRAPHQL'
            scalar DateTime @scalar(class: "Noldors\\LighthouseExtensions\\Scalars\\DateTime")
            type Query {
                date: DateTime @mock @dateTime
                custom("Application format" format: String! = "Y-m-d"): DateTime @mock @dateTime
            }
            GRAPHQL;
        $this->setUpTestSchema();

        $this->graphQL(
            '{ __type(name: "Query") { fields { name args { name description } } } }',
        )->assertGraphQLErrorFree()->assertJsonPath('data.__type.fields.0.name', 'date')
            ->assertJsonPath('data.__type.fields.0.args.0.name', 'timeZone')
            ->assertJsonPath('data.__type.fields.0.args.0.description', $timeZoneDescription)
            ->assertJsonPath('data.__type.fields.0.args.1.name', 'format')
            ->assertJsonPath(
                'data.__type.fields.0.args.1.description',
                $formatDescription,
            )->assertJsonPath('data.__type.fields.1.name', 'custom')
            ->assertJsonPath('data.__type.fields.1.args.0.name', 'format')
            ->assertJsonPath('data.__type.fields.1.args.0.description', 'Application format')
            ->assertJsonPath('data.__type.fields.1.args.1.name', 'timeZone')
            ->assertJsonPath('data.__type.fields.1.args.1.description', $timeZoneDescription);
    }

    public function testDirectivePreservesExistingArgumentMetadataAndAddsOnlyTheMissingArgument(): void
    {
        $this->schema = <<<'GRAPHQL'
            scalar DateTime @scalar(class: "Noldors\\LighthouseExtensions\\Scalars\\DateTime")
            type Query {
                date("Application format" format: String! = "Y-m-d" @rules(apply: ["max:20"])): DateTime @mock @dateTime
            }
            GRAPHQL;
        $this->setUpTestSchema();
        $this->mockResolver(
            static fn () => Carbon::parse('2026-10-06T12:00:00Z'),
        );
        $query = $this->application()->make(SchemaBuilder::class)->schema()->getQueryType();
        $this->assertNotNull($query);
        $field = $query->getField('date');
        $format = $field->getArg('format');
        $this->assertNotNull($format);
        $this->assertSame('String!', (string) $format->getType());
        $this->assertSame('Application format', $format->description);
        $this->assertSame('Y-m-d', $format->defaultValue);
        $this->assertCount(2, $field->args);
        $this->graphQL('{ date }')->assertGraphQLErrorFree()->assertJsonPath('data.date', '2026-10-06');
        $this->graphQL(
            '{ date(format: "123456789012345678901") }',
        )->assertGraphQLValidationKeys(['format']);
    }

    #[TestWith(['format', 'Int'])]
    #[TestWith(['format', '[String!]'])]
    #[TestWith(['timeZone', 'Boolean!'])]
    #[TestWith(['timeZone', '[String]'])]
    public function testDirectiveRejectsIncompatibleExistingArgumentTypes(string $argument, string $type): void
    {
        $this->schema = 'scalar DateTime @scalar(class: "Noldors\\\\LighthouseExtensions\\\\Scalars\\\\DateTime")'
            . " type Query { date({$argument}: {$type}): DateTime @mock @dateTime }";
        $this->setUpTestSchema();
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(
            "The {$argument} argument for @dateTime on Query.date must have type String or String!.",
        );
        $this->application()->make(SchemaBuilder::class)->schema();
    }
}
