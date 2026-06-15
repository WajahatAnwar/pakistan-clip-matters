<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceSample extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'file_path',
        'duration',
        'pyannote_job_id',
        'voiceprint',
    ];
}
