<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 */
final class Record extends Model
{
    protected $table = 'extension_records';

    protected $guarded = [];

    protected $dispatchesEvents = ['updated' => RecordUpdated::class];

    /**
     * @return HasMany<Record, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Record, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
