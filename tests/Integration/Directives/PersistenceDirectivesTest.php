<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Integration\Directives;

use RuntimeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\TestWith;
use Noldors\LighthouseExtensions\Tests\Fixtures\Record;
use Noldors\LighthouseExtensions\Tests\Fixtures\RecordUpdated;
use Noldors\LighthouseExtensions\Tests\Support\GraphqlTestCase;
use Noldors\LighthouseExtensions\Tests\Fixtures\SynchronousRecord;
use Noldors\LighthouseExtensions\Tests\Fixtures\SynchronousRecordUpdated;

final class PersistenceDirectivesTest extends GraphqlTestCase
{
    public function testSingleAndBatchDeleteReturnKeysAndPersistDeletion(): void
    {
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation {
                    delete(id: SerialID! @whereKey): SerialID @delete(model: "Record")
                    many(ids: [SerialID!]! @whereKey): [SerialID!]! @delete(model: "Record")
                }
                GRAPHQL,
        );
        $deleted = [];
        Record::deleted(
            static function (Record $record) use (&$deleted): void {
                $deleted[] = $record->id;
            },
        );
        $first = Record::query()->create(['title' => 'first']);
        $second = Record::query()->create(['title' => 'second']);
        $this->graphQL('mutation ($id: SerialID!) { delete(id: $id) }', ['id' => $first->id])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.delete', (string) $first->id);
        $this->assertModelMissing($first);
        $this->graphQL('mutation ($ids: [SerialID!]!) { many(ids: $ids) }', ['ids' => [$second->id]])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.many', [(string) $second->id]);
        $this->assertModelMissing($second);
        $this->assertSame([$first->id, $second->id], $deleted);
    }

    public function testDeniedDeleteLeavesRecordUnchanged(): void
    {
        $this->actingAs(new User());
        Gate::define('delete', static fn (): bool => false);
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation {
                    delete(id: SerialID! @whereKey): SerialID @delete(model: "Record")
                        @canFind(ability: "delete", find: "id", model: "Record")
                }
                GRAPHQL,
        );
        $record = Record::query()->create(['title' => 'retained']);
        $this->graphQL('mutation ($id: SerialID!) { delete(id: $id) }', ['id' => $record->id])
            ->assertGraphQLErrorMessage('This action is unauthorized.');
        $this->assertModelExists($record);
    }

    public function testUpdatePublishesOnceForScalarRelationOnlyAndUnchangedWrites(): void
    {
        $this->freezeTime();
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation { update(input: UpdateInput! @spread): Record! @update }
                type Record { id: SerialID!, title: String, children: [Record!]! @hasMany }
                input UpdateInput { id: SerialID!, title: String, children: ChildrenInput }
                input ChildrenInput { create: [ChildInput!] }
                input ChildInput { title: String }
                GRAPHQL,
        );
        $record = Record::query()->create(['title' => 'before']);
        Event::fake([RecordUpdated::class]);

        foreach ([['title' => 'after'], ['children' => ['create' => [['title' => 'child']]]], []] as $input) {
            $this->graphQL('mutation ($input: UpdateInput!) { update(input: $input) { id } }', [
                'input' => ['id' => $record->id, ...$input],
            ])->assertGraphQLErrorFree();
        }
        Event::assertDispatchedTimes(RecordUpdated::class, 3);
        $this->assertDatabaseHas('extension_records', ['id' => $record->id, 'title' => 'after']);
        $this->assertSame(1, $record->children()->count());
    }

    #[TestWith([true, 0])]
    #[TestWith([false, 1])]
    public function testSynchronousMappedListenerFailureUsesNativeTransactionSetting(bool $transactional, int $remainingChildren): void
    {
        $this->freezeTime();
        \config(['lighthouse.transactional_mutations' => $transactional]);
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation { update(input: UpdateInput! @spread): Record! @update(model: "SynchronousRecord") }
                type Record { id: SerialID! }
                input UpdateInput { id: SerialID!, children: ChildrenInput }
                input ChildrenInput { create: [ChildInput!] }
                input ChildInput { title: String }
                GRAPHQL,
        );
        $record = SynchronousRecord::query()->create(['title' => 'before']);
        $listenerCalls = 0;
        Event::listen(SynchronousRecordUpdated::class, static function () use (&$listenerCalls): never {
            ++$listenerCalls;

            throw new RuntimeException('Synchronous mapped listener failed.');
        });
        $this->assertSame(0, DB::transactionLevel());
        $response = $this->graphQL('mutation ($input: UpdateInput!) { update(input: $input) { id } }', [
            'input' => ['id' => $record->getKey(), 'children' => ['create' => [['title' => 'child']]]],
        ]);
        $response->assertGraphQLErrorMessage('Internal server error');
        $this->assertSame(['update'], $response->json('errors.0.path'));
        $this->assertSame(1, $listenerCalls);
        $this->assertSame($remainingChildren, $record->children()->count());
        $this->assertDatabaseHas('extension_records', ['id' => $record->getKey(), 'title' => 'before']);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testVetoedDirtyScalarUpdateDoesNotDispatchSyntheticUpdatedEvent(): void
    {
        $this->freezeTime();
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation { update(input: UpdateInput! @spread): Record! @update(model: "SynchronousRecord") }
                type Record { id: SerialID!, title: String }
                input UpdateInput { id: SerialID!, title: String }
                GRAPHQL,
        );
        $record = SynchronousRecord::query()->create(['title' => 'before']);
        $vetoCalls = 0;
        SynchronousRecord::updating(
            static function () use (&$vetoCalls): bool {
                ++$vetoCalls;

                return false;
            },
        );
        Event::fake([SynchronousRecordUpdated::class]);
        $this->graphQL('mutation ($input: UpdateInput!) { update(input: $input) { id title } }', [
            'input' => ['id' => $record->getKey(), 'title' => 'rejected'],
        ])->assertGraphQLErrorFree()->assertJsonPath('data.update.title', 'before');
        $this->assertSame(1, $vetoCalls);
        Event::assertNotDispatched(SynchronousRecordUpdated::class);
        $this->assertDatabaseHas('extension_records', ['id' => $record->getKey(), 'title' => 'before']);
    }

    public function testRelationOnlyMappedAfterCommitEventObservesCommittedChildren(): void
    {
        $this->freezeTime();
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation { update(input: UpdateInput! @spread): Record! @update }
                type Record { id: SerialID! }
                input UpdateInput { id: SerialID!, children: ChildrenInput }
                input ChildrenInput { create: [ChildInput!] }
                input ChildInput { title: String }
                GRAPHQL,
        );
        $record = Record::query()->create(['title' => 'before']);
        $observed = [];
        Event::listen(RecordUpdated::class, static function (RecordUpdated $event) use (&$observed): void {
            $observed[] = [DB::transactionLevel(), $event->record->getKey()];
        });
        $this->graphQL('mutation ($input: UpdateInput!) { update(input: $input) { id } }', [
            'input' => ['id' => $record->getKey(), 'children' => ['create' => [['title' => 'child']]]],
        ])->assertGraphQLErrorFree();
        $this->assertSame([[0, $record->getKey()]], $observed);
        $this->assertSame(1, $record->children()->count());
    }

    public function testMissingDeleteReturnsNullAndMappedUpdateEventRollsBack(): void
    {
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation {
                    delete(id: SerialID! @whereKey): SerialID @delete(model: "Record")
                    update(input: UpdateInput! @spread): Record! @update
                }
                type Record { id: SerialID!, title: String }
                input UpdateInput { id: SerialID!, title: String }
                GRAPHQL,
        );
        $this->graphQL('mutation { delete(id: 99999) }')->assertGraphQLErrorFree()->assertJsonPath('data.delete', null);
        $record = Record::query()->create(['title' => 'before']);
        Event::fake([RecordUpdated::class]);
        DB::beginTransaction();
        $this->graphQL('mutation ($input: UpdateInput!) { update(input: $input) { id } }', [
            'input' => ['id' => $record->id, 'title' => 'after'],
        ])->assertGraphQLErrorFree();
        DB::rollBack();
        Event::assertNotDispatched(RecordUpdated::class);
        $this->assertDatabaseHas('extension_records', ['id' => $record->id, 'title' => 'before']);
    }
}
