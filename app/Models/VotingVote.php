<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VotingVote extends Model
{
    protected $fillable = ['voting_subject_id', 'voting_contestant_id', 'registration_id'];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(VotingSubject::class, 'voting_subject_id');
    }

    public function contestant(): BelongsTo
    {
        return $this->belongsTo(VotingContestant::class, 'voting_contestant_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
