<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, clean up any existing duplicates before adding the constraint
        // This ensures the unique constraint can be applied successfully
        
        $duplicates = DB::table('videos')
            ->select('user_id', 'dropbox_path', DB::raw('MIN(id) as keep_id'))
            ->groupBy('user_id', 'dropbox_path')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            // Delete all duplicates except the oldest one (smallest id)
            DB::table('videos')
                ->where('user_id', $duplicate->user_id)
                ->where('dropbox_path', $duplicate->dropbox_path)
                ->where('id', '>', $duplicate->keep_id)
                ->delete();
        }

        // Now add the unique constraint to prevent future duplicates
        Schema::table('videos', function (Blueprint $table) {
            $table->unique(['user_id', 'dropbox_path'], 'unique_user_dropbox_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropUnique('unique_user_dropbox_path');
        });
    }
};
