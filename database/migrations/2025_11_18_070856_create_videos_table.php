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
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Dropbox information
            $table->string('dropbox_path');
            $table->string('filename');
            $table->string('extension', 10)->nullable();
            $table->decimal('size_mb', 10, 2)->nullable();
            
            // Video metadata
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            
            // Processing status
            $table->enum('processing_status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('processing_error')->nullable();
            
            // YouTube information
            $table->string('youtube_video_id')->nullable();
            $table->string('youtube_url')->nullable();
            $table->enum('youtube_privacy', ['public', 'unlisted', 'private'])->default('unlisted');
            
            // Transcription information
            $table->string('transcript_id')->nullable();
            $table->longText('transcript_text')->nullable();
            $table->string('language_detected', 10)->nullable();
            $table->integer('audio_duration_seconds')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            
            // Speaker information
            $table->integer('speakers_count')->nullable();
            $table->json('speakers_data')->nullable(); // Store speaker labels and utterances
            
            // Summary
            $table->text('summary')->nullable();
            
            // Processing timestamps
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_completed_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('user_id');
            $table->index('processing_status');
            $table->index('youtube_video_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
