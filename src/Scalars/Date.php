<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Scalars;

use ValueError;
use InvalidArgumentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Carbon\Exceptions\InvalidFormatException;
use Nuwave\Lighthouse\Schema\Types\Scalars\DateScalar;

final class Date extends DateScalar
{
    protected function format(Carbon $carbon): string
    {
        return $carbon->copy()->utc()->format(
            Config::string('lighthouse-extensions.date_format', 'Y-m-d'),
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
            throw new InvalidArgumentException(\__('lighthouse-extensions::messages.date_string'));
        }

        $format = Config::string('lighthouse-extensions.date_format', 'Y-m-d');
        $message = \__('lighthouse-extensions::messages.date_format', ['format' => $format]);

        try {
            $date = Carbon::createFromFormat($format, $value, 'UTC');
            $errors = Carbon::getLastErrors();
        } catch (InvalidFormatException|ValueError) {
            throw new InvalidArgumentException($message);
        }

        if (null === $date || (false !== $errors && (0 < $errors['warning_count'] || 0 < $errors['error_count']))) {
            throw new InvalidArgumentException($message);
        }

        return $date->utc()->startOfDay();
    }
}
