<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final readonly class SynchronousRecordUpdated
{
    public function __construct(
        public Model $record,
    ) {}
}
