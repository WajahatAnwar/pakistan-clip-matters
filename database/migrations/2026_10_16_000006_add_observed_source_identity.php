<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('videos',function(Blueprint$t){$t->string('dropbox_file_id')->nullable()->index();$t->string('dropbox_observed_revision')->nullable();$t->unsignedBigInteger('source_size_bytes')->nullable();$t->timestamp('source_observed_at')->nullable();});}
 public function down():void {Schema::table('videos',fn(Blueprint$t)=>$t->dropColumn(['dropbox_file_id','dropbox_observed_revision','source_size_bytes','source_observed_at']));}
};
