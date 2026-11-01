<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Unit\Scalars;

use GraphQL\Error\Error;
use GraphQL\Language\Parser;
use GraphQL\Error\SerializationError;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\StringValueNode;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\DataProvider;
use Noldors\LighthouseExtensions\Tests\TestCase;
use Noldors\LighthouseExtensions\Scalars\SerialID;

final class SerialIDTest extends TestCase
{
    #[DataProvider('validIds')]
    public function testParsesVariablesAndLiteralsAsIntegersAndSerializesAsStrings(int|string $value, int $expected): void
    {
        $scalar = new SerialID();
        $literal = \is_int($value)
            ? new IntValueNode(['value' => (string) $value])
            : new StringValueNode(['value' => $value]);

        $this->assertSame($expected, $scalar->parseValue($value));
        $this->assertSame($expected, $scalar->parseLiteral($literal));
        $this->assertSame((string) $expected, $scalar->serialize($value));
    }

    /**
     * @return iterable<string, array{int|string, int}>
     */
    public static function validIds(): iterable
    {
        yield 'minimum integer' => [1, 1];

        yield 'minimum string' => ['1', 1];

        yield 'numeric string' => ['42', 42];

        yield 'signed string' => ['+42', 42];

        yield 'whitespace string' => [' 42 ', 42];

        yield 'beyond GraphQL Int' => ['2147483648', 2_147_483_648];

        yield 'maximum integer' => [\PHP_INT_MAX, \PHP_INT_MAX];

        yield 'maximum string' => [(string) \PHP_INT_MAX, \PHP_INT_MAX];
    }

    #[DataProvider('invalidIds')]
    public function testRejectsInvalidVariables(mixed $value): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessageIs(
            'The identifier must be a positive integer within the supported range.',
        );

        new SerialID()->parseValue($value);
    }

    #[DataProvider('invalidIds')]
    public function testRejectsInvalidOutputAsSerializationError(mixed $value): void
    {
        $this->expectException(SerializationError::class);

        new SerialID()->serialize($value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidIds(): iterable
    {
        yield 'zero integer' => [0];

        yield 'zero string' => ['0'];

        yield 'negative integer' => [-1];

        yield 'negative string' => ['-1'];

        yield 'overflow' => ['9223372036854775808'];

        yield 'empty string' => [''];

        yield 'whitespace' => [' '];

        yield 'nonnumeric' => ['bad'];

        yield 'numeric prefix' => ['42bad'];

        yield 'fractional string' => ['1.5'];

        yield 'exponent string' => ['1e3'];

        yield 'fractional float' => [1.5];

        yield 'whole float' => [1.0];

        yield 'boolean true' => [true];

        yield 'boolean false' => [false];

        yield 'null' => [null];

        yield 'array' => [[1]];

        yield 'NUL byte' => ["42\0"];
    }

    #[TestWith(['0'])]
    #[TestWith(['-1'])]
    #[TestWith(['""'])]
    #[TestWith(['"bad"'])]
    #[TestWith(['"0.5"'])]
    #[TestWith(['9223372036854775808'])]
    #[TestWith(['1.0'])]
    #[TestWith(['true'])]
    #[TestWith(['null'])]
    #[TestWith(['[1]'])]
    #[TestWith(['{id: 1}'])]
    public function testRejectsInvalidLiterals(string $literal): void
    {
        $this->expectException(Error::class);

        new SerialID()->parseLiteral(Parser::parseValue($literal));
    }
}
