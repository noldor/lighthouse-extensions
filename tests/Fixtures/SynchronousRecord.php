<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SynchronousRecord extends Model
{
    protected $table = 'extension_records';

    protected $guarded = [];

    protected $dispatchesEvents = ['updated' => SynchronousRecordUpdated::class];

    /**
     * @return HasMany<SynchronousRecord, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
