<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class RecordUpdated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Model $record,
    ) {}
}
