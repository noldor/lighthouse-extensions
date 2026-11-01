<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Integration\Scalars;

use GraphQL\Error\Error;
use Illuminate\Support\Carbon;
use GraphQL\Language\AST\StringValueNode;
use PHPUnit\Framework\Attributes\TestWith;
use Noldors\LighthouseExtensions\Scalars\Date;
use PHPUnit\Framework\Attributes\DataProvider;
use Noldors\LighthouseExtensions\Tests\TestCase;
use Noldors\LighthouseExtensions\Scalars\DateTime;

final class DateScalarsTest extends TestCase
{
    public function testPackageFormatsIgnoreApplicationFormatsAndReadCurrentConfiguration(): void
    {
        \config([
            'app.date_format' => 'd.m.Y',
            'app.datetime_format' => 'd.m.Y H:i',
            'app.timezone' => 'Pacific/Auckland',
            'lighthouse-extensions.date_format' => 'd/m/Y',
            'lighthouse-extensions.datetime_format' => 'Y-m-d H:i:s',
            'lighthouse-extensions.timezone' => 'Europe/Moscow',
        ]);
        $date = new Date();
        $dateTime = new DateTime();
        $source = Carbon::parse('2026-10-06T12:00:00Z');

        $this->assertSame('06/10/2026', $date->serialize($source));
        $this->assertSame('2026-10-06 15:00:00', $dateTime->serialize($source));
        $this->assertSame('2026-10-06T09:00:00+00:00', $dateTime->parseValue('2026-10-06 12:00:00')->toIso8601String());
        $this->assertSame('2026-10-06T12:00:00+00:00', $source->toIso8601String());
        \config(['lighthouse-extensions.datetime_format' => 'd/m/Y H:i']);
        $this->assertSame('06/10/2026 15:00', $dateTime->serialize($source));
    }

    public function testDateTimeDoesNotAcceptArbitraryFormattedOutputAsInput(): void
    {
        \config(['lighthouse-extensions.datetime_format' => 'Y-m-d\\TH:i:s.uP']);
        $this->expectException(Error::class);
        new DateTime()->parseValue('06/10/2026 15:00');
    }

    #[TestWith(['2026-10-06'])]
    #[TestWith(['2026-10-6'])]
    public function testDateParsesVariablesAndLiteralsAtUtcMidnightAndSerializesDate(string $value): void
    {
        \config(['lighthouse-extensions.date_format' => 'Y-m-d']);
        $scalar = new Date();

        $date = $scalar->parseValue($value);
        $literal = $scalar->parseLiteral(new StringValueNode(['value' => $value]));

        $this->assertSame('2026-10-06T00:00:00+00:00', $date->toIso8601String());
        $this->assertSame($date->toIso8601String(), $literal->toIso8601String());
        $this->assertSame('2026-10-06', $scalar->serialize(Carbon::parse('2026-10-06T12:00:00Z')));
    }

    #[TestWith(['2026-10-06T15:00:00.000000+03:00'])]
    #[TestWith(['2026-10-06T15:00:00.000000+0300'])]
    #[TestWith(['2026-10-06T12:00:00.0Z'])]
    public function testDateTimeNormalizesTimezoneForVariablesAndLiterals(string $value): void
    {
        \config([
            'lighthouse-extensions.datetime_format' => 'Y-m-d\\TH:i:s.uP',
            'lighthouse-extensions.timezone' => 'UTC',
        ]);
        $scalar = new DateTime();

        $date = $scalar->parseValue($value);
        $literal = $scalar->parseLiteral(new StringValueNode(['value' => $value]));

        $this->assertSame('2026-10-06T12:00:00+00:00', $date->toIso8601String());
        $this->assertSame($date->toIso8601String(), $literal->toIso8601String());
        $this->assertSame('2026-10-06T12:00:00.000000+00:00', $scalar->serialize($date));
    }

    public function testDateTimeAllowsOffsetLikeTextInEscapedFormatLiterals(): void
    {
        \config(['lighthouse-extensions.datetime_format' => 'Y-m-d\\TH:i:s.uP \\+99:99']);
        $scalar = new DateTime();
        $value = '2026-10-06T15:00:00.000000+03:00 +99:99';

        $date = $scalar->parseValue($value);
        $literal = $scalar->parseLiteral(new StringValueNode(['value' => $value]));

        $this->assertSame('2026-10-06T12:00:00+00:00', $date->toIso8601String());
        $this->assertSame($date->toIso8601String(), $literal->toIso8601String());
    }

    #[TestWith(['+00:59', '2026-10-10T11:01:00+00:00'], 'highest valid offset minutes')]
    #[TestWith(['+000059', '2026-10-10T11:59:01+00:00'], 'highest valid offset seconds')]
    public function testDateTimeAcceptsHighestValidOffsetComponents(string $offset, string $expected): void
    {
        \config([
            'lighthouse-extensions.datetime_format' => 'Y-m-d\\TH:i:s.uP',
            'lighthouse-extensions.timezone' => 'UTC',
        ]);
        $scalar = new DateTime();
        $value = '2026-10-10T12:00:00.000000' . $offset;

        $date = $scalar->parseValue($value);
        $literal = $scalar->parseLiteral(new StringValueNode(['value' => $value]));

        $this->assertSame($expected, $date->toIso8601String());
        $this->assertSame($expected, $literal->toIso8601String());
    }

    #[TestWith(['+03:00', '2099-10-10T09:00:00+00:00'])]
    #[TestWith(['+00:00', '2099-10-10T12:00:00+00:00'])]
    public function testDateTimeAllowsOffsetLikeNumericDateFields(string $offset, string $expected): void
    {
        \config([
            'lighthouse-extensions.datetime_format' => '\\+d:y Y-m-d\\TH:i:s.uP',
            'lighthouse-extensions.timezone' => 'UTC',
        ]);
        $scalar = new DateTime();
        $value = '+10:99 2099-10-10T12:00:00.000000' . $offset;

        $date = $scalar->parseValue($value);
        $literal = $scalar->parseLiteral(new StringValueNode(['value' => $value]));

        $this->assertSame($expected, $date->toIso8601String());
        $this->assertSame($expected, $literal->toIso8601String());
    }

    /**
     * @param class-string<Date|DateTime> $class
     */
    #[TestWith([Date::class, 'Date format must match Y-m-d'])]
    #[TestWith([DateTime::class, 'DateTime format must match Y-m-d\\TH:i:s.uP'])]
    public function testInvalidFormatReturnsUsefulError(string $class, string $message): void
    {
        \config([
            'lighthouse-extensions.date_format' => 'Y-m-d',
            'lighthouse-extensions.datetime_format' => 'Y-m-d\\TH:i:s.uP',
        ]);
        $this->expectException(Error::class);
        $this->expectExceptionMessageIs($message);

        new $class()->parseValue('not-a-date');
    }

    /**
     * @param class-string<Date|DateTime> $class
     */
    #[DataProvider('invalidDateComponents')]
    public function testInvalidDateComponentsAreRejectedForVariables(string $class, string $value, string $message, string $dateTimeFormat = 'Y-m-d\\TH:i:s.uP'): void
    {
        \config([
            'lighthouse-extensions.date_format' => 'Y-m-d',
            'lighthouse-extensions.datetime_format' => $dateTimeFormat,
        ]);
        $this->expectException(Error::class);
        $this->expectExceptionMessageIs($message);

        new $class()->parseValue($value);
    }

    /**
     * @param class-string<Date|DateTime> $class
     */
    #[DataProvider('invalidDateComponents')]
    public function testInvalidDateComponentsAreRejectedForLiterals(string $class, string $value, string $message, string $dateTimeFormat = 'Y-m-d\\TH:i:s.uP'): void
    {
        \config([
            'lighthouse-extensions.date_format' => 'Y-m-d',
            'lighthouse-extensions.datetime_format' => $dateTimeFormat,
        ]);
        $this->expectException(Error::class);
        $this->expectExceptionMessageIs($message);

        new $class()->parseLiteral(new StringValueNode(['value' => $value]));
    }

    /**
     * @return iterable<string, array{0: class-string<Date|DateTime>, 1: string, 2: string, 3?: string}>
     */
    public static function invalidDateComponents(): iterable
    {
        yield from [
            'invalid calendar date' => [Date::class, '2026-02-30', 'Date format must match Y-m-d'],
            'invalid calendar datetime' => [
                DateTime::class,
                '2026-02-30T12:00:00.000000+00:00',
                'DateTime format must match Y-m-d\\TH:i:s.uP',
            ],
            'overflowing time' => [
                DateTime::class,
                '2026-10-10T25:61:00.000000+00:00',
                'DateTime format must match Y-m-d\\TH:i:s.uP',
            ],
            'invalid offset hours' => [
                DateTime::class,
                '2026-10-10T12:00:00.000000+99:99',
                'DateTime format must match Y-m-d\\TH:i:s.uP',
            ],
            'invalid offset minutes' => [
                DateTime::class,
                '2026-10-10T12:00:00.000000+00:99',
                'DateTime format must match Y-m-d\\TH:i:s.uP',
            ],
            'offset minutes at invalid boundary' => [
                DateTime::class,
                '2026-10-10T12:00:00.000000+00:60',
                'DateTime format must match Y-m-d\\TH:i:s.uP',
            ],
            'invalid compact offset minutes' => [
                DateTime::class,
                '2026-10-10T12:00:00.000000+009900',
                'DateTime format must match Y-m-d\\TH:i:s.uP',
            ],
            'invalid compact offset seconds' => [
                DateTime::class,
                '2026-10-10T12:00:00.000000+005999',
                'DateTime format must match Y-m-d\\TH:i:s.uP',
            ],
            'offset seconds at invalid boundary' => [
                DateTime::class,
                '2026-10-10T12:00:00.000000+000060',
                'DateTime format must match Y-m-d\\TH:i:s.uP',
            ],
            'invalid offset before date' => [
                DateTime::class,
                '+00:99 2026-10-10T12:00:00.000000',
                'DateTime format must match P Y-m-d\\TH:i:s.u',
                'P Y-m-d\\TH:i:s.u',
            ],
            'valid literal before invalid timezone' => [
                DateTime::class,
                '2026-10-10T12:00:00.000000+03:00 +00:99',
                'DateTime format must match Y-m-d\\TH:i:s.u\\+03:00 P',
                'Y-m-d\\TH:i:s.u\\+03:00 P',
            ],
        ];
    }

    public function testDateWithNullByteReturnsCoercionError(): void
    {
        \config(['lighthouse-extensions.date_format' => 'Y-m-d']);
        $this->expectException(Error::class);
        $this->expectExceptionMessageIs('Date format must match Y-m-d');

        new Date()->parseValue("2026-10-06\0");
    }

    public function testDatePreservesCalendarDayWhenPhpTimezoneIsNotUtc(): void
    {
        \config(['lighthouse-extensions.date_format' => 'Y-m-d', 'lighthouse-extensions.timezone' => 'Europe/Moscow']);
        $originalTimezone = \date_default_timezone_get();

        try {
            \date_default_timezone_set('Europe/Moscow');
            $scalar = new Date();
            $date = $scalar->parseValue('2026-10-06');
            $literal = $scalar->parseLiteral(
                new StringValueNode(['value' => '2026-10-06']),
            );

            $this->assertSame('2026-10-06T00:00:00+00:00', $date->toIso8601String());
            $this->assertSame($date->toIso8601String(), $literal->toIso8601String());
            $this->assertSame('2026-10-06', $scalar->serialize($date));
        } finally {
            \date_default_timezone_set($originalTimezone);
        }
    }
}
