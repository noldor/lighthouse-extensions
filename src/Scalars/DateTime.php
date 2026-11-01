<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Scalars;

use Override;
use ValueError;
use DateTimeInterface;
use InvalidArgumentException;
use Illuminate\Support\Carbon;
use GraphQL\Error\InvariantViolation;
use Illuminate\Support\Facades\Config;
use Carbon\Exceptions\InvalidFormatException;
use Nuwave\Lighthouse\Schema\Types\Scalars\DateScalar;
use Noldors\LighthouseExtensions\Support\FormattedDateTime;

final class DateTime extends DateScalar
{
    /**
     * @phpstan-param mixed $value
     */
    #[Override]
    public function serialize(mixed $value): string
    {
        if ($value instanceof FormattedDateTime) {
            return $value->value;
        }

        if (! $value instanceof DateTimeInterface && ! \is_string($value)) {
            throw new InvariantViolation('Query error: Can only parse strings.');
        }

        return parent::serialize($value);
    }

    protected function format(Carbon $carbon): string
    {
        return $carbon->copy()->setTimezone(
            Config::string('lighthouse-extensions.timezone', 'UTC'),
        )->format(
            Config::string('lighthouse-extensions.datetime_format', 'Y-m-d\\TH:i:s.uP'),
        );
    }

    /**
     * @phpstan-param mixed $value
     *
     * @throws InvalidArgumentException
     */
    protected function parse(mixed $value): Carbon
    {
        if (! \is_string($value)) {
            throw new InvalidArgumentException(\__('lighthouse-extensions::messages.datetime_string'));
        }

        $format = Config::string('lighthouse-extensions.datetime_format', 'Y-m-d\\TH:i:s.uP');
        $message = \__('lighthouse-extensions::messages.datetime_format', ['format' => $format]);

        try {
            $date = Carbon::createFromFormat($format, $value, Config::string('lighthouse-extensions.timezone', 'UTC'));
            $errors = Carbon::getLastErrors();
        } catch (InvalidFormatException|ValueError) {
            throw new InvalidArgumentException($message);
        }

        if (null === $date
            || (false !== $errors && (0 < $errors['warning_count'] || 0 < $errors['error_count']))
            || $this->hasInvalidTimezoneOffset($format, $value, $date->getOffset())
        ) {
            throw new InvalidArgumentException($message);
        }

        return $date->utc();
    }

    private function hasInvalidTimezoneOffset(string $format, string $value, int $originalOffset): bool
    {
        \preg_match_all('/[+-]\\d{1,2}:?(?<minutes>\\d{2})(?::?(?<seconds>\\d{2}))?(?!\\d)/', $value, $offsets, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);

        foreach ($offsets as $offset) {
            if (60 > (int) $offset['minutes'][0] && 60 > (int) ($offset['seconds'][0] ?? 0)) {
                continue;
            }

            $replacement = 0 === $originalOffset ? '+01:00' : '+00:00';
            $expectedOffset = 0 === $originalOffset ? 3_600 : 0;
            $probe = \date_parse_from_format($format, \substr_replace($value, $replacement, $offset[0][1], \mb_strlen($offset[0][0])));

            // Only reject offsets consumed as a timezone, preserving numeric text in format literals.
            if (0 === $probe['error_count']
                && 0 === $probe['warning_count']
                && ($probe['zone'] ?? null) === $expectedOffset
            ) {
                return true;
            }
        }

        return false;
    }
}
