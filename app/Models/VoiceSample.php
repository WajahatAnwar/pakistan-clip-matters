<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceSample extends Model
{
    protected $hidden = ['voiceprint'];
    protected $casts = ['quality_review'=>'array'];
    protected $fillable = [
        'user_id',
        'name',
        'file_path',
        'duration',
        'pyannote_job_id',
        'voiceprint', 'identity_key', 'voiceprint_model', 'voiceprint_revision',
        'validation_state', 'quality_review', 'voiceprint_fingerprint',
    ];
}
