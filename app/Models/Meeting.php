<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Meeting extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'title',
        'status',
        'host_token',
        'password',
        'is_locked',
        'max_participants',
        'expires_at',
        'ended_at',
    ];

    protected $hidden = [
        'password',
        'host_token',
    ];

    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'max_participants' => 'integer',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Meeting $meeting) {
            $meeting->uuid ??= (string) Str::uuid();
            $meeting->host_token ??= (string) Str::uuid();
            $meeting->status ??= 'active';
        });
    }

    /**
     * Meetings are looked up by UUID everywhere outside the database,
     * so route binding should never leak the internal auto-increment id.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    public function activeParticipants(): HasMany
    {
        return $this->participants()->whereNull('left_at');
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isFull(): bool
    {
        return $this->activeParticipants()->count() >= $this->max_participants;
    }

    public function isHost(?string $hostToken): bool
    {
        return $hostToken !== null && hash_equals($this->host_token, $hostToken);
    }
}
