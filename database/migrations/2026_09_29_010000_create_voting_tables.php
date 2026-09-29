<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voting_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 64)->unique();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->index(['event_id', 'status']);
        });

        Schema::create('voting_contestants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voting_subject_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->index(['voting_subject_id', 'display_order']);
        });

        Schema::create('voting_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voting_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voting_contestant_id')->constrained()->restrictOnDelete();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index(['voting_subject_id', 'voting_contestant_id']);
            $table->unique(['voting_subject_id', 'registration_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voting_votes');
        Schema::dropIfExists('voting_contestants');
        Schema::dropIfExists('voting_subjects');
    }
};
