<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Implements Authenticatable solely so this model can be returned by the
 * "participant" broadcasting guard (see config/auth.php) — it is never
 * used for a real login session.
 */
class Participant extends Model implements AuthenticatableContract
{
    use Authenticatable, HasFactory;

    protected $fillable = [
        'meeting_id',
        'participant_id',
        'display_name',
        'is_host',
        'is_muted',
        'is_camera_off',
        'joined_at',
        'last_seen_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'is_host' => 'boolean',
            'is_muted' => 'boolean',
            'is_camera_off' => 'boolean',
            'joined_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('left_at');
    }

    public function hasLeft(): bool
    {
        return $this->left_at !== null;
    }
}
