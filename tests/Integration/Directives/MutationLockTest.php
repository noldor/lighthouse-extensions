<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Integration\Directives;

use GraphQL\Deferred;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Nuwave\Lighthouse\Schema\SchemaBuilder;
use Illuminate\Database\Events\QueryExecuted;
use Nuwave\Lighthouse\Exceptions\DefinitionException;
use Noldors\LighthouseExtensions\Tests\Fixtures\Record;
use Noldors\LighthouseExtensions\Tests\Support\GraphqlTestCase;

final class MutationLockTest extends GraphqlTestCase
{
    public function testParentAndTargetAreLockedBeforeValidationAndHandler(): void
    {
        $parent = Record::query()->create(['title' => 'parent']);
        $child = Record::query()->create(['title' => 'child', 'parent_id' => $parent->id]);
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation { change(input: LockInput! @spread): String @mock @lockForUpdate(model: "Record", argument: "input.id", parent: "parent") }
                input LockInput { id: SerialID! @rules(apply: ["exists:extension_records,id"]) }
                GRAPHQL,
        );
        $queries = [];
        DB::listen(
            static function (QueryExecuted $event) use (&$queries): void {
                if (\str_contains($event->sql, 'for update') || \str_contains($event->sql, 'count(*)')) {
                    $queries[] = $event->sql;
                }
            },
        );
        $level = DB::transactionLevel();
        $this->mockResolver(
            static function () use ($child, $level): string {
                self::assertSame($level + 1, DB::transactionLevel());
                $child->update(['title' => 'updated']);

                return 'done';
            },
        );
        $this->graphQL('mutation ($input: LockInput!) { change(input: $input) }', ['input' => ['id' => $child->id]])
            ->assertGraphQLErrorFree();
        $this->assertCount(3, $queries);
        $this->assertStringContainsString('select "extension_records"."id"', $queries[0]);
        $this->assertStringContainsString('for update', $queries[0]);
        $this->assertStringContainsString('for update', $queries[1]);
        $this->assertStringContainsString('count(*)', $queries[2]);
        $this->assertSame($level, DB::transactionLevel());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testHandlerFailureOrDeferredResultRollsBack(bool $deferred): void
    {
        $record = Record::query()->create(['title' => 'before']);
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation { change(id: SerialID!): String @mock @lockForUpdate(model: "Record", argument: "id") }
                GRAPHQL,
        );
        $this->mockResolver(
            static function () use ($record, $deferred): mixed {
                $record->update(['title' => 'after']);

                if ($deferred) {
                    return new Deferred(static fn (): string => 'late');
                }

                throw new RuntimeException('fixture failure');
            },
        );
        $result = $this->graphQL('mutation ($id: SerialID!) { change(id: $id) }', ['id' => $record->id]);
        $this->assertNotEmpty($result->json('errors'));
        $this->assertDatabaseHas('extension_records', ['id' => $record->id, 'title' => 'before']);
    }

    public function testNonnumericSelectorRunsNativePipelineInsideTransactionWithoutRowLocks(): void
    {
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation { change(id: ID!): String @mock @lockForUpdate(model: "Record", argument: "id") }
                GRAPHQL,
        );
        $locks = [];
        DB::listen(
            static function (QueryExecuted $event) use (&$locks): void {
                if (\str_contains($event->sql, 'for update')) {
                    $locks[] = $event->sql;
                }
            },
        );
        $level = DB::transactionLevel();
        $executions = 0;
        $this->mockResolver(
            static function (mixed $root, array $args) use ($level, &$executions): string {
                ++$executions;
                self::assertSame($level + 1, DB::transactionLevel());
                self::assertSame('nonnumeric', $args['id']);

                return 'native result';
            },
        );

        $this->graphQL('mutation { change(id: "nonnumeric") }')
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.change', 'native result');
        $this->assertSame(1, $executions);
        $this->assertSame([], $locks);
        $this->assertSame($level, DB::transactionLevel());
    }

    public function testMalformedSelectorIsRejectedDuringSchemaBuild(): void
    {
        $this->schema = 'type Query { noop: Boolean @mock } type Mutation { change(id: ID!): String @mock @lockForUpdate(model: "Record", argument: "missing") }';
        $this->setUpTestSchema();
        $this->expectException(DefinitionException::class);
        $this->application()->make(SchemaBuilder::class)->schema();
    }
}
