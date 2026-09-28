<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaffleWinner extends Model
{
    protected $fillable = [
        'event_id', 'registration_id', 'raffle_draw_id', 'confirmed_by', 'won_at',
    ];

    protected function casts(): array
    {
        return ['won_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function raffleDraw(): BelongsTo
    {
        return $this->belongsTo(RaffleDraw::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
