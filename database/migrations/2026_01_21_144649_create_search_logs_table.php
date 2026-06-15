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
        Schema::create('search_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('query')->nullable(); // The search query text
            $table->string('word')->nullable(); // Word search
            $table->string('speaker')->nullable(); // Speaker filter
            $table->integer('video_id')->nullable(); // Video filter if used
            $table->integer('results_count')->default(0); // Number of results returned
            $table->integer('videos_count')->default(0); // Number of unique videos in results
            $table->float('min_score')->nullable(); // Min score filter used
            $table->string('ip_address')->nullable(); // For analytics
            $table->string('user_agent')->nullable(); // Browser/device info
            $table->integer('response_time_ms')->nullable(); // How long the search took
            $table->timestamps();
            
            // Indexes for analytics queries
            $table->index('query');
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_logs');
    }
};
