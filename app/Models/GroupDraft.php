<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GroupDraft extends Model
{
    use HasUuids, SoftDeletes;

    // Only domain services supply these attributes. HTTP requests have their own
    // stricter allowlist and never mass-assign owner, version or publication state.
    protected $fillable = ['owner_id', 'data', 'version', 'status', 'published_group_id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'version' => 'integer'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
