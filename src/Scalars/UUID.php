<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Scalars;

use Override;
use GraphQL\Error\Error;
use Illuminate\Support\Str;
use GraphQL\Language\AST\Node;
use GraphQL\Error\SerializationError;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Language\AST\StringValueNode;

final class UUID extends ScalarType
{
    #[Override]
    public function parseValue(mixed $value): string
    {
        $uuid = $this->normalize($value);

        if (null === $uuid) {
            throw new Error(\__('lighthouse-extensions::messages.uuid'));
        }

        return $uuid;
    }

    #[Override]
    public function parseLiteral(Node $valueNode, array|null $variables = null): string
    {
        if (! $valueNode instanceof StringValueNode) {
            throw new Error(\__('lighthouse-extensions::messages.uuid_string'), [$valueNode]);
        }

        return $this->parseValue($valueNode->value);
    }

    #[Override]
    public function serialize(mixed $value): string
    {
        $uuid = $this->normalize($value);

        if (null === $uuid) {
            throw new SerializationError('Cannot serialize value as UUID.');
        }

        return $uuid;
    }

    private function normalize(mixed $value): string|null
    {
        if (! \is_string($value) || ! Str::isUuid($value)) {
            return null;
        }

        return \mb_strtolower($value);
    }
}
