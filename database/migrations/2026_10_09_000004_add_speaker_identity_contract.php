<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('voice_samples',function(Blueprint $t){$t->string('identity_key')->nullable()->index();$t->string('voiceprint_model')->nullable();$t->string('voiceprint_revision')->nullable();$t->string('validation_state')->default('unreviewed');$t->json('quality_review')->nullable();$t->string('voiceprint_fingerprint',64)->nullable()->index();});
  Schema::create('speaker_identification_runs',function(Blueprint $t){$t->id();$t->string('job_id')->unique();$t->unsignedBigInteger('video_id')->nullable()->index();$t->json('enrollment');$t->string('status')->default('pending');$t->timestamps();});
 }
 public function down(): void {Schema::dropIfExists('speaker_identification_runs');Schema::table('voice_samples',fn(Blueprint $t)=>$t->dropColumn(['identity_key','voiceprint_model','voiceprint_revision','validation_state','quality_review','voiceprint_fingerprint']));}
};
