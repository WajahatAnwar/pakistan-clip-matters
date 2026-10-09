<?php
namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Facades\Http;

/** Stage 3 contracts; no transcript JSON is hydrated by this presenter. */
final class StageThreeSearch
{
    private function base(array $input)
    {
        $query = Video::readyForSearch();
        if (!empty($input['video_id'])) $query->where('id', $input['video_id']);
        if (!empty($input['language'])) $query->where('language_detected', $input['language']);
        if (!empty($input['filter_date'])) $query->whereDate('video_created_at', $input['filter_date']);
        elseif (!empty($input['filter_year'])) {
            $query->whereYear('video_created_at', $input['filter_year']);
            if (!empty($input['filter_month'])) $query->whereMonth('video_created_at', $input['filter_month']);
        }
        return $query;
    }

    public function search(array $input): \Illuminate\Http\JsonResponse
    {
        $type = $input['filter_type']; $query = $input['query'] ?? $input['title'] ?? '';
        if ($type === 'title') $query = trim((string) ($input['title'] ?? '')) !== '' ? $input['title'] : $query;
        if (trim($query) === '') return response()->json(['success'=>false,'message'=>'A search query is required'],422);
        $page = $input['page'] ?? 1; $perPage = $input['per_page'] ?? 10;
        $base = $this->base($input);
        if ($type === 'title') {
            // Scan only small title projections. Find every match before pagination,
            // including an exact title encountered after thousands of partials.
            $matches = [];
            (clone $base)->select(['id','title'])->chunkById(200, function ($videos) use (&$matches,$query) {
                foreach ($videos as $video) {
                    $score = TranscriptSearchContract::titleScore($video->title ?? '', $query);
                    if ($score) $matches[] = ['id'=>$video->id,'score'=>$score];
                }
            });
            usort($matches, fn($a,$b)=>($b['score'] <=> $a['score']) ?: ($a['id'] <=> $b['id']));
            $total = count($matches); $slice = array_slice($matches,($page-1)*$perPage,$perPage);
            // Apply database pagination/projection before hydrating card fields.
            $ids = array_column($slice,'id');
            $videos = (clone $base)->whereIn('id',$ids)->limit($perPage)->get(Video::SEARCH_CARD_COLUMNS)->keyBy('id');
            $groups = collect($slice)->map(fn($row)=>[
                'video'=>$this->card($videos[$row['id']]), 'segments'=>[], 'match_count'=>0,
                'passage_count'=>0,'occurrence_count'=>null,'best_score'=>$row['score']*100,
                'avg_score'=>$row['score']*100,'is_video_only'=>true,'match_types'=>['simple_title_filter'],
                'matched_fields'=>['video_title'],
            ])->values();
            return $this->response($input,$groups,$total,0,null,[
                'authority'=>'python_contract_v1','representation'=>'transcript_contract_v1','contract'=>'title',
                'status'=>$total?'completed':'no_matches','exhaustive'=>true,'scope'=>'eligible_database_titles',
            ]);
        }
        $payload = array_intersect_key($input,array_flip(['query','words','video_id','language','time_range',
            'filter_year','filter_month','filter_date','max_scanned','transcript_contract']));
        $payload += ['query'=>$query,'search_mode'=>'simple','filter_type'=>$type,'top_k'=>1000];
        $summaryLimited = false;
        if ($type === 'summary') {
            $candidates = [];
            (clone $base)->select(['id','summary','summary_english','summary_urdu'])->chunkById(200,
                function ($videos) use (&$candidates,&$summaryLimited,$query) {
                    foreach ($videos as $video) {
                        $fields=[];
                        foreach (['summary','summary_english','summary_urdu'] as $field) {
                            if (TranscriptSearchContract::titleScore($video->$field ?? '',$query)>0) $fields[]=$field;
                        }
                        if (!$fields) continue;
                        if (count($candidates)>=60) {$summaryLimited=true;continue;}
                        $candidates[]=['video_id'=>$video->id,'matched_summary_fields'=>$fields];
                    }
                });
            $payload['summary_candidates']=$candidates;
        }
        try {
            $response = Http::connectTimeout(5)->timeout(75)->withHeaders([
                'X-API-Key'=>config('services.embedding.api_key'),'Accept'=>'application/json',
            ])->post(rtrim(config('services.embedding.url'),'/').'/search',$payload);
            if (!$response->successful()) {
                $detail = $response->json('detail');
                return response()->json(['success'=>false,
                    'message'=>is_array($detail)?($detail['message']??'Search unavailable. Please retry.'):($detail?:'Search unavailable. Please retry.'),
                    'retryable'=>is_array($detail)?($detail['retryable']??$response->serverError()):$response->serverError(),
                ],$response->status());
            }
            $data = $response->json(); $contract = $data['search_contract'] ?? null;
            if (($contract['authority'] ?? null) !== 'python_contract_v1') {
                return response()->json(['success'=>false,'message'=>'Search contract upgrade is required. Please retry after deployment.','retryable'=>true],503);
            }
            if ($summaryLimited) { $contract['status']='partial';$contract['exhaustive']=false; }
            $rows = collect($data['results']??[]);
            $ids = $rows->pluck('video_id')->unique()->values()->all();
            $videos = (clone $base)->whereIn('id',$ids)->get(Video::SEARCH_CARD_COLUMNS)->keyBy('id');
            $segments = $rows->map(function ($row) use ($videos,$type) {
                $video = $videos->get($row['video_id']??null);
                if (!$video || !isset($row['start_time'],$row['end_time']) || $row['start_time']<0 || $row['end_time']<=$row['start_time']) return null;
                if ($type==='text' && empty($row['match_spans'])) return null;
                if ($type==='summary' && empty($row['passage_evidence'])) return null;
                return $row + ['segment_id'=>$row['id'],'video'=>$this->card($video),
                    'text_preview'=>mb_substr($row['text']??'',0,150),
                    'youtube_timestamp_url'=>$video->youtube_url ? $video->youtube_url.(str_contains($video->youtube_url,'?')?'&':'?').'t='.floor($row['start_time']).'s':null];
            })->filter()->values();
            $groups = $segments->groupBy('video.id')->map(function ($items) use ($type) {
                return ['video'=>$items->first()['video'],'segments'=>$items->map(function ($s) {unset($s['video']);return $s;})->values(),
                    'match_count'=>$items->count(),'passage_count'=>$items->count(),
                    'occurrence_count'=>$type==='text'?$items->sum('occurrence_count'):null,
                    'best_score'=>$items->max('score'),'avg_score'=>$items->avg('score'),
                    'match_types'=>$items->pluck('match_types')->flatten()->unique()->values(),
                    'matched_fields'=>$items->pluck('matched_field')->unique()->values()];
            })->values();
            $total = $groups->count(); $passages=$segments->count();
            $occurrences=$type==='text'?$segments->sum('occurrence_count'):null;
            return $this->response($input,$groups->slice(($page-1)*$perPage,$perPage)->values(),$total,$passages,$occurrences,$contract);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['success'=>false,'message'=>'Search timed out. Please retry.','retryable'=>true],503);
        }
    }

    private function card(Video $video): array
    {
        return array_intersect_key($video->toArray(),array_flip(Video::SEARCH_CARD_COLUMNS));
    }

    private function response(array $input,$groups,int $total,int $passages,?int $occurrences,array $contract)
    {
        $page=$input['page']??1;$perPage=$input['per_page']??10;
        $counts=['matching_videos'=>$total,'displayed_passages'=>$passages,'matched_transcript_occurrences'=>$occurrences];
        $contract['counts']=$counts;
        return response()->json(['success'=>true,'query'=>$input['query']??$input['title']??'',
            'search_mode'=>'simple','filter_type'=>$input['filter_type'],'is_incremental'=>false,
            'grouped_by_video'=>$groups,'total_videos'=>$total,'total_segments'=>$passages,'counts'=>$counts,
            'current_page'=>$page,'per_page'=>$perPage,'total_pages'=>(int)ceil($total/$perPage),
            'has_next_page'=>$page*$perPage<$total,'has_prev_page'=>$page>1,
            'search_status'=>$contract['status'],'retryable'=>$contract['retryable']??false,
            'search_metadata'=>['search_contract'=>$contract,'passage_validation'=>$contract['passage_validation']??null,
                'page_counts'=>['matching_videos'=>count($groups),'displayed_passages'=>$groups->sum('passage_count'),
                    'matched_transcript_occurrences'=>$occurrences===null?null:$groups->sum('occurrence_count')]],
        ],200,[],JSON_UNESCAPED_UNICODE);
    }
}
