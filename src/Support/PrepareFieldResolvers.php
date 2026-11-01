<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Support;

use WeakMap;
use LogicException;
use GraphQL\Type\Schema;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\FieldDefinition;
use Nuwave\Lighthouse\Events\StartExecution;
use GraphQL\Language\AST\FieldDefinitionNode;
use Nuwave\Lighthouse\Schema\DirectiveLocator;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use Noldors\LighthouseExtensions\Directives\LockForUpdateDirective;

/**
 * @phpstan-import-type FieldResolver from \GraphQL\Executor\Executor as FieldResolverFn
 */
final class PrepareFieldResolvers
{
    /**
     * @var WeakMap<Schema, true>
     */
    private WeakMap $preparedSchemas;

    /**
     * @var WeakMap<FieldDefinition, true>
     */
    private WeakMap $preparedFields;

    public function __construct()
    {
        $this->preparedSchemas = new WeakMap();
        $this->preparedFields = new WeakMap();
    }

    public function handle(StartExecution $event): void
    {
        $schema = $event->schema;

        if (isset($this->preparedSchemas[$schema])) {
            return;
        }

        // Keep only metadata across worker requests; use the current container here.
        $directives = \resolve(DirectiveLocator::class);

        foreach ($schema->getTypeMap() as $type) {
            if (! $type instanceof ObjectType || ! $type->astNode instanceof ObjectTypeDefinitionNode) {
                continue;
            }

            foreach ($type->getFields() as $field) {
                $node = $field->astNode;

                if ($node instanceof FieldDefinitionNode) {
                    $this->prepare($field, $node, $directives);
                }
            }
        }

        $this->preparedSchemas[$schema] = true;
    }

    private function prepare(FieldDefinition $field, FieldDefinitionNode $node, DirectiveLocator $directives): void
    {
        if (isset($this->preparedFields[$field])) {
            return;
        }

        $lock = $directives->exclusiveOfType($node, LockForUpdateDirective::class);

        if ($lock instanceof LockForUpdateDirective) {
            if (! \is_callable($field->resolveFn)) {
                throw new LogicException("Field {$field->name} must have a finished Lighthouse resolver.");
            }

            /** @var FieldResolverFn $resolver */
            $resolver = $field->resolveFn;
            $field->resolveFn = $lock->wrapResolver($resolver);
        }

        $this->preparedFields[$field] = true;
    }
}
