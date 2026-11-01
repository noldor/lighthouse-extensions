<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Support;

/**
 * Carries trusted field formatting to the DateTime scalar without Carbon macros.
 *
 * @internal
 */
final readonly class FormattedDateTime
{
    public function __construct(
        public string $value,
    ) {}
}
