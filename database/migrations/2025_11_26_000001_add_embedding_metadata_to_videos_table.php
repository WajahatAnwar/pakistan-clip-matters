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
        // Check if columns exist before adding indexes to videos table
        Schema::table('videos', function (Blueprint $table) {
            // Only add index if column exists and index doesn't exist
            if (Schema::hasColumn('videos', 'pyannote_job_id')) {
                try {
                    $table->index('pyannote_job_id');
                } catch (\Exception $e) {
                    // Index might already exist, ignore
                }
            }
        });

        // Create separate video_embeddings table
        Schema::create('video_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->onDelete('cascade');
            
            // Qdrant metadata
            $table->string('qdrant_collection')->default('video_transcript_segments');
            $table->integer('segments_count')->default(0);
            $table->integer('vector_dimensions')->default(384);
            
            // Processing status
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            
            // Timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('video_id');
            $table->index('status');
            $table->index('qdrant_collection');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_embeddings');
        
        // Don't drop columns from videos table as they might be used by other parts
        // Only drop the index if it was added
        Schema::table('videos', function (Blueprint $table) {
            try {
                $table->dropIndex(['pyannote_job_id']);
            } catch (\Exception $e) {
                // Index might not exist, ignore
            }
        });
    }
};
