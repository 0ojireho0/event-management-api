<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormField extends Model
{
    protected $fillable = [
        'registration_form_id', 'key', 'system_key', 'type', 'label', 'description',
        'placeholder', 'is_required', 'position', 'validation_rules', 'settings',
    ];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'validation_rules' => 'array', 'settings' => 'array'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(RegistrationForm::class, 'registration_form_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(FieldOption::class)->orderBy('position');
    }
}
