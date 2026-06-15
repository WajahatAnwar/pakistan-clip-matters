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
            $table->json('diarization_data')->nullable()->after('summary');
            $table->json('identification_data')->nullable()->after('diarization_data');
            $table->string('pyannote_job_id')->nullable()->after('identification_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['diarization_data', 'identification_data', 'pyannote_job_id']);
        });
    }
};
