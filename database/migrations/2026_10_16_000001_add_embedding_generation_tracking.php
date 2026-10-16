<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('video_embeddings', function (Blueprint $table) {
            $table->json('queued_job_ids')->nullable();
            $table->timestamp('submitted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('video_embeddings', function (Blueprint $table) {
            $table->dropColumn(['queued_job_ids', 'submitted_at']);
        });
    }
};
