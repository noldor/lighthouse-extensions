<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Unit\Scalars;

use GraphQL\Error\Error;
use GraphQL\Language\Parser;
use GraphQL\Error\SerializationError;
use GraphQL\Language\AST\StringValueNode;
use PHPUnit\Framework\Attributes\TestWith;
use Noldors\LighthouseExtensions\Scalars\UUID;
use PHPUnit\Framework\Attributes\DataProvider;
use Noldors\LighthouseExtensions\Tests\TestCase;

final class UUIDTest extends TestCase
{
    #[DataProvider('validUuids')]
    public function testAcceptsAnyVersionAndNormalizesInputAndOutput(string $value): void
    {
        $scalar = new UUID();
        $expected = \mb_strtolower($value);

        $this->assertSame($expected, $scalar->parseValue($value));
        $this->assertSame($expected, $scalar->parseLiteral(new StringValueNode(['value' => $value])));
        $this->assertSame($expected, $scalar->serialize($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validUuids(): iterable
    {
        foreach (\range(0, 15) as $version) {
            yield 'version nibble ' . $version => ['F47AC10B-58CC-' . \dechex($version) . '372-A567-0E02B2C3D479'];
        }

        yield 'nil UUID' => ['00000000-0000-0000-0000-000000000000'];

        yield 'max UUID' => ['ffffffff-ffff-ffff-ffff-ffffffffffff'];
    }

    #[DataProvider('invalidUuids')]
    public function testRejectsInvalidInput(mixed $value): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessageIs('The value must be a UUID.');

        new UUID()->parseValue($value);
    }

    #[DataProvider('invalidUuids')]
    public function testRejectsInvalidOutputAsSerializationError(mixed $value): void
    {
        $this->expectException(SerializationError::class);

        new UUID()->serialize($value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidUuids(): iterable
    {
        yield 'null' => [null];

        yield 'integer' => [42];

        yield 'float' => [42.0];

        yield 'boolean' => [true];

        yield 'array' => [['f47ac10b-58cc-4372-a567-0e02b2c3d479']];

        yield 'empty' => [''];

        yield 'malformed' => ['not-a-uuid'];

        yield 'invalid hex' => ['g47ac10b-58cc-4372-a567-0e02b2c3d479'];

        yield 'short' => ['f47ac10b-58cc-4372-a567-0e02b2c3d47'];

        yield 'long' => ['f47ac10b-58cc-4372-a567-0e02b2c3d4790'];

        yield 'no hyphens' => ['f47ac10b58cc4372a5670e02b2c3d479'];

        yield 'braces' => ['{f47ac10b-58cc-4372-a567-0e02b2c3d479}'];

        yield 'URN' => ['urn:uuid:f47ac10b-58cc-4372-a567-0e02b2c3d479'];

        yield 'leading whitespace' => [' f47ac10b-58cc-4372-a567-0e02b2c3d479'];

        yield 'trailing whitespace' => ['f47ac10b-58cc-4372-a567-0e02b2c3d479 '];

        yield 'trailing newline' => ["f47ac10b-58cc-4372-a567-0e02b2c3d479\n"];

        yield 'NUL byte' => ["f47ac10b-58cc-4372-a567-0e02b2c3d479\0"];
    }

    #[TestWith(['42'])]
    #[TestWith(['true'])]
    #[TestWith(['["f47ac10b-58cc-4372-a567-0e02b2c3d479"]'])]
    #[TestWith(['{id: "f47ac10b-58cc-4372-a567-0e02b2c3d479"}'])]
    #[TestWith(['"not-a-uuid"'])]
    public function testRejectsInvalidLiterals(string $literal): void
    {
        $this->expectException(Error::class);

        new UUID()->parseLiteral(Parser::parseValue($literal));
    }
}
