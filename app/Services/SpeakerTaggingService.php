<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SpeakerTaggingService
{
    /**
     * Tag speakers in transcript data using diarization results
     * 
     * This method maps AssemblyAI speakers (A, B, C, D) to actual names from Pyannote.
     * 
     * Data Flow:
     * 1. AssemblyAI returns utterances with speakers: A, B, C, D (timestamps in milliseconds)
     * 2. Pyannote returns identification: SPEAKER_00→"Imran Khan" (timestamps in seconds)
     * 3. This service matches them by timestamp overlap
     * 
     * @param array $transcriptData Transcript data with speakers A, B, etc. (timestamps in milliseconds)
     * @param array $diarizationData Speaker diarization data with actual names (timestamps in seconds)
     * @return array Tagged transcript with actual speaker names
     */
    public function tagSpeakers(array $transcriptData, array $diarizationData): array
    {
        Log::info('Starting speaker tagging', [
            'transcript_segments' => count($transcriptData),
            'diarization_segments' => count($diarizationData)
        ]);

        // Create mapping from transcript speakers (A, B, C, D) to actual names
        $speakerMapping = [];
        $speakerVotes = []; // Track which actual speaker each letter maps to (voting system)
        
        // Process each transcript segment
        foreach ($transcriptData as &$segment) {
            if (!isset($segment['speaker'], $segment['start'], $segment['end'])) {
                continue;
            }
            
            // Convert milliseconds to seconds for comparison
            $startTimeSeconds = $segment['start'] / 1000;
            $endTimeSeconds = $segment['end'] / 1000;
            $midpointSeconds = ($startTimeSeconds + $endTimeSeconds) / 2;
            
            // Find matching speaker from diarization data
            $actualSpeaker = $this->findMatchingSpeaker(
                $midpointSeconds, 
                $startTimeSeconds, 
                $endTimeSeconds, 
                $diarizationData
            );
            
            if ($actualSpeaker) {
                $transcriptSpeaker = $segment['speaker']; // A, B, C, D
                $diarizationSpeaker = $actualSpeaker['diarizationSpeaker'] ?? null; // SPEAKER_00, SPEAKER_01
                $actualName = $actualSpeaker['speaker'] ?? $actualSpeaker['match'] ?? null; // Imran Khan, Junaid Akram

                if ($actualName) {
                    // Initialize voting array for this speaker letter
                    if (!isset($speakerVotes[$transcriptSpeaker])) {
                        $speakerVotes[$transcriptSpeaker] = [];
                    }
                    
                    // Vote for this actual name
                    if (!isset($speakerVotes[$transcriptSpeaker][$actualName])) {
                        $speakerVotes[$transcriptSpeaker][$actualName] = 0;
                    }
                    $speakerVotes[$transcriptSpeaker][$actualName]++;
                    
                    // Add actual speaker name to the segment
                    $segment['actualSpeaker'] = $actualName;
                    if ($diarizationSpeaker) {
                        $segment['diarizationSpeaker'] = $diarizationSpeaker;
                    }
                    $segment['confidence'] = $actualSpeaker['match'] ?? null;
                }
            }
            
            // Also tag individual words if they exist
            if (isset($segment['words']) && is_array($segment['words'])) {
                foreach ($segment['words'] as &$word) {
                    if (!isset($word['start'], $word['end'])) {
                        continue;
                    }
                    
                    $wordStartSeconds = $word['start'] / 1000;
                    $wordEndSeconds = $word['end'] / 1000;
                    $wordMidpoint = ($wordStartSeconds + $wordEndSeconds) / 2;
                    
                    $wordSpeaker = $this->findMatchingSpeaker(
                        $wordMidpoint,
                        $wordStartSeconds,
                        $wordEndSeconds,
                        $diarizationData
                    );
                    
                    if ($wordSpeaker) {
                        $wordActualName = $wordSpeaker['speaker'] ?? $wordSpeaker['match'] ?? null;
                        if ($wordActualName) {
                            $word['actualSpeaker'] = $wordActualName;
                        }
                        if (isset($wordSpeaker['diarizationSpeaker'])) {
                            $word['diarizationSpeaker'] = $wordSpeaker['diarizationSpeaker'];
                        }
                    }
                }
            }
        }
        
        // Build final speaker mapping using voting (most common match wins)
        foreach ($speakerVotes as $letter => $votes) {
            if (!empty($votes)) {
                arsort($votes); // Sort by vote count descending
                $speakerMapping[$letter] = array_key_first($votes);
            }
        }

        // Second pass: Apply consistent mapping to segments without actualSpeaker
        foreach ($transcriptData as &$segment) {
            if (!isset($segment['actualSpeaker']) && isset($segment['speaker']) && isset($speakerMapping[$segment['speaker']])) {
                $segment['actualSpeaker'] = $speakerMapping[$segment['speaker']];
            }
        }

        Log::info('Speaker tagging completed', [
            'speaker_mapping' => $speakerMapping,
            'votes' => $speakerVotes
        ]);
        
        return [
            'taggedTranscript' => $transcriptData,
            'speakerMapping' => $speakerMapping,
            'statistics' => $this->getStatistics($transcriptData, $speakerMapping)
        ];
    }
    
    /**
     * Build mapping from diarization speakers to actual names
     */
    private function buildSpeakerMapping(array $diarizationData): array
    {
        $mapping = [];
        
        foreach ($diarizationData as $segment) {
            if (isset($segment['diarizationSpeaker'], $segment['speaker'])) {
                $diarizationSpeaker = $segment['diarizationSpeaker']; // SPEAKER_00, SPEAKER_01
                $actualName = $segment['speaker']; // Imran Khan, Junaid Akram
                
                // Map diarization speaker to actual name
                if (!isset($mapping[$diarizationSpeaker])) {
                    $mapping[$diarizationSpeaker] = $actualName;
                }
            }
        }
        
        return $mapping;
    }
    
    /**
     * Find the matching speaker for a given time range
     * 
     * This method uses multiple strategies to find the best match:
     * 1. Primary: Maximum overlap between transcript segment and diarization segment
     * 2. Secondary: Midpoint falls within diarization segment
     * 3. Fallback: Closest diarization segment by time
     */
    private function findMatchingSpeaker(
        float $midpoint, 
        float $startTime, 
        float $endTime, 
        array $diarizationData
    ): ?array {
        $bestMatch = null;
        $maxOverlap = 0;
        $closestDistance = PHP_FLOAT_MAX;
        $closestMatch = null;
        
        foreach ($diarizationData as $diarizationSegment) {
            if (!isset($diarizationSegment['start'], $diarizationSegment['end'])) {
                continue;
            }
            
            $diaStart = (float) $diarizationSegment['start'];
            $diaEnd = (float) $diarizationSegment['end'];
            
            // Calculate overlap between transcript segment and diarization segment
            $overlapStart = max($startTime, $diaStart);
            $overlapEnd = min($endTime, $diaEnd);
            $overlap = max(0, $overlapEnd - $overlapStart);
            
            // Check if midpoint falls within diarization segment
            $midpointMatch = ($midpoint >= $diaStart && $midpoint <= $diaEnd);
            
            // Strategy 1 & 2: Prefer segments with midpoint match and maximum overlap
            if ($midpointMatch && $overlap > $maxOverlap) {
                $maxOverlap = $overlap;
                $bestMatch = $diarizationSegment;
            }
            // Also consider overlap without midpoint match (for edge cases)
            elseif ($overlap > $maxOverlap && !$bestMatch) {
                $maxOverlap = $overlap;
                $bestMatch = $diarizationSegment;
            }
            // Strategy 3: Track closest segment as fallback
            $diaMidpoint = ($diaStart + $diaEnd) / 2;
            $distance = abs($midpoint - $diaMidpoint);
            if ($distance < $closestDistance) {
                $closestDistance = $distance;
                $closestMatch = $diarizationSegment;
            }
        }
        // Use best match if found, otherwise use closest match as fallback
        // Only use fallback if the distance is reasonable (within 5 seconds)
        if ($bestMatch) {
            return $bestMatch;
        } elseif ($closestMatch && $closestDistance < 5.0) {
            return $closestMatch;
        }
        return null;
    }
    
    /**
     * Get statistics about speaker tagging
     */
    private function getStatistics(array $transcriptData, array $speakerMapping): array
    {
        $stats = [
            'totalSegments' => count($transcriptData),
            'taggedSegments' => 0,
            'untaggedSegments' => 0,
            'speakerCounts' => [],
            'totalDuration' => 0,
            'speakerDurations' => []
        ];
        
        foreach ($transcriptData as $segment) {
            if (isset($segment['actualSpeaker'])) {
                $stats['taggedSegments']++;
                $speaker = $segment['actualSpeaker'];
                
                if (!isset($stats['speakerCounts'][$speaker])) {
                    $stats['speakerCounts'][$speaker] = 0;
                    $stats['speakerDurations'][$speaker] = 0;
                }
                
                $stats['speakerCounts'][$speaker]++;
                
                // Calculate duration in seconds
                if (isset($segment['start'], $segment['end'])) {
                    $duration = ($segment['end'] - $segment['start']) / 1000;
                    $stats['speakerDurations'][$speaker] += $duration;
                    $stats['totalDuration'] += $duration;
                }
            } else {
                $stats['untaggedSegments']++;
            }
        }
        
        return $stats;
    }
    
    /**
     * Convert transcript with generic speakers to actual speaker names
     * This is a simpler version that uses a pre-built mapping
     */
    public function applySimpleSpeakerMapping(array $transcriptData, array $mapping): array
    {
        // Mapping example: ['A' => 'Imran Khan', 'B' => 'Junaid Akram']
        foreach ($transcriptData as &$segment) {
            if (isset($segment['speaker']) && isset($mapping[$segment['speaker']])) {
                $segment['actualSpeaker'] = $mapping[$segment['speaker']];
            }
            
            // Also update words
            if (isset($segment['words']) && is_array($segment['words'])) {
                foreach ($segment['words'] as &$word) {
                    if (isset($word['speaker']) && isset($mapping[$word['speaker']])) {
                        $word['actualSpeaker'] = $mapping[$word['speaker']];
                    }
                }
            }
        }
        
        return $transcriptData;
    }
    
    /**
     * Generate a formatted transcript with speaker names
     */
    public function generateFormattedTranscript(array $taggedTranscript): string
    {
        $output = [];
        $currentSpeaker = null;
        $currentText = [];
        
        foreach ($taggedTranscript as $segment) {
            $speaker = $segment['actualSpeaker'] ?? $segment['speaker'] ?? 'Unknown';
            $text = $segment['text'] ?? '';
            $startTime = $this->formatTimestamp($segment['start'] ?? 0);
            
            if ($speaker !== $currentSpeaker) {
                // Save previous speaker's text
                if ($currentSpeaker && !empty($currentText)) {
                    $output[] = "$currentSpeaker: " . implode(' ', $currentText);
                }
                
                // Start new speaker
                $currentSpeaker = $speaker;
                $currentText = [$text];
            } else {
                $currentText[] = $text;
            }
        }
        
        // Add last speaker's text
        if ($currentSpeaker && !empty($currentText)) {
            $output[] = "$currentSpeaker: " . implode(' ', $currentText);
        }
        
        return implode("\n\n", $output);
    }
    
    /**
     * Format timestamp from milliseconds to HH:MM:SS
     */
    private function formatTimestamp(int $milliseconds): string
    {
        $seconds = floor($milliseconds / 1000);
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        $ms = $milliseconds % 1000;
        
        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $ms);
        }
        
        return sprintf('%02d:%02d.%03d', $minutes, $secs, $ms);
    }
}
