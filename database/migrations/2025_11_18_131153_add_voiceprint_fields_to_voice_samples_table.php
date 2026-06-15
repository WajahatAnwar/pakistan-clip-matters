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
        Schema::table('voice_samples', function (Blueprint $table) {
            $table->string('pyannote_job_id')->nullable()->after('duration');
            $table->text('voiceprint')->nullable()->after('pyannote_job_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('voice_samples', function (Blueprint $table) {
            $table->dropColumn(['pyannote_job_id', 'voiceprint']);
        });
    }
};
