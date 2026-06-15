<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // Store translated transcripts with same format as speakers_data (array of utterances)
            $table->json('transcript_urdu')->nullable()->after('speakers_data');
            $table->json('transcript_english')->nullable()->after('transcript_urdu');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['transcript_urdu', 'transcript_english']);
        });
    }
};
