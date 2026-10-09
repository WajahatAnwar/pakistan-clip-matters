#!/usr/bin/env python3
"""Score saved results only after passage-level human review; never call services.

Predictions: {"cases": [{"qa_id": 201, "latency_seconds": 1.2,
"provider_calls": 3, "passages": [{"video_id": 1, "segment_ids": ["id"],
"start_time": 8, "end_time": 18, "text": "...",
"human_verified": true, "supports_complete_query": true}]}]}.
Unreviewed passages make metrics unavailable, never silently negative/positive.
"""
import argparse
import json
from pathlib import Path


def score(fixtures, predictions):
    by_id = {p['qa_id']: p for p in predictions.get('cases', [])}
    output = []
    for case in fixtures['cases']:
        prediction = by_id.get(case['qa_id'])
        row = {'qa_id': case['qa_id'], 'top_five_video_relevance': None,
               'displayed_passage_relevance': None, 'target_met': None,
               'status': 'missing_predictions_and_human_ground_truth'}
        if prediction:
            row.update(latency_seconds=prediction.get('latency_seconds'), provider_calls=prediction.get('provider_calls'))
            passages = prediction.get('passages', [])
            valid = bool(passages) and all(
                p.get('human_verified') is True and type(p.get('supports_complete_query')) is bool
                and p.get('segment_ids') and p.get('text') and p.get('video_id') is not None
                and isinstance(p.get('start_time'), (int,float)) and isinstance(p.get('end_time'), (int,float))
                and p['end_time'] >= p['start_time'] >= 0 for p in passages)
            if valid:
                videos = {}
                for p in passages:
                    videos.setdefault(p['video_id'], []).append(p['supports_complete_query'])
                top_five = list(videos.values())[:5]
                row.update(status='human_reviewed',
                    top_five_video_relevance=sum(any(v) for v in top_five)/len(top_five),
                    displayed_passage_relevance=sum(p['supports_complete_query'] for p in passages)/len(passages),
                    target_met=(sum(any(v) for v in top_five) >= 4 and all(p['supports_complete_query'] for p in passages))
                      if len(top_five)==5 else None)
            else:
                row['status'] = 'missing_passage_level_human_review_or_no_results'
        output.append(row)
    return {'model_accuracy_verified': any(r['status']=='human_reviewed' for r in output), 'cases': output}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--fixtures', default='tests/fixtures/semantic_qa_201_231.json')
    parser.add_argument('--predictions')
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    results = score(json.loads(Path(args.fixtures).read_text()), json.loads(Path(args.predictions).read_text()) if args.predictions else {})
    Path(args.output).write_text(json.dumps(results,ensure_ascii=False,indent=2)+'\n')
