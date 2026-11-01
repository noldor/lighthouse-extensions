<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Directives;

use Closure;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;

final class InjectValueDirective extends BaseDirective implements FieldMiddleware
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
            "Inject a fixed server-defined string into operation arguments."
            directive @injectValue(name: String!, value: String!) repeatable on FIELD_DEFINITION
            GRAPHQL;
    }

    public function handleField(FieldValue $fieldValue): void
    {
        /** @var string $name */
        $name = $this->directiveArgValue('name');

        /** @var string $value */
        $value = $this->directiveArgValue('value');

        $fieldValue->wrapResolver(
            static fn (callable $resolver): Closure => static function (mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo) use ($name, $value, $resolver): mixed {
                $resolveInfo->argumentSet->addValue($name, $value);

                return $resolver($root, $resolveInfo->argumentSet->toArray(), $context, $resolveInfo);
            },
        );
    }
}
