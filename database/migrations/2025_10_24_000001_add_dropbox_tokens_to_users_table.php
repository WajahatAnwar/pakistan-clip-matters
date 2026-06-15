<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('users', function (Blueprint $table) {
            $table->text('dropbox_access_token')->nullable();
            $table->text('dropbox_refresh_token')->nullable();
            $table->timestamp('dropbox_token_expires_at')->nullable();
        });
    }
    public function down() {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['dropbox_access_token', 'dropbox_refresh_token', 'dropbox_token_expires_at']);
        });
    }
};
