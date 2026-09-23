<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckIn extends Model
{
    protected $fillable = ['registration_id', 'checked_in_by', 'checked_in_at', 'gate', 'device_id', 'result', 'metadata'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime', 'metadata' => 'array'];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }
}
