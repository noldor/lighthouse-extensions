<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Scalars;

use Override;
use GraphQL\Error\Error;
use GraphQL\Language\AST\Node;
use GraphQL\Error\SerializationError;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Language\AST\StringValueNode;

final class SerialID extends ScalarType
{
    #[Override]
    public function parseValue(mixed $value): int
    {
        $id = $this->toInteger($value);

        if (false === $id) {
            throw new Error(\__('lighthouse-extensions::messages.serial_id'));
        }

        return $id;
    }

    #[Override]
    public function parseLiteral(Node $valueNode, array|null $variables = null): int
    {
        if (! $valueNode instanceof IntValueNode && ! $valueNode instanceof StringValueNode) {
            throw new Error(\__('lighthouse-extensions::messages.serial_id_type'), [$valueNode]);
        }

        return $this->parseValue($valueNode->value);
    }

    #[Override]
    public function serialize(mixed $value): string
    {
        $id = $this->toInteger($value);

        if (false === $id) {
            throw new SerializationError('Cannot serialize value as SerialID.');
        }

        return (string) $id;
    }

    private function toInteger(mixed $value): false|int
    {
        if (! \is_int($value) && ! \is_string($value)) {
            return false;
        }

        return \filter_var(
            $value,
            \FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
    }
}
