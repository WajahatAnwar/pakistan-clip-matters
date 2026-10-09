<?php
namespace App\Services;

use Illuminate\Support\Collection;

/** Presentation only: Python owns evidence consolidation and relevance order. */
final class SemanticResultPresenter
{
    public function group(Collection $segments): Collection
    {
        return $segments->groupBy('video.id')->map(function ($items) {
            $unique = $items->unique('segment_id')->values();
            return [
                'video' => $unique->first()['video'],
                'match_count' => $unique->count(),
                'passage_count' => $unique->count(),
                'source_segment_count' => $unique->sum(fn ($s) => count($s['segment_ids'] ?? [$s['segment_id']])),
                'occurrence_count' => null,
                'best_score' => $unique->max('score'),
                'avg_score' => round($unique->avg('score'), 2),
                'match_types' => $unique->pluck('match_types')->flatten()->unique()->values(),
                'matched_fields' => $unique->pluck('matched_field')->filter()->unique()->values(),
                'segments' => $unique->map(function ($s) {
                    unset($s['video']);
                    return $s;
                })->values(),
            ];
        })->values();
    }
}
