<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('events')->orderBy('id')->each(function (object $event): void {
            DB::table('events')->where('id', $event->id)->update([
                'slug' => hash('sha256', $event->id.'|'.Str::uuid()->toString()),
            ]);
        });
    }

    public function down(): void
    {
        // Opaque identifiers cannot be safely reversed to the previous title-based slugs.
    }
};
