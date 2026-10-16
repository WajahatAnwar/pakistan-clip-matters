export function seekSeconds(value) {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) throw new Error('Invalid passage timestamp.');
  return value;
}
export function youtubeId(url) {
  try {
    const u = new URL(url);
    const host = u.hostname.toLowerCase().replace(/^www\./, '').replace(/^m\./, '');
    const id = host === 'youtu.be' ? u.pathname.slice(1) : ['youtube.com', 'youtube-nocookie.com'].includes(host)
      ? (u.searchParams.get('v') || u.pathname.match(/^\/(?:embed|shorts|live)\/([^/]+)/)?.[1]) : null;
    return /^[\w-]{11}$/.test(id || '') ? id : null;
  } catch { return null; }
}
export class PlaybackSession {
  constructor({ videoId, youtube, dropbox, fetchLink, change = () => {} }) {
    Object.assign(this, { videoId, youtube, dropbox, fetchLink, change });
    this.attempted = new Set(); this.pending = null; this.position = 0; this.refreshes = 0;
    this.generation = 0; this.disposed = false; this.adapter = null; this.duration = null; this.wantPlay = false;
  }
  emit(state, message = null) { if (!this.disposed) { this.state = state; this.change({ state, message, provider: this.provider, url: this.url, linkId: this.linkId }); } }
  start() { return this.select(this.youtube ? 'youtube' : this.dropbox ? 'dropbox' : null); }
  async select(provider) {
    if (this.disposed) return;
    if (!provider || this.attempted.has(provider)) { this.emit('failed', 'Neither video source could be played. Retry playback.'); return; }
    this.pending = this.pending ?? this.position; this.adapter = null; this.duration = null;
    this.provider = provider; this.attempted.add(provider); this.emit('loading');
    if (provider === 'dropbox') await this.link(false);
  }
  async link(refresh) {
    if (this.inflight || this.disposed) return;
    const generation = ++this.generation;
    this.abort = new AbortController(); this.inflight = true;
    this.emit(refresh ? 'refreshing' : 'loading');
    try {
      const result = await this.fetchLink({ videoId: this.videoId, refresh, linkId: this.linkId, signal: this.abort.signal });
      if (this.disposed || generation !== this.generation) return;
      if (String(result.video_id) !== String(this.videoId) || !/^https:\/\//.test(result.temporary_link || '')) throw new Error('Invalid media identity');
      this.url = result.temporary_link; this.linkId = result.link_id;
      this.emit('loading');
    } catch (error) {
      if (!this.disposed && generation === this.generation && error.name !== 'AbortError') {
        if ([401, 403].includes(error.status)) this.emit('failed', 'You do not have access to this video. Sign in or contact an administrator.');
        else if (this.youtube && !this.attempted.has('youtube')) this.select('youtube');
        else this.emit('failed', error.code === 'media_missing' ? 'The video file was not found in Dropbox.'
          : error.code === 'provider_access' ? 'Dropbox access is unavailable. Please contact an administrator.'
          : 'Video media could not be loaded. Retry playback.');
      }
    } finally { if (generation === this.generation) this.inflight = false; }
  }
  ready(adapter, duration) {
    if (this.disposed) return;
    this.adapter = adapter;
    if (Number.isFinite(duration) && duration > 0) this.duration = duration;
    // YouTube may be ready before its duration is known. Keep the seek queued.
    this.emit('ready'); this.applySeek();
    if (this.wantPlay && this.pending === null) this.play();
  }
  metadata(duration) { if (Number.isFinite(duration) && duration > 0) { this.duration = duration; const queued = this.pending !== null; this.applySeek(); if (queued && this.pending === null && this.wantPlay) this.play(); } }
  seek(value) {
    try { this.pending = seekSeconds(value); this.applySeek(); }
    catch (e) { this.emit(this.adapter ? 'ready' : this.state || 'loading', e.message); return false; }
    return true;
  }
  applySeek() {
    if (this.pending === null || !this.adapter || !this.duration) return;
    if (this.pending >= this.duration) { this.pending = null; this.emit('ready', 'The passage timestamp is outside this video’s duration.'); return; }
    const target = this.pending;
    this.adapter.seek(target); this.position = target; this.pending = null; this.emit('ready');
  }
  progress(value) { if (!this.disposed && Number.isFinite(value) && value >= 0) this.position = value; }
  async play() {
    this.wantPlay = true;
    if (!this.adapter || this.pending !== null) return;
    try { await this.adapter.play(); }
    catch (e) { if (e.name === 'NotAllowedError') { this.wantPlay = false; this.emit('ready', 'Press Play to start the video.'); } else this.fail('play'); }
  }
  pause() { this.wantPlay = false; this.adapter?.pause(); }
  fail(kind, position) {
    if (this.disposed || this.inflight) return;
    if (kind === 'autoplay') { this.wantPlay = false; this.emit('ready', 'Press Play to start the video.'); return; }
    this.progress(position); this.pending = this.pending ?? this.position; this.adapter = null;
    if (this.provider === 'dropbox' && kind !== 'unsupported' && this.refreshes < 1) {
      this.refreshes++; return this.link(true);
    }
    const fallback = this.provider === 'youtube' && this.dropbox ? 'dropbox' : this.youtube ? 'youtube' : null;
    return this.select(fallback);
  }
  retry() { this.abort?.abort(); this.generation++; this.inflight = false; this.attempted.clear(); this.refreshes = 0; this.url = null; this.linkId = null; return this.start(); }
  dispose() { this.disposed = true; this.generation++; this.abort?.abort(); this.adapter = null; }
}
