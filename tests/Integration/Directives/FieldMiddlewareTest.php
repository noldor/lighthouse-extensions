<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Integration\Directives;

use Override;
use stdClass;
use Generator;
use GraphQL\GraphQL;
use GraphQL\Deferred;
use GraphQL\Type\Schema;
use Carbon\CarbonImmutable;
use GraphQL\Language\Parser;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Illuminate\Support\Carbon;
use GraphQL\Type\Definition\Type;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Type\Definition\ObjectType;
use PHPUnit\Framework\Attributes\TestWith;
use Nuwave\Lighthouse\Testing\MocksResolvers;
use Nuwave\Lighthouse\Testing\UsesTestSchema;
use Nuwave\Lighthouse\Schema\Values\TypeValue;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Noldors\LighthouseExtensions\Tests\TestCase;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use Noldors\LighthouseExtensions\Scalars\DateTime;
use Nuwave\Lighthouse\Execution\HttpGraphQLContext;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Noldors\LighthouseExtensions\Directives\DateTimeDirective;

final class FieldMiddlewareTest extends TestCase
{
    use MakesGraphQLRequests;
    use MocksResolvers;
    use UsesTestSchema;

    #[Override]
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            FieldValue::clear();
        }
    }

    public function testLowerNormalizesArgumentsAndInputFieldsBeforeValidationAndRenaming(): void
    {
        $this->schema = <<<'GRAPHQL'
            type Query {
                value(
                    text: String! @lower @rules(apply: ["regex:/^[a-z]+$/"]) @rename(attribute: "normalized_text")
                    input: LowerInput! @spread
                ): String! @mock
            }

            input LowerInput {
                label: String! @lower @rules(apply: ["regex:/^[a-z]+$/"]) @rename(attribute: "normalized_label")
                count: Int!
                enabled: Boolean!
                note: String @lower
            }

            GRAPHQL;
        $this->setUpTestSchema();
        \config(['lighthouse.schema_cache.enable' => false]);
        $resolvedArgs = null;
        $this->mockResolver(
            static function (mixed $root, array $args) use (&$resolvedArgs): string {
                $resolvedArgs = $args;

                return 'validated';
            },
        );

        $this->graphQL(
            'query { value(text: "UPPER", input: { label: "NESTED", count: 42, enabled: true, note: null }) }',
        )->assertGraphQLErrorFree()->assertJsonPath('data.value', 'validated');

        $this->assertIsArray($resolvedArgs);
        \ksort($resolvedArgs);
        $this->assertSame([
            'count' => 42,
            'enabled' => true,
            'normalized_label' => 'nested',
            'normalized_text' => 'upper',
            'note' => null,
        ], $resolvedArgs);
    }

    public function testDateTimeUsesPackageDefaultsWithoutRegisteringCarbonMacros(): void
    {
        \config([
            'lighthouse-extensions.datetime_format' => 'd/m/Y H:i',
            'lighthouse-extensions.timezone' => 'Europe/Moscow',
            'app.datetime_format' => 'Y',
            'app.timezone' => 'UTC',
        ]);
        $this->schema = <<<'GRAPHQL'
            scalar DateTime @scalar(class: "Noldors\\LighthouseExtensions\\Scalars\\DateTime")
            type Query { date: DateTime @mock @dateTime }
            GRAPHQL;
        $this->setUpTestSchema();
        $source = Carbon::parse('2026-10-06T12:00:00Z');
        $this->mockResolver(static fn () => $source);
        $this->graphQL('{ date }')->assertGraphQLErrorFree()->assertJsonPath('data.date', '06/10/2026 15:00');
        $this->assertSame('2026-10-06T12:00:00+00:00', $source->toIso8601String());
        $this->assertFalse(Carbon::hasMacro('frontendDateFormat'));
        $this->assertFalse(
            Carbon::hasMacro('frontendDateTimeFormat'),
        );
        $this->assertFalse(Carbon::hasMacro('toDateResponse'));
        $this->assertFalse(Carbon::hasMacro('toDateTimeResponse'));
        $this->assertFalse(
            CarbonImmutable::hasMacro('frontendDateTimeFormat'),
        );
    }

    public function testDateTimeFormatsListWithoutMutatingOriginalDates(): void
    {
        \config([
            'lighthouse-extensions.datetime_format' => 'Y-m-d\\TH:i:s.uP',
            'lighthouse-extensions.timezone' => 'UTC',
        ]);
        $dates = [Carbon::parse('2026-10-06T12:00:00Z'), Carbon::parse('2026-10-06T13:00:00Z')];
        $definition = Parser::parse(
            'type Query { dates(timeZone: String, format: String): [DateTime!]! }',
        )->definitions[0];
        $this->assertInstanceOf(ObjectTypeDefinitionNode::class, $definition);
        $field = new FieldValue(new TypeValue($definition), $definition->fields[0]);
        new DateTimeDirective()->handleField($field);
        $type = new ObjectType([
            'name' => 'Query',
            'fields' => [
                'dates' => [
                    'type' => Type::listOf(new DateTime()),
                    'args' => ['timeZone' => Type::string(), 'format' => Type::string()],
                    'astNode' => $definition->fields[0],
                    'resolve' => $field->finishResolver(static fn () => $dates),
                ],
            ],
        ]);

        FieldValue::clear();
        $result = GraphQL::executeQuery(
            new Schema(['query' => $type]),
            '{ dates(timeZone: "Europe/Moscow", format: "Y-m-d H:i") }',
            null,
            new HttpGraphQLContext(new Request()),
        );

        $this->assertSame(['data' => ['dates' => ['2026-10-06 15:00', '2026-10-06 16:00']]], $result->toArray());
        $this->assertSame('2026-10-06T12:00:00+00:00', $dates[0]->toIso8601String());
        $this->assertSame('2026-10-06T13:00:00+00:00', $dates[1]->toIso8601String());
    }

    public function testDateTimeFormatsDeferredValuesWithoutMutatingOriginalDates(): void
    {
        $this->schema = <<<'GRAPHQL'
            scalar DateTime @scalar(class: "Noldors\\LighthouseExtensions\\Scalars\\DateTime")

            type Query {
                date(timeZone: String, format: String): DateTime @mock @dateTime
            }
            GRAPHQL;
        $this->setUpTestSchema();
        \config(['lighthouse.schema_cache.enable' => false]);
        $date = Carbon::parse('2026-10-06T12:00:00Z');
        $this->mockResolver(
            static fn (): Deferred => new Deferred(static fn (): Carbon => $date),
        );

        $this->graphQL(
            '{ date(timeZone: "Europe/Moscow", format: "Y-m-d H:i:s") }',
        )->assertGraphQLErrorFree()->assertJsonPath('data.date', '2026-10-06 15:00:00');

        $this->assertSame('2026-10-06T12:00:00+00:00', $date->toIso8601String());
    }

    public function testDateTimeRejectsFieldsWithoutCarbonValues(): void
    {
        $definition = Parser::parse('type Query { value: String }')->definitions[0];
        $this->assertInstanceOf(ObjectTypeDefinitionNode::class, $definition);
        $field = new FieldValue(new TypeValue($definition), $definition->fields[0]);
        new DateTimeDirective()->handleField($field);
        $type = new ObjectType([
            'name' => 'Query',
            'fields' => [
                'value' => [
                    'type' => Type::string(),
                    'astNode' => $definition->fields[0],
                    'resolve' => $field->finishResolver(static fn () => 'not-a-date'),
                ],
            ],
        ]);

        FieldValue::clear();
        $result = GraphQL::executeQuery(
            new Schema(['query' => $type]),
            '{ value }',
            null,
            new HttpGraphQLContext(new Request()),
        );

        $previous = $result->errors[0]->getPrevious();
        $this->assertNotNull($previous);
        $this->assertSame('@dateTime directive could be applied only to carbon fields.', $previous->getMessage());
        $this->assertSame(['value' => null], $result->data);
    }

    public function testDateTimeFormatsAGeneratorWithoutMutatingItsDates(): void
    {
        $date = Carbon::parse('2026-10-11T12:00:00Z');
        $immutableDate = CarbonImmutable::parse('2026-10-11T13:00:00Z');
        $dates = (static function () use ($date, $immutableDate): Generator {
            yield 4 => $date;

            yield 'second' => $immutableDate;
        })();

        $result = $this->resolveDates($dates);

        $this->assertSame(['data' => ['dates' => ['2026-10-11 15:00', '2026-10-11 16:00']]], $result->toArray());
        $this->assertSame('2026-10-11T12:00:00+00:00', $date->toIso8601String());
        $this->assertSame('2026-10-11T13:00:00+00:00', $immutableDate->toIso8601String());
    }

    #[TestWith([null])]
    #[TestWith(['not-a-date'])]
    #[TestWith([123])]
    public function testDateTimeRejectsListItemsWithoutCarbonValues(mixed $value): void
    {
        $result = $this->resolveDates([Carbon::parse('2026-10-11T12:00:00Z'), $value]);

        $this->assertCount(1, $result->errors);
        $previous = $result->errors[0]->getPrevious();
        $this->assertInstanceOf(InvalidArgumentException::class, $previous);
        $this->assertSame('@dateTime directive could be applied only to carbon fields.', $previous->getMessage());
        $this->assertSame(['dates'], $result->errors[0]->getPath());
        $this->assertSame(['dates' => null], $result->data);
    }

    public function testDateTimeRejectsIterableKeysThatCannotBeCollectionKeys(): void
    {
        $dates = (static function (): Generator {
            yield new stdClass() => Carbon::parse('2026-10-11T12:00:00Z');
        })();

        $result = $this->resolveDates($dates);

        $this->assertCount(1, $result->errors);
        $previous = $result->errors[0]->getPrevious();
        $this->assertInstanceOf(InvalidArgumentException::class, $previous);
        $this->assertSame('@dateTime iterable keys must be integers or strings.', $previous->getMessage());
        $this->assertSame(['dates'], $result->errors[0]->getPath());
        $this->assertSame(['dates' => null], $result->data);
    }

    public function testDateTimePreservesNullForANullableField(): void
    {
        $definition = Parser::parse('type Query { value: DateTime }')->definitions[0];
        $this->assertInstanceOf(ObjectTypeDefinitionNode::class, $definition);
        $field = new FieldValue(new TypeValue($definition), $definition->fields[0]);
        new DateTimeDirective()->handleField($field);
        $type = new ObjectType([
            'name' => 'Query',
            'fields' => [
                'value' => [
                    'type' => new DateTime(),
                    'astNode' => $definition->fields[0],
                    'resolve' => $field->finishResolver(static fn () => null),
                ],
            ],
        ]);

        FieldValue::clear();
        $result = GraphQL::executeQuery(
            new Schema(['query' => $type]),
            '{ value }',
            null,
            new HttpGraphQLContext(new Request()),
        );

        $this->assertSame(['data' => ['value' => null]], $result->toArray());
    }

    /**
     * @param iterable<mixed, mixed> $dates
     */
    private function resolveDates(iterable $dates): ExecutionResult
    {
        $definition = Parser::parse(
            'type Query { dates(timeZone: String, format: String): [DateTime!] }',
        )->definitions[0];
        $this->assertInstanceOf(ObjectTypeDefinitionNode::class, $definition);
        $field = new FieldValue(new TypeValue($definition), $definition->fields[0]);
        new DateTimeDirective()->handleField($field);
        $type = new ObjectType([
            'name' => 'Query',
            'fields' => [
                'dates' => [
                    'type' => Type::listOf(Type::nonNull(new DateTime())),
                    'args' => ['timeZone' => Type::string(), 'format' => Type::string()],
                    'astNode' => $definition->fields[0],
                    'resolve' => $field->finishResolver(static fn () => $dates),
                ],
            ],
        ]);

        FieldValue::clear();

        return GraphQL::executeQuery(
            new Schema(['query' => $type]),
            '{ dates(timeZone: "Europe/Moscow", format: "Y-m-d H:i") }',
            null,
            new HttpGraphQLContext(new Request()),
        );
    }
}
