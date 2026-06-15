<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('users', function (Blueprint $table) {
            $table->text('assemblyai_api_key')->nullable();
        });
    }
    public function down() {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['assemblyai_api_key']);
        });
    }
};
