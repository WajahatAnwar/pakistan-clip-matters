import React, { useEffect, useRef, useState, useImperativeHandle, forwardRef } from 'react';
import { PlaybackSession, youtubeId } from '../utils/playbackSession';
let youtubePromise;
function loadYouTube() {
  if (window.YT?.Player) return Promise.resolve(window.YT);
  if (!youtubePromise) youtubePromise = new Promise((resolve, reject) => {
    const previous = window.onYouTubeIframeAPIReady;
    const timer = setTimeout(() => { youtubePromise = null; reject(new Error('YouTube load timeout')); }, 15000);
    window.onYouTubeIframeAPIReady = () => { clearTimeout(timer); previous?.(); resolve(window.YT); };
    if (!document.querySelector('script[src="https://www.youtube.com/iframe_api"]')) {
      const tag = document.createElement('script'); tag.src = 'https://www.youtube.com/iframe_api';
      tag.onerror = () => { clearTimeout(timer); youtubePromise = null; tag.remove(); reject(new Error('YouTube unavailable')); };
      document.head.appendChild(tag);
    }
  });
  return youtubePromise;
}
export default forwardRef(function ReliableVideoPlayer({ videoId, youtube, hasDropbox, onTime, onDuration, onPlaying, onLink }, ref) {
  const [view, setView] = useState({ state: 'loading' });
  const session = useRef(); const native = useRef(); const host = useRef();
  const callbacks = useRef(); callbacks.current = { onTime, onDuration, onPlaying, onLink };
  useImperativeHandle(ref, () => ({
    seek: value => session.current?.seek(value), play: () => session.current?.play(), pause: () => session.current?.pause(),
    ready: () => !!session.current?.adapter, position: () => session.current?.position || 0,
  }), []);
  useEffect(() => {
    const s = new PlaybackSession({ videoId, youtube: youtubeId(youtube), dropbox: hasDropbox,
      change: setView, fetchLink: async ({ videoId, refresh, linkId, signal }) => {
        const timeout = setTimeout(() => controller.abort(), 30000);
        const controller = new AbortController(); const abort = () => controller.abort();
        signal.addEventListener('abort', abort, { once: true });
        try {
          const response = await fetch(route('video.link'), { method: 'POST', signal: controller.signal,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({ video_id: videoId, refresh, link_id: linkId }) });
          if (!response.ok) { const e = new Error('Media unavailable'); e.status = response.status;
            const failure = await response.json().catch(() => ({}));
            if (['media_missing','provider_access'].includes(failure.code)) e.code = failure.code;
            throw e; }
          const result = await response.json();
          if (!signal.aborted) callbacks.current.onLink?.(result.temporary_link);
          return result;
        } catch (error) {
          if (error.name === 'AbortError' && !signal.aborted) throw new Error('Media request timed out');
          throw error;
        } finally { clearTimeout(timeout); signal.removeEventListener('abort', abort); }
      } });
    session.current = s; s.start();
    return () => { s.dispose(); callbacks.current.onPlaying?.(false); };
  }, [videoId, youtube, hasDropbox]);
  useEffect(() => {
    const s = session.current; if (!s || view.provider !== 'youtube') return;
    let cancelled = false, player, poll;
    const timeout = setTimeout(() => { if (!cancelled) s.fail('availability', s.position); }, 20000);
    loadYouTube().then(YT => {
      if (cancelled || s.disposed || s.provider !== 'youtube') return;
      const node = document.createElement('div'); host.current.replaceChildren(node);
      player = new YT.Player(node, { videoId: s.youtube, width: '100%', height: '100%', playerVars: { autoplay: 0, playsinline: 1, origin: window.location.origin }, events: {
        onReady: () => {
          if (cancelled || s.provider !== 'youtube') return; clearTimeout(timeout);
          s.ready({ seek: t => player.seekTo(t, true), play: () => player.playVideo(), pause: () => player.pauseVideo() }, player.getDuration());
          const metadataDeadline = setTimeout(() => { if (!cancelled && !s.duration) s.fail('availability', s.position); }, 15000);
          player.__metadataDeadline = metadataDeadline;
          poll = setInterval(() => { if (cancelled || s.provider !== 'youtube') return; const duration = player.getDuration(); s.metadata(duration); if (duration > 0) callbacks.current.onDuration?.(duration);
            const t = player.getCurrentTime(); s.progress(t); callbacks.current.onTime?.(t); }, 250);
        },
        onStateChange: event => { if (cancelled || s.provider !== 'youtube') return; callbacks.current.onPlaying?.(event.data === 1); if (event.data === 1) s.emit('playing'); else if ([0, 2, 5].includes(event.data)) s.emit('ready'); },
        onAutoplayBlocked: () => { if (!cancelled) s.fail('autoplay'); },
        onError: event => { if (!cancelled) { clearTimeout(timeout); s.fail(event.data === 2 ? 'unsupported' : 'availability', player?.getCurrentTime?.()); } },
      } });
    }).catch(() => { if (!cancelled && s.provider === 'youtube') s.fail('availability'); });
    return () => { cancelled = true; clearTimeout(timeout); clearInterval(poll); clearTimeout(player?.__metadataDeadline); try { player?.destroy(); } catch {} };
  }, [view.provider, videoId, youtube]);
  useEffect(() => {
    if (view.provider !== 'dropbox' || !view.url) return;
    const s = session.current;
    const timer = setTimeout(() => s.fail('network', native.current?.currentTime), 20000);
    const loaded = () => clearTimeout(timer); const element = native.current;
    element?.addEventListener('loadedmetadata', loaded);
    if (element?.readyState >= 1) clearTimeout(timer);
    return () => { clearTimeout(timer); element?.removeEventListener('loadedmetadata', loaded); element?.pause(); };
  }, [view.provider, view.url, view.linkId]);
  const ready = e => {
    const s = session.current, element = e.currentTarget;
    if (s.provider !== 'dropbox' || element !== native.current) return;
    s.ready({ seek: t => { element.currentTime = t; }, play: () => element.play(), pause: () => element.pause() }, element.duration);
    callbacks.current.onDuration?.(element.duration);
  };
  return <div style={{ width: '100%', height: '100%', position: 'relative', background: '#000' }}>
    {view.provider === 'youtube' && <div ref={host} style={{ width: '100%', height: '100%' }} />}
    {view.provider === 'dropbox' && view.url && <video key={view.linkId || view.url} ref={native} src={view.url} controls playsInline preload="metadata"
      style={{ width: '100%', height: '100%', objectFit: 'contain' }} onLoadedMetadata={ready}
      onTimeUpdate={e => { session.current.progress(e.currentTarget.currentTime); callbacks.current.onTime?.(e.currentTarget.currentTime); }}
      onPlay={() => { session.current.emit('playing'); callbacks.current.onPlaying?.(true); }}
      onPause={() => callbacks.current.onPlaying?.(false)} onEnded={() => callbacks.current.onPlaying?.(false)}
      onError={e => session.current.fail(e.currentTarget.error?.code === 4 ? 'unsupported' : 'network', e.currentTarget.currentTime)} />}
    {(['loading', 'refreshing', 'failed'].includes(view.state) || view.message) && <div role={view.state === 'failed' || view.message ? 'alert' : 'status'}
      style={{ position: 'absolute', left: '10%', right: '10%', top: '35%', padding: 16, color: '#fff', background: '#1a1a1aeb', borderRadius: 8 }}>
      {view.message || (view.state === 'refreshing' ? 'Refreshing video link…' : 'Loading video…')}
      {view.state === 'failed' && <button onClick={() => session.current.retry()} style={{ marginLeft: 12 }}>Retry playback</button>}
    </div>}
  </div>;
});
