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
        Schema::create('dropbox_deletions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('video_id')->nullable()->constrained()->onDelete('set null');
            $table->string('dropbox_path');
            $table->string('filename');
            $table->string('file_type')->nullable(); // video, audio, etc.
            $table->text('metadata')->nullable(); // JSON metadata
            $table->boolean('processed')->default(false);
            $table->timestamp('deleted_at');
            $table->timestamps();
            
            $table->index(['user_id', 'deleted_at']);
            $table->index('dropbox_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dropbox_deletions');
    }
};
