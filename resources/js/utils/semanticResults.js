// Presentation preserves Python's order and distinct passage identities.
export function preservePassages(videos) {
  return videos.map(video => {
    const seen = new Set();
    const segments = (video.segments || []).filter(segment => {
      const identity = segment.segment_id ?? JSON.stringify(segment.segment_ids || [segment.start_time, segment.end_time, segment.text]);
      if (seen.has(identity)) return false;
      seen.add(identity);
      return true;
    });
    return { ...video, segments, match_count: segments.length };
  });
}

export function appendSemanticPage(current, incoming) {
  const output = preservePassages(current);
  const indices = new Map(output.map((v, i) => [v.video?.id ?? v.video_id, i]));
  for (const video of incoming) {
    const id = video.video?.id ?? video.video_id;
    if (indices.has(id)) {
      const index = indices.get(id);
      output[index] = preservePassages([{ ...output[index], segments: [...(output[index].segments || []), ...(video.segments || [])] }])[0];
    } else {
      indices.set(id, output.length);
      output.push(preservePassages([video])[0]);
    }
  }
  return output;
}
