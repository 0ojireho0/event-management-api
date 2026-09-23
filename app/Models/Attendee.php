<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attendee extends Model
{
    protected $fillable = ['full_name', 'email', 'email_normalized', 'phone', 'company'];

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }
}
