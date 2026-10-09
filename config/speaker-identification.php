<?php
return [
 'model'=>'precision-2', 'contract'=>'pyannote-identify-v1', 'revision'=>env('PYANNOTE_VOICEPRINT_REVISION'),
 // No calibrated operating point exists yet: identification fails closed until configured.
 'threshold'=>env('SPEAKER_IDENTIFICATION_THRESHOLD'), 'margin'=>env('SPEAKER_IDENTIFICATION_MARGIN'),
 'min_speech_seconds'=>env('SPEAKER_IDENTIFICATION_MIN_SPEECH_SECONDS'),
 'mapping_coverage'=>0.8, // Temporal coverage, not a speaker probability. Provisional.
];
