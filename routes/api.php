<?php

use App\Http\Controllers\Api\TranscriptController;
use Illuminate\Support\Facades\Route;

Route::get('/transcripts', [TranscriptController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('api.transcripts.index');

Route::post('/transcripts', [TranscriptController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('api.transcripts.show');
