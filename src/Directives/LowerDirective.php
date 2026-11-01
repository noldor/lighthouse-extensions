<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Directives;

class LowerDirective extends BaseTransformDirective
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
            """
            Lowercase argument or input-field strings before validation.
            Handle nested objects and lists while preserving all other value types.
            """
            directive @lower on ARGUMENT_DEFINITION | INPUT_FIELD_DEFINITION
            GRAPHQL;
    }

    /**
     * @param mixed $value The client given value
     *
     * @return mixed The transformed value
     */
    protected function transformLeaf(mixed $value): mixed
    {
        if (\is_string($value)) {
            return \mb_strtolower($value);
        }

        return $value;
    }
}
