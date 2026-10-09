<?php
namespace App\Services;

use InvalidArgumentException;

class TranscriptSegmentMapper
{
    public function map(array $utterances, string $unit): array
    {
        if (!in_array($unit, ['milliseconds', 'seconds'], true)) {
            throw new InvalidArgumentException('Explicit transcript timestamp unit is required');
        }
        $segments = [];
        foreach ($utterances as $utterance) {
            $text = trim($utterance['text'] ?? '');
            if ($text === '') continue;
            $start = $utterance['start'] ?? null;
            $end = $utterance['end'] ?? null;
            if (!is_numeric($start) || !is_numeric($end) || $start < 0 || $end <= $start) {
                throw new InvalidArgumentException('Invalid transcript time range');
            }
            $scale = $unit === 'milliseconds' ? 1000 : 1;
            $segments[] = [
                'start' => $start / $scale, 'end' => $end / $scale,
                'speaker' => $utterance['actualSpeaker'] ?? 'Unknown Speaker',
                'transcript_speaker' => $utterance['speaker'] ?? null,
                'text' => $text,
            ];
        }
        return $segments;
    }
}
