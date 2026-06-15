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
            // pending = awaiting review, approved = approved for use, rejected = rejected, archived = archived by superAdmin
            $table->string('approval_status')->default('pending')->after('processing_status');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approval_status');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->boolean('is_archived')->default(false)->after('approved_at');
            $table->unsignedBigInteger('archived_by')->nullable()->after('is_archived');
            $table->timestamp('archived_at')->nullable()->after('archived_by');
            
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('archived_by')->references('id')->on('users')->onDelete('set null');
            
            $table->index('approval_status');
            $table->index('is_archived');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['archived_by']);
            $table->dropColumn(['approval_status', 'approved_by', 'approved_at', 'is_archived', 'archived_by', 'archived_at']);
        });
    }
};
