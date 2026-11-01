<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Directives;

use Closure;
use Override;
use LogicException;
use GraphQL\Deferred;
use GraphQL\Executor\Executor;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Language\AST\NamedTypeNode;
use Illuminate\Database\Eloquent\Model;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use Nuwave\Lighthouse\Schema\AST\DocumentAST;
use GraphQL\Language\AST\InputValueDefinitionNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nuwave\Lighthouse\Exceptions\DefinitionException;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use GraphQL\Language\AST\InputObjectTypeDefinitionNode;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Nuwave\Lighthouse\Support\Contracts\FieldManipulator;

/**
 * @phpstan-import-type FieldResolver from \GraphQL\Executor\Executor as FieldResolverFn
 */
final class LockForUpdateDirective extends BaseDirective implements FieldManipulator
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
            """
            Execute validation and a synchronous mutation inside a transaction,
            locking the selected numeric-ID row before the native field pipeline.
            The operation must validate the identifier.
            """
            directive @lockForUpdate(
                "Model class, resolved through Lighthouse's model namespaces."
                model: String!
                "Dot-separated identifier path in the original field arguments."
                argument: String!
                """
                Immutable belongsTo or hasOne relation to lock before the target row.
                Both models must use the same database connection.
                """
                parent: String
            ) on FIELD_DEFINITION
            GRAPHQL;
    }

    #[Override]
    public function manipulateFieldDefinition(
        DocumentAST &$documentAST,
        FieldDefinitionNode &$fieldDefinition,
        InterfaceTypeDefinitionNode|ObjectTypeDefinitionNode &$parentType,
    ): void {
        /** @var string $argument */
        $argument = $this->directiveArgValue('argument');
        $location = $parentType->name->value . '.' . $fieldDefinition->name->value;

        if (1 !== \preg_match('/^[_A-Za-z][_0-9A-Za-z]*(?:\\.[_A-Za-z][_0-9A-Za-z]*)*$/D', $argument)) {
            throw new DefinitionException("The argument path for @lockForUpdate on {$location} must contain dot-separated GraphQL names.");
        }

        $segments = \explode('.', $argument);
        $fields = $fieldDefinition->arguments;

        foreach ($segments as $index => $segment) {
            $typeName = $this->argumentTypeName(
                $fields,
                $segment,
                $argument,
                $location,
            );
            $definition = $documentAST->types[$typeName] ?? null;

            if (\count($segments) - 1 === $index) {
                if (\in_array($typeName, ['ID', 'Int', 'String', 'Float', 'Boolean'], true)
                    || $definition instanceof ScalarTypeDefinitionNode
                ) {
                    return;
                }

                throw new DefinitionException("The argument path '{$argument}' for @lockForUpdate on {$location} must select a scalar identifier.");
            }

            if (! $definition instanceof InputObjectTypeDefinitionNode) {
                throw new DefinitionException("The argument path '{$argument}' for @lockForUpdate on {$location} can only traverse input objects.");
            }

            $fields = $definition->fields;
        }
    }

    /**
     * @param FieldResolverFn $resolver
     */
    public function wrapResolver(callable $resolver): Closure
    {
        $modelClass = $this->getModelClass();
        $parent = $this->parentRelation();

        /** @var string $argument */
        $argument = $this->directiveArgValue('argument');

        return $this->transactionalResolver(
            $resolver,
            $modelClass,
            $argument,
            $parent,
        );
    }

    /**
     * @param BelongsTo<Model, Model>|HasOne<Model, Model> $parent
     */
    private function lockParent(Model $model, int $id, BelongsTo|HasOne $parent): void
    {
        [$relatedKey, $targetKey] = $parent instanceof BelongsTo
            ? [$parent->getQualifiedOwnerKeyName(), $parent->getQualifiedForeignKeyName()]
            : [$parent->getQualifiedForeignKeyName(), $parent->getQualifiedParentKeyName()];
        $related = $parent->getRelated();
        $related->newQuery()
            ->where($relatedKey, $model->newQuery()->whereKey($id)->select($targetKey))
            ->lockForUpdate()
            ->toBase()
            ->first([$related->getQualifiedKeyName()]);
    }

    /**
     * @param FieldResolverFn $resolver
     * @param class-string<Model> $modelClass
     * @param BelongsTo<Model, Model>|HasOne<Model, Model>|null $parent
     */
    private function transactionalResolver(
        callable $resolver,
        string $modelClass,
        string $argument,
        BelongsTo|HasOne|null $parent,
    ): Closure {
        $lockedResolver = $this->lockedResolver(...);

        return static function (
            mixed $root,
            array $args,
            GraphQLContext $context,
            ResolveInfo $resolveInfo,
        ) use ($resolver, $modelClass, $argument, $parent, $lockedResolver): mixed {
            /** @var array<string, mixed> $args */
            if ('mutation' !== $resolveInfo->operation->operation) {
                throw new LogicException('@lockForUpdate works on mutations only.');
            }

            $model = new $modelClass();
            $value = \data_get($args, $argument);
            $id = \is_int($value) || \is_string($value)
                ? \filter_var($value, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;

            return $model->getConnection()
                ->transaction(
                    $lockedResolver(
                        $model,
                        $id,
                        $parent,
                        $resolver,
                        $root,
                        $args,
                        $context,
                        $resolveInfo,
                    ),
                );
        };
    }

    /**
     * @param BelongsTo<Model, Model>|HasOne<Model, Model>|null $parent
     * @param FieldResolverFn $resolver
     * @param array<string, mixed> $args
     *
     * @return Closure(): mixed
     */
    private function lockedResolver(
        Model $model,
        false|int $id,
        BelongsTo|HasOne|null $parent,
        callable $resolver,
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo,
    ): Closure {
        $lockParent = $this->lockParent(...);

        return static function () use ($model, $id, $parent, $resolver, $root, $args, $context, $resolveInfo, $lockParent): mixed {
            if (false !== $id) {
                if (null !== $parent) {
                    $lockParent($model, $id, $parent);
                }

                $model->newQuery()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->toBase()
                    ->first([$model->getQualifiedKeyName()]);
            }

            $result = $resolver($root, $args, $context, $resolveInfo);

            if ($result instanceof Deferred
                || $result instanceof Promise
                || Executor::getDefaultPromiseAdapter()->isThenable($result)
            ) {
                throw new LogicException('@lockForUpdate requires a synchronous resolver.');
            }

            return $result;
        };
    }

    /**
     * @return BelongsTo<Model, Model>|HasOne<Model, Model>|null
     */
    private function parentRelation(): BelongsTo|HasOne|null
    {
        /** @var string|null $name */
        $name = $this->directiveArgValue('parent');

        if (null === $name) {
            return null;
        }

        $modelClass = $this->getModelClass();
        $model = new $modelClass();
        $relation = $model->isRelation($name) ? $model->{$name}() : null;

        if ((! $relation instanceof BelongsTo && ! $relation instanceof HasOne) || $relation instanceof MorphTo) {
            throw new DefinitionException("The parent '{$name}' for @lockForUpdate must be a belongsTo or hasOne relation on {$modelClass}.");
        }

        if ($model->getConnection() !== $relation->getRelated()->getConnection()) {
            throw new DefinitionException('The parent for @lockForUpdate must use the same database connection as the target.');
        }

        return $relation;
    }

    /**
     * @param iterable<int, InputValueDefinitionNode> $fields
     */
    private function argumentTypeName(iterable $fields, string $segment, string $argument, string $location): string
    {
        $selected = null;

        foreach ($fields as $field) {
            if ($field->name->value === $segment) {
                $selected = $field;

                break;
            }
        }

        if (null === $selected) {
            throw new DefinitionException("The argument path '{$argument}' for @lockForUpdate on {$location} does not exist in the original GraphQL arguments.");
        }

        $type = $selected->type;

        if ($type instanceof NonNullTypeNode) {
            $type = $type->type;
        }

        if (! $type instanceof NamedTypeNode) {
            throw new DefinitionException("The argument path '{$argument}' for @lockForUpdate on {$location} must not select or traverse a list.");
        }

        return $type->name->value;
    }
}
