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
        Schema::table('users', function (Blueprint $table) {
            $table->string('dropbox_team_id')->nullable()->after('dropbox_token_expires_at');
            $table->string('dropbox_team_member_id')->nullable()->after('dropbox_team_id');
            $table->string('dropbox_root_namespace_id')->nullable()->after('dropbox_team_member_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['dropbox_team_id', 'dropbox_team_member_id', 'dropbox_root_namespace_id']);
        });
    }
};
