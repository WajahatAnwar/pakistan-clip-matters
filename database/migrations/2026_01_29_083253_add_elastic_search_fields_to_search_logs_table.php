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
        Schema::table('search_logs', function (Blueprint $table) {
            $table->string('search_type')->nullable()->after('response_time_ms');
            $table->json('filters')->nullable()->after('search_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('search_logs', function (Blueprint $table) {
            $table->dropColumn(['search_type', 'filters']);
        });
    }
};
