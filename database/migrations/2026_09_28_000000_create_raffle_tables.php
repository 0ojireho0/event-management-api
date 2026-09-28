<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raffle_draws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->dateTime('selected_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->index(['event_id', 'status', 'expires_at']);
            $table->index(['event_id', 'registration_id']);
        });

        Schema::create('raffle_winners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raffle_draw_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('won_at');
            $table->timestamps();
            $table->unique(['event_id', 'registration_id']);
            $table->index(['event_id', 'won_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raffle_winners');
        Schema::dropIfExists('raffle_draws');
    }
};
