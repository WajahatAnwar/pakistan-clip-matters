<?php

namespace App\Services;

use Google\Cloud\Speech\V2\Client\SpeechClient;
use Google\Cloud\Speech\V2\RecognizeRequest;
use Google\Cloud\Speech\V2\RecognitionConfig;
use Google\Cloud\Speech\V2\AutoDetectDecodingConfig;
use Google\Cloud\Speech\V2\ExplicitDecodingConfig;
use Google\Cloud\Speech\V2\BatchRecognizeRequest;
use Google\Cloud\Speech\V2\RecognitionFeatures;
use Google\Cloud\Speech\V2\SpeakerDiarizationConfig;
use Google\Cloud\Speech\V2\TranslationConfig;
use Google\ApiCore\ApiException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\User;

/**
 * Google Cloud Speech-to-Text Service
 * 
 * This service provides comprehensive speech recognition capabilities including:
 * - Real-time speech recognition
 * - Batch audio transcription
 * - Speaker diarization (identify different speakers)
 * - Multi-language support and auto-detection
 * - Word-level timestamps
 * - Profanity filtering
 * - Automatic punctuation
 * - Audio translation
 * 
 * @link https://docs.cloud.google.com/php/docs/reference/cloud-speech/latest
 */
class GoogleCloudSpeechService
{
    protected $client;
    protected $user;
    protected $projectId;
    protected $location;

    /**
     * Initialize the Google Cloud Speech service
     * 
     * @param User|null $user Optional user for user-specific credentials
     */
    public function __construct(?User $user = null)
    {
        $this->user = $user;
        $this->projectId = env('GOOGLE_CLOUD_PROJECT_ID');
        $this->location = env('GOOGLE_CLOUD_LOCATION', 'global');
        
        // Set up authentication
        $this->setupAuthentication();
        
        // Initialize the Speech client
        $this->client = new SpeechClient();
    }

    /**
     * Set up Google Cloud authentication
     */
    protected function setupAuthentication()
    {
        $credentialsPath = $this->user?->google_cloud_credentials_path
            ?? env('GOOGLE_APPLICATION_CREDENTIALS');
        
        if ($credentialsPath) {
            putenv("GOOGLE_APPLICATION_CREDENTIALS={$credentialsPath}");
        }
    }

    /**
     * Transcribe audio file with basic configuration
     * 
     * @param string $audioPath Path to audio file (local or GCS URI)
     * @param string|null $languageCode Language code (e.g., 'en-US', 'es-ES'). null for auto-detection
     * @param array $options Additional options
     * @return array Transcription result
     */
    public function transcribe(string $audioPath, ?string $languageCode = 'en-US', array $options = []): array
    {
        try {
            $audioContent = $this->loadAudioContent($audioPath);
            
            // Build recognition config
            $config = $this->buildRecognitionConfig($languageCode, $options);
            
            // Create the recognizer name
            $recognizer = $this->getRecognizerName($options['recognizer'] ?? 'default');
            
            // Create the request
            $request = (new RecognizeRequest())
                ->setRecognizer($recognizer)
                ->setConfig($config)
                ->setContent($audioContent);
            
            // Perform recognition
            $response = $this->client->recognize($request);
            
            return $this->parseRecognitionResponse($response);
            
        } catch (ApiException $e) {
            Log::error('Google Cloud Speech transcription failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'audio_path' => $audioPath,
            ]);
            throw $e;
        }
    }

    /**
     * Transcribe audio with speaker diarization (identify different speakers)
     * 
     * @param string $audioPath Path to audio file
     * @param int $minSpeakers Minimum number of speakers
     * @param int $maxSpeakers Maximum number of speakers
     * @param string|null $languageCode Language code
     * @param array $options Additional options
     * @return array Transcription with speaker labels
     */
    public function transcribeWithSpeakers(
        string $audioPath, 
        int $minSpeakers = 2, 
        int $maxSpeakers = 6,
        ?string $languageCode = 'en-US',
        array $options = []
    ): array {
        $options['enable_speaker_diarization'] = true;
        $options['min_speaker_count'] = $minSpeakers;
        $options['max_speaker_count'] = $maxSpeakers;
        
        return $this->transcribe($audioPath, $languageCode, $options);
    }

    /**
     * Transcribe audio with automatic language detection
     * 
     * @param string $audioPath Path to audio file
     * @param array $languageCodes Array of possible language codes to detect from
     * @param array $options Additional options
     * @return array Transcription with detected language
     */
    public function transcribeWithAutoLanguageDetection(
        string $audioPath,
        array $languageCodes = ['en-US', 'es-ES', 'fr-FR', 'de-DE'],
        array $options = []
    ): array {
        $options['auto_detect_language'] = true;
        $options['language_codes'] = $languageCodes;
        
        return $this->transcribe($audioPath, null, $options);
    }

    /**
     * Transcribe long audio file using batch processing
     * 
     * @param string $gcsUri Google Cloud Storage URI (gs://bucket/file)
     * @param string $outputGcsUri Output GCS URI for results
     * @param string|null $languageCode Language code
     * @param array $options Additional options
     * @return array Operation info
     */
    public function batchTranscribe(
        string $gcsUri,
        string $outputGcsUri,
        ?string $languageCode = 'en-US',
        array $options = []
    ): array {
        try {
            $config = $this->buildRecognitionConfig($languageCode, $options);
            $recognizer = $this->getRecognizerName($options['recognizer'] ?? 'default');
            
            $request = (new BatchRecognizeRequest())
                ->setRecognizer($recognizer)
                ->setConfig($config)
                ->setFiles([
                    [
                        'uri' => $gcsUri,
                    ]
                ])
                ->setRecognitionOutputConfig([
                    'gcs_output_config' => [
                        'uri' => $outputGcsUri,
                    ]
                ]);
            
            $operation = $this->client->batchRecognize($request);
            
            return [
                'operation_name' => $operation->getName(),
                'status' => 'processing',
                'message' => 'Batch transcription started. Check operation status to get results.',
            ];
            
        } catch (ApiException $e) {
            Log::error('Google Cloud Speech batch transcription failed', [
                'error' => $e->getMessage(),
                'gcs_uri' => $gcsUri,
            ]);
            throw $e;
        }
    }

    /**
     * Transcribe audio with translation
     * 
     * @param string $audioPath Path to audio file
     * @param string $sourceLanguageCode Source language code
     * @param string $targetLanguageCode Target language code for translation
     * @param array $options Additional options
     * @return array Transcription and translation
     */
    public function transcribeAndTranslate(
        string $audioPath,
        string $sourceLanguageCode,
        string $targetLanguageCode,
        array $options = []
    ): array {
        $options['translation_config'] = [
            'target_language' => $targetLanguageCode,
        ];
        
        return $this->transcribe($audioPath, $sourceLanguageCode, $options);
    }

    /**
     * Get word-level timestamps from audio
     * 
     * @param string $audioPath Path to audio file
     * @param string|null $languageCode Language code
     * @param array $options Additional options
     * @return array Transcription with word timestamps
     */
    public function transcribeWithWordTimestamps(
        string $audioPath,
        ?string $languageCode = 'en-US',
        array $options = []
    ): array {
        $options['enable_word_time_offsets'] = true;
        
        return $this->transcribe($audioPath, $languageCode, $options);
    }

    /**
     * Build recognition configuration
     * 
     * @param string|null $languageCode Language code
     * @param array $options Configuration options
     * @return RecognitionConfig
     */
    protected function buildRecognitionConfig(?string $languageCode, array $options): RecognitionConfig
    {
        $config = new RecognitionConfig();
        
        // Set language codes
        if (!empty($options['auto_detect_language'])) {
            $autoDetectConfig = new AutoDetectDecodingConfig();
            if (!empty($options['language_codes'])) {
                foreach ($options['language_codes'] as $code) {
                    $autoDetectConfig->setLanguageCodes([$code]);
                }
            }
            $config->setAutoDecodingConfig($autoDetectConfig);
        } else {
            $explicitConfig = new ExplicitDecodingConfig();
            if ($languageCode) {
                $explicitConfig->setLanguageCode($languageCode);
            }
            
            // Set audio encoding if specified
            if (!empty($options['encoding'])) {
                $explicitConfig->setEncoding($options['encoding']);
            }
            
            // Set sample rate if specified
            if (!empty($options['sample_rate_hertz'])) {
                $explicitConfig->setSampleRateHertz($options['sample_rate_hertz']);
            }
            
            // Set audio channels if specified
            if (!empty($options['audio_channel_count'])) {
                $explicitConfig->setAudioChannelCount($options['audio_channel_count']);
            }
            
            $config->setExplicitDecodingConfig($explicitConfig);
        }
        
        // Build features
        $features = new RecognitionFeatures();
        
        // Enable automatic punctuation
        if ($options['enable_automatic_punctuation'] ?? true) {
            $features->setEnableAutomaticPunctuation(true);
        }
        
        // Enable word timestamps
        if ($options['enable_word_time_offsets'] ?? false) {
            $features->setEnableWordTimeOffsets(true);
        }
        
        // Enable profanity filter
        if ($options['profanity_filter'] ?? false) {
            $features->setProfanityFilter(true);
        }
        
        // Enable speaker diarization
        if ($options['enable_speaker_diarization'] ?? false) {
            $diarizationConfig = new SpeakerDiarizationConfig();
            $diarizationConfig->setMinSpeakerCount($options['min_speaker_count'] ?? 2);
            $diarizationConfig->setMaxSpeakerCount($options['max_speaker_count'] ?? 6);
            $features->setDiarizationConfig($diarizationConfig);
        }
        
        // Enable word confidence
        if ($options['enable_word_confidence'] ?? true) {
            $features->setEnableWordConfidence(true);
        }
        
        // Set max alternatives
        if (!empty($options['max_alternatives'])) {
            $features->setMaxAlternatives($options['max_alternatives']);
        }
        
        $config->setFeatures($features);
        
        // Add translation config if specified
        if (!empty($options['translation_config'])) {
            $translationConfig = new TranslationConfig();
            $translationConfig->setTargetLanguage($options['translation_config']['target_language']);
            $config->setTranslationConfig($translationConfig);
        }
        
        // Set model
        if (!empty($options['model'])) {
            $config->setModel($options['model']);
        }
        
        return $config;
    }

    /**
     * Load audio content from file or URI
     * 
     * @param string $audioPath Path to audio file or GCS URI
     * @return string Audio content as bytes
     */
    protected function loadAudioContent(string $audioPath): string
    {
        if (str_starts_with($audioPath, 'gs://')) {
            // For GCS URIs, you'd typically use batch recognition
            throw new \InvalidArgumentException('Use batchTranscribe() for GCS URIs');
        }
        
        // Check if it's a storage path
        if (Storage::exists($audioPath)) {
            return Storage::get($audioPath);
        }
        
        // Assume it's a local file path
        if (file_exists($audioPath)) {
            return file_get_contents($audioPath);
        }
        
        throw new \InvalidArgumentException("Audio file not found: {$audioPath}");
    }

    /**
     * Parse recognition response into structured array
     * 
     * @param mixed $response Recognition response
     * @return array Parsed results
     */
    protected function parseRecognitionResponse($response): array
    {
        $results = [];
        
        foreach ($response->getResults() as $result) {
            $alternatives = [];
            
            foreach ($result->getAlternatives() as $alternative) {
                $altData = [
                    'transcript' => $alternative->getTranscript(),
                    'confidence' => $alternative->getConfidence(),
                    'words' => [],
                ];
                
                // Parse word-level information
                foreach ($alternative->getWords() as $wordInfo) {
                    $word = [
                        'word' => $wordInfo->getWord(),
                        'confidence' => $wordInfo->getConfidence(),
                    ];
                    
                    // Add timestamps if available
                    if ($wordInfo->getStartOffset()) {
                        $word['start_time'] = $this->durationToSeconds($wordInfo->getStartOffset());
                        $word['end_time'] = $this->durationToSeconds($wordInfo->getEndOffset());
                    }
                    
                    // Add speaker tag if available
                    if ($wordInfo->getSpeakerLabel()) {
                        $word['speaker'] = $wordInfo->getSpeakerLabel();
                    }
                    
                    $altData['words'][] = $word;
                }
                
                $alternatives[] = $altData;
            }
            
            $resultData = [
                'alternatives' => $alternatives,
                'language_code' => $result->getLanguageCode(),
            ];
            
            // Add channel tag if available
            if ($result->getChannelTag()) {
                $resultData['channel'] = $result->getChannelTag();
            }
            
            $results[] = $resultData;
        }
        
        return [
            'results' => $results,
            'total_billed_time' => $response->getMetadata()?->getTotalBilledDuration() 
                ? $this->durationToSeconds($response->getMetadata()->getTotalBilledDuration())
                : null,
        ];
    }

    /**
     * Convert protobuf Duration to seconds
     * 
     * @param mixed $duration Duration object
     * @return float Seconds
     */
    protected function durationToSeconds($duration): float
    {
        if (method_exists($duration, 'getSeconds')) {
            return $duration->getSeconds() + ($duration->getNanos() / 1000000000);
        }
        return 0.0;
    }

    /**
     * Get the recognizer name/path
     * 
     * @param string $recognizerName Recognizer name or 'default'
     * @return string Full recognizer path
     */
    protected function getRecognizerName(string $recognizerName = 'default'): string
    {
        if ($recognizerName === 'default' || $recognizerName === '_') {
            return sprintf(
                'projects/%s/locations/%s/recognizers/_',
                $this->projectId,
                $this->location
            );
        }
        
        return sprintf(
            'projects/%s/locations/%s/recognizers/%s',
            $this->projectId,
            $this->location,
            $recognizerName
        );
    }

    /**
     * Upload audio file to Google Cloud Storage
     * 
     * @param string $localPath Local file path
     * @param string $gcsBucket GCS bucket name
     * @param string $gcsPath Path in bucket
     * @return string GCS URI (gs://bucket/path)
     */
    public function uploadToGCS(string $localPath, string $gcsBucket, string $gcsPath): string
    {
        $storage = new \Google\Cloud\Storage\StorageClient([
            'projectId' => $this->projectId,
        ]);
        
        $bucket = $storage->bucket($gcsBucket);
        
        $object = $bucket->upload(
            fopen($localPath, 'r'),
            [
                'name' => $gcsPath,
            ]
        );
        
        return sprintf('gs://%s/%s', $gcsBucket, $gcsPath);
    }

    /**
     * Format transcription for display
     * 
     * @param array $results Transcription results
     * @param bool $includeSpeakers Include speaker labels
     * @param bool $includeTimestamps Include timestamps
     * @return string Formatted text
     */
    public function formatTranscription(
        array $results,
        bool $includeSpeakers = false,
        bool $includeTimestamps = false
    ): string {
        $output = [];
        
        foreach ($results['results'] as $result) {
            if (empty($result['alternatives'])) {
                continue;
            }
            
            $alternative = $result['alternatives'][0];
            
            if ($includeSpeakers || $includeTimestamps) {
                $currentSpeaker = null;
                $currentLine = '';
                
                foreach ($alternative['words'] as $wordInfo) {
                    $speaker = $wordInfo['speaker'] ?? null;
                    
                    // New speaker or first word
                    if ($speaker !== $currentSpeaker && $includeSpeakers) {
                        if ($currentLine) {
                            $output[] = $currentLine;
                        }
                        $currentSpeaker = $speaker;
                        $timestamp = $includeTimestamps && isset($wordInfo['start_time'])
                            ? sprintf('[%s] ', $this->formatTime($wordInfo['start_time']))
                            : '';
                        $currentLine = sprintf('%sSpeaker %s: %s', $timestamp, $speaker, $wordInfo['word']);
                    } else {
                        $currentLine .= ' ' . $wordInfo['word'];
                    }
                }
                
                if ($currentLine) {
                    $output[] = $currentLine;
                }
            } else {
                $output[] = $alternative['transcript'];
            }
        }
        
        return implode("\n", $output);
    }

    /**
     * Format time in seconds to readable format
     * 
     * @param float $seconds Time in seconds
     * @return string Formatted time (HH:MM:SS)
     */
    protected function formatTime(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        return sprintf('%02d:%02d:%05.2f', $hours, $minutes, $secs);
    }

    /**
     * Close the client connection
     */
    public function __destruct()
    {
        if ($this->client) {
            $this->client->close();
        }
    }
}
