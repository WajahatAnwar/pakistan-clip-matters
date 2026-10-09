<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('videos', fn (Blueprint $t) => $t->string('transcript_timestamp_unit')->nullable());
        Schema::table('video_embeddings', function (Blueprint $t) {
            $t->uuid('active_run_id')->nullable();
            $t->unsignedInteger('expected_batches')->nullable();
            $t->json('batch_acknowledgments')->nullable();
        });
    }
    public function down(): void {
        Schema::table('videos', fn (Blueprint $t) => $t->dropColumn('transcript_timestamp_unit'));
        Schema::table('video_embeddings', fn (Blueprint $t) => $t->dropColumn(['active_run_id', 'expected_batches', 'batch_acknowledgments']));
    }
};
