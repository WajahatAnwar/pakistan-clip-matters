// Python offsets are Unicode code points, not JavaScript UTF-16 indices.
export function evidenceParts(text, spans = []) {
  const chars = Array.from(text || '');
  const parts = []; let offset = 0;
  for (const span of spans) {
    const start = Math.max(offset, span.start); const end = Math.min(chars.length, span.end);
    if (end <= start) continue;
    if (start > offset) parts.push({ text: chars.slice(offset, start).join(''), matched: false });
    parts.push({ text: chars.slice(start, end).join(''), matched: true }); offset = end;
  }
  if (offset < chars.length) parts.push({ text: chars.slice(offset).join(''), matched: false });
  return parts;
}
export function passageSeekSeconds(passage) {
  const start = Number(passage.start_time); const end = Number(passage.end_time);
  return Number.isFinite(start) && Number.isFinite(end) && start >= 0 && end > start ? start : null;
}
export function buildEvidenceClips(passages) {
  if (!passages.every(p => p.match_spans || p.passage_evidence)) return null;
  return passages.filter(p => passageSeekSeconds(p) !== null).map((p, index) => ({
    id: `search-${index}`, start: passageSeekSeconds(p), end: Number(p.end_time),
    title: p.speaker || 'Transcript passage', speaker: p.speaker, text: p.text || '',
    source_segment_id: p.segment_id || p.id, segment_ids: p.segment_ids,
  }));
}
