<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RaffleDraw extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'event_id', 'registration_id', 'selected_by', 'status',
        'selected_at', 'confirmed_at', 'cancelled_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'selected_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function selectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by');
    }

    public function winner(): HasOne
    {
        return $this->hasOne(RaffleWinner::class);
    }
}
