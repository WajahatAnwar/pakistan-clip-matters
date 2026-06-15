import UserAuthenticateLayout from '@/Layouts/UserAuthenticateLayout';
import React, { useState, useRef, useEffect } from 'react';
import { Box, Typography, Tabs, Tab, Button, IconButton, TextField, Dialog, DialogTitle, DialogContent, DialogActions, CircularProgress } from '@mui/material';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import DownloadIcon from '@mui/icons-material/Download';
import AddIcon from '@mui/icons-material/Add';
import PlayArrowIcon from '@mui/icons-material/PlayArrow';
import PauseIcon from '@mui/icons-material/Pause';
import ScissorsIcon from '@mui/icons-material/ContentCut';
import DeleteIcon from '@mui/icons-material/Delete';
import ClearAllIcon from '@mui/icons-material/ClearAll';
import ReactPlayer from 'react-player';
import { router, usePage } from '@inertiajs/react';
import { FFmpeg } from "@ffmpeg/ffmpeg";
import { fetchFile } from "@ffmpeg/util";
import JSZip from 'jszip';
import { useTranslation } from 'react-i18next';
import Swal from 'sweetalert2';
import RandomImage from '@/Images/random-data-grid.svg';

export default function index() {
  // Get videoId from route parameter
  const { props } = usePage();
  const videoIdFromRoute = props.videoId;
  const { t } = useTranslation();
  const [tabValue, setTabValue] = useState(0);
  const [selectedTranscript, setSelectedTranscript] = useState(2);
  const [youtubeUrl, setYoutubeUrl] = useState('');
  const [videoId, setVideoId] = useState('');
  const [clips, setClips] = useState([]);
  const [customClips, setCustomClips] = useState([]);
  const [selectedClip, setSelectedClip] = useState(null);
  const [isPlaying, setIsPlaying] = useState(false);
  const [currentTime, setCurrentTime] = useState(0);
  const [duration, setDuration] = useState(0);
  const [transcript, setTranscript] = useState([]);
  const [openTrimDialog, setOpenTrimDialog] = useState(false);
  const [trimStart, setTrimStart] = useState(0);
  const [trimEnd, setTrimEnd] = useState(0);
  const [chapters, setChapters] = useState([]);
  const [isLoadingChapters, setIsLoadingChapters] = useState(false);
  const [chaptersError, setChaptersError] = useState(null);
  const [isPreviewingClip, setIsPreviewingClip] = useState(false);
  const [clipPreviewStart, setClipPreviewStart] = useState(null);
  const [clipPreviewEnd, setClipPreviewEnd] = useState(null);
  const isPreviewingClipRef = useRef(false);
  const clipPreviewEndRef = useRef(null);
  const playerRef = useRef(null);
  const [temporaryLink, setTemporaryLink] = useState(null);
  const [videoStartTime, setVideoStartTime] = useState("00:00:00");
  const [videoEndTime, setVideoEndTime] = useState("00:00:00");
  const [ffmpegReady, setFfmpegReady] = useState(false);
  const [trimmingClipId, setTrimmingClipId] = useState(null);
  const [dragStart, setDragStart] = useState(null);
  const [dragEnd, setDragEnd] = useState(null);
  const [isDraggingStart, setIsDraggingStart] = useState(false);
  const [isDraggingEnd, setIsDraggingEnd] = useState(false);
  const [tempClipStart, setTempClipStart] = useState(null);
  const [tempClipEnd, setTempClipEnd] = useState(null);
  const [previewClip, setPreviewClip] = useState(null);
  const [downloadingAll, setDownloadingAll] = useState(false);
  const timelineRef = useRef(null);
  const ffmpegRef = useRef(new FFmpeg());
  const youtubePlayerRef = useRef(null);
  const timeUpdateIntervalRef = useRef(null);
  const [ytApiReady, setYtApiReady] = useState(false);
  const [summary, setSummary] = useState('');
  const [alternativeSummary, setAlternativeSummary] = useState('');
  const [showAlternativeSummary, setShowAlternativeSummary] = useState(false); // Toggle between primary and alternative summary
  const [engTranslation, setEngTranslation] = useState('');
  const [urduTranslation, setUrduTranslation] = useState('');
  const [detectedLanguage, setDetectedLanguage] = useState('');
  const [alternativeTranscript, setAlternativeTranscript] = useState([]);
  const [alternativeLanguage, setAlternativeLanguage] = useState('');
  const [showAlternativeTranscript, setShowAlternativeTranscript] = useState(false); // Toggle between primary and alternative

  // Download dialog state
  const [downloadDialogOpen, setDownloadDialogOpen] = useState(false);

  // Speaker tagging state
  const [speakerMapping, setSpeakerMapping] = useState(null);
  const [isSpeakerTagged, setIsSpeakerTagged] = useState(false);
  const [taggingInProgress, setTaggingInProgress] = useState(false);
  const [currentVideoId, setCurrentVideoId] = useState(null);

  // Loading state
  const [isLoadingVideo, setIsLoadingVideo] = useState(false);

  // Download progress state
  const [downloadProgress, setDownloadProgress] = useState(0);
  const [downloadStatus, setDownloadStatus] = useState('');

  // Video title state
  const [videoTitle, setVideoTitle] = useState('');

  // Dropbox fallback player state
  const [useDropboxPlayer, setUseDropboxPlayer] = useState(false);
  const [youtubeError, setYoutubeError] = useState(false);
  const [dropboxStreamUrl, setDropboxStreamUrl] = useState(null);
  const [storedDropboxPath, setStoredDropboxPath] = useState(null);
  const [dbDurationSeconds, setDbDurationSeconds] = useState(null);
  const reactPlayerRef = useRef(null);
  const dropboxPlayingRef = useRef(false);

  // Get clip thumbnail - always use default thumbnail
  const getClipThumbnail = () => {
    return RandomImage;
  };

  // ========================================
  // UNIFIED PLAYER HELPERS (YouTube + Dropbox)
  // ========================================
  const playerSeekTo = (seconds) => {
    if (useDropboxPlayer && reactPlayerRef.current) {
      reactPlayerRef.current.currentTime = seconds;
      setCurrentTime(seconds);
    } else if (youtubePlayerRef.current && typeof youtubePlayerRef.current.seekTo === 'function') {
      youtubePlayerRef.current.seekTo(seconds, true);
      setCurrentTime(seconds);
    } else {
      console.warn('No player available for seeking');
    }
  };

  const playerPlay = () => {
    if (useDropboxPlayer && reactPlayerRef.current) {
      reactPlayerRef.current.play().catch(e => console.warn('Play failed:', e));
      setIsPlaying(true);
    } else if (youtubePlayerRef.current && typeof youtubePlayerRef.current.playVideo === 'function') {
      youtubePlayerRef.current.playVideo();
      setIsPlaying(true);
    }
  };

  const playerPause = () => {
    if (useDropboxPlayer && reactPlayerRef.current) {
      reactPlayerRef.current.pause();
      setIsPlaying(false);
    } else if (youtubePlayerRef.current && typeof youtubePlayerRef.current.pauseVideo === 'function') {
      youtubePlayerRef.current.pauseVideo();
      setIsPlaying(false);
    }
  };

  const isPlayerReady = () => {
    if (useDropboxPlayer) return !!(reactPlayerRef.current && reactPlayerRef.current.readyState >= 2);
    return !!(youtubePlayerRef.current && typeof youtubePlayerRef.current.seekTo === 'function');
  };

  // Switch to Dropbox player when YouTube is unavailable
  const switchToDropboxPlayer = async (dropboxPath) => {
    console.log('Switching to Dropbox player for path:', dropboxPath);
    let streamUrl = temporaryLink;

    if (!streamUrl && dropboxPath) {
      streamUrl = await getVideoTemporaryLink(dropboxPath);
    }

    if (streamUrl) {
      // Destroy YouTube player if it exists
      if (youtubePlayerRef.current && youtubePlayerRef.current.destroy) {
        try { youtubePlayerRef.current.destroy(); } catch (e) { }
        youtubePlayerRef.current = null;
      }
      setDropboxStreamUrl(streamUrl);
      setUseDropboxPlayer(true);
      setYoutubeError(true);

      // Use DB duration as fallback until ReactPlayer reports actual duration
      if (dbDurationSeconds && duration === 0) {
        setDuration(dbDurationSeconds);
        setVideoEndTime(formatTime(dbDurationSeconds));
      }
    } else {
      Swal.fire({
        icon: 'error',
        title: t('mainVideoPage.messages.videoUnavailable'),
        text: t('mainVideoPage.messages.videoUnavailableText'),
        confirmButtonColor: '#E11D48',
      });
    }
  };

  // Load YouTube IFrame API
  useEffect(() => {
    if (!window.YT) {
      const tag = document.createElement('script');
      tag.src = 'https://www.youtube.com/iframe_api';
      const firstScriptTag = document.getElementsByTagName('script')[0];
      firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);

      window.onYouTubeIframeAPIReady = () => {
        console.log('YouTube IFrame API loaded and ready');
        setYtApiReady(true);
      };
    } else if (window.YT && window.YT.Player) {
      setYtApiReady(true);
    }
  }, []);

  // Load FFmpeg
  useEffect(() => {
    const loadFFmpeg = async () => {
      try {
        console.log("Loading FFmpeg...");
        const ffmpeg = ffmpegRef.current;
        ffmpeg.on('log', ({ message }) => {
          console.log('FFmpeg:', message);
        });
        await ffmpeg.load();
        console.log("FFmpeg loaded successfully");
        setFfmpegReady(true);
      } catch (error) {
        console.error("Error loading FFmpeg:", error);
      }
    };
    loadFFmpeg();
  }, []);

  // Create YouTube player when API is ready (skip if using Dropbox player)
  useEffect(() => {
    if (useDropboxPlayer) return; // Skip YouTube player creation in Dropbox mode
    if (ytApiReady && videoId && window.YT && window.YT.Player) {
      // Destroy existing player if any
      if (youtubePlayerRef.current && youtubePlayerRef.current.destroy) {
        youtubePlayerRef.current.destroy();
      }

      console.log('Creating YouTube player for video:', videoId);

      youtubePlayerRef.current = new window.YT.Player('youtube-player', {
        videoId: videoId,
        playerVars: {
          autoplay: 0,
          controls: 1,
          modestbranding: 1,
          rel: 0,
          fs: 1,
          disablekb: 0,
          showinfo: 0,
          iv_load_policy: 3,  // Hide video annotations
          cc_load_policy: 0,  // Don't show captions by default
        },
        events: {
          onReady: onYouTubePlayerReady,
          onStateChange: onYouTubePlayerStateChange,
          onError: onYouTubePlayerError
        }
      });
    }
  }, [ytApiReady, videoId, useDropboxPlayer]);

  // Extract YouTube video ID from URL - defined early so it can be used in other functions
  const extractVideoId = (url) => {
    const regExp = /^.*((youtu.be\/)|(v\/)|(\/u\/\w\/)|(embed\/)|(watch\?))\??v?=?([^#&?]*).*/;
    const match = url.match(regExp);
    return (match && match[7].length === 11) ? match[7] : null;
  };

  // Load video from database when coming from search results
  useEffect(() => {
    if (videoIdFromRoute) {
      console.log('Loading video from database, ID:', videoIdFromRoute);
      loadVideoFromDatabase(videoIdFromRoute);
    }
  }, [videoIdFromRoute]);

  // Function to load video data from database
  const loadVideoFromDatabase = async (dbVideoId) => {
    setIsLoadingVideo(true);
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const response = await fetch(route('user.youtube.details'), {
        method: 'POST',
        body: JSON.stringify({ video_id: dbVideoId }),
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'Content-Type': 'application/json'
        }
      });

      const data = await response.json();
      console.log('Video data from database:', data);

      if (data.success && data.video) {
        // Set common metadata regardless of player type
        setCurrentVideoId(dbVideoId);
        setVideoTitle(data.video.title || '');
        setSummary(data.video.summary || '');
        setAlternativeSummary(data.video.alternative_summary || '');
        setEngTranslation(data.video.eng_translation || '');
        setUrduTranslation(data.video.urdu_translation || '');
        setDetectedLanguage(data.video.language_detected || '');
        setAlternativeLanguage(data.video.alternative_language || '');

        // Store Dropbox path and DB duration for fallback
        if (data.video.dropbox_path) {
          setStoredDropboxPath(data.video.dropbox_path);
        }
        if (data.duration_seconds) {
          setDbDurationSeconds(data.duration_seconds);
        }

        // Load primary transcript (speakers_data - original language)
        if (data.video.speakers_data) {
          const parsedTranscript = data.video.speakers_data.map(item => {
            // Handle both millisecond and second formats
            const startSec = item.start > 10000 ? item.start / 1000 : item.start;
            const endSec = item.end > 10000 ? item.end / 1000 : item.end;

            return {
              time: `${formatTime(startSec)} - ${formatTime(endSec)}`,
              start: startSec,
              end: endSec,
              seconds: startSec,
              speaker: item.speaker || 'Unknown',
              actualSpeaker: item.actualSpeaker || item.speaker || 'Unknown',
              text: item.text || ''
            };
          });
          setTranscript(parsedTranscript);

          const hasRealNames = parsedTranscript.some(item =>
            item.actualSpeaker &&
            !['A', 'B', 'C', 'D', 'Unknown'].includes(item.actualSpeaker)
          );
          setIsSpeakerTagged(hasRealNames);
          console.log('Speakers tagged with real names:', hasRealNames);
        }

        // Load alternative transcript
        if (data.video.alternative_transcript) {
          const parsedAltTranscript = data.video.alternative_transcript.map(item => {
            const startSec = item.start > 10000 ? item.start / 1000 : item.start;
            const endSec = item.end > 10000 ? item.end / 1000 : item.end;

            return {
              time: `${formatTime(startSec)} - ${formatTime(endSec)}`,
              start: startSec,
              end: endSec,
              seconds: startSec,
              speaker: item.speaker || 'Unknown',
              actualSpeaker: item.actualSpeaker || item.speaker || 'Unknown',
              text: item.text || ''
            };
          });
          setAlternativeTranscript(parsedAltTranscript);
        }

        // Determine player mode: YouTube or Dropbox
        const ytUrl = data.video.youtube_url;
        const extractedId = ytUrl ? extractVideoId(ytUrl) : null;

        if (extractedId) {
          // Has valid YouTube URL — try YouTube first (will auto-fallback on error)
          setYoutubeUrl(ytUrl);
          setVideoId(extractedId);

        // Pre-fetch Dropbox temporary link for clip downloads + fallback
          if (data.video.dropbox_path) {
            console.log('Pre-fetching Dropbox temporary link for fallback:', data.video.dropbox_path);
            await getVideoTemporaryLink(data.video.dropbox_path, extractedId);
          }
        } else if (data.video.dropbox_path) {
          // No YouTube URL — go directly to Dropbox streaming
          console.log('No YouTube URL, using Dropbox player directly');
          if (data.duration_seconds) {
            setDuration(data.duration_seconds);
            setVideoEndTime(formatTime(data.duration_seconds));
          }
          await switchToDropboxPlayer(data.video.dropbox_path);
        }

        // Load speaker mapping
        if (data.video.speaker_mapping) {
          setSpeakerMapping(data.video.speaker_mapping);
        }
      }
    } catch (error) {
      console.error('Error loading video from database:', error);
    } finally {
      setIsLoadingVideo(false);
    }
  };

  // Process segments from search results and create clips
  useEffect(() => {
    // Retrieve segments from sessionStorage if available
    const storedSegments = sessionStorage.getItem('searchSegments');

    if (storedSegments && duration > 0 && (videoId || useDropboxPlayer)) {
      try {
        const segmentsFromSearch = JSON.parse(storedSegments);
        console.log('Creating clips from search segments:', segmentsFromSearch);

        if (Array.isArray(segmentsFromSearch) && segmentsFromSearch.length > 0) {
          const searchClips = segmentsFromSearch
            .filter(segment => segment.start_time || segment.end_time) // Skip segments with no real time range
            .map((segment, index) => ({
            id: `search-${index}`,
            start: segment.start_time,
            end: segment.end_time,
            thumbnail: getClipThumbnail(),
            title: `${segment.speaker || 'Speaker'} - ${formatTime(segment.start_time)}`,
            speaker: segment.speaker,
            text: segment.text || segment.text_preview || ''
          }));

          // Add to customClips so they display in the UI
          setCustomClips(searchClips);

          if (searchClips.length > 0) {
            setSelectedClip(0);
            // Jump to the first segment after a short delay to ensure player is ready
            setTimeout(() => {
              if (isPlayerReady()) {
                playerSeekTo(searchClips[0].start);
                console.log('Jumped to first search result at:', searchClips[0].start);
              }
            }, 1000);
          }

          console.log('Created', searchClips.length, 'clips from search segments');

          // Clear the stored segments after using them
          sessionStorage.removeItem('searchSegments');
        }
      } catch (error) {
        console.error('Error parsing segments from sessionStorage:', error);
        sessionStorage.removeItem('searchSegments');
      }
    }
  }, [duration, videoId, useDropboxPlayer]);

  // Fetch YouTube video chapters using backend API
  const fetchYouTubeChapters = async (videoId) => {
    setIsLoadingChapters(true);
    setChaptersError(null);

    try {
      console.log('Fetching real chapters for video:', videoId);

      // Call our Laravel backend to get YouTube chapters
      const response = await fetch('/user/youtube/chapters', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ video_id: videoId })
      });

      const result = await response.json();
      console.log('Backend response:', result);

      if (result.success && result.chapters && result.chapters.length > 0) {
        // Use real chapters from backend
        const generatedClips = result.chapters.map((chapter, idx) => ({
          id: idx,
          start: chapter.start,
          end: chapter.end || (result.chapters[idx + 1]?.start || chapter.start + 60),
          thumbnail: RandomImage,
          title: chapter.title || `Chapter ${idx + 1}`,
        }));

        // Adjust the last chapter's end time to video duration if available
        if (generatedClips.length > 0 && duration > 0) {
          generatedClips[generatedClips.length - 1].end = duration;
        }

        console.log('Setting real YouTube clips:', generatedClips);
        setClips(generatedClips);
        setChapters(generatedClips);
        setIsLoadingChapters(false);
      } else {
        // Fallback: Generate chapters from transcript timestamps
        console.log('No YouTube chapters found, using transcript-based chapters');
        generateTranscriptChapters(videoId);
      }

    } catch (error) {
      console.error('Error fetching chapters from backend:', error);
      setChaptersError('Failed to load chapters. Using fallback segments.');
      // Fallback: Generate chapters from transcript
      generateTranscriptChapters(videoId);
    } finally {
      setIsLoadingChapters(false);
    }
  };

  // Generate chapters from transcript data or create time-based segments
  const generateTranscriptChapters = (ytVideoId) => {
    console.log('Generating fallback chapters, duration:', duration);
    const thumb = RandomImage;

    // If we have transcript data, use it
    if (transcript && transcript.length > 0) {
      const transcriptChapters = [];

      // Create chapters from transcript entries
      transcript.forEach((item, index) => {
        const nextItem = transcript[index + 1];
        const endTime = nextItem ? nextItem.seconds : (duration || item.seconds + 30);

        transcriptChapters.push({
          id: index,
          start: item.seconds,
          end: endTime,
          thumbnail: thumb,
          title: item.time,
          subtitle: item.text.substring(0, 50) + '...'
        });
      });

      console.log('Generated transcript-based chapters:', transcriptChapters.length);
      setClips(transcriptChapters);
      setChapters(transcriptChapters);
    } else if (duration > 0) {
      // Create time-based chapters (every 5 minutes for any video)
      const timeBasedChapters = [];
      const chapterInterval = 300; // 5 minutes
      const numChapters = Math.ceil(duration / chapterInterval);

      for (let i = 0; i < numChapters; i++) {
        const start = i * chapterInterval;
        const end = Math.min((i + 1) * chapterInterval, duration);

        timeBasedChapters.push({
          id: i,
          start: start,
          end: end,
          thumbnail: thumb,
          title: i === 0 ? 'Introduction' : `Chapter ${i + 1}`,
        });
      }

      console.log('Generated time-based chapters:', timeBasedChapters.length);
      setClips(timeBasedChapters);
      setChapters(timeBasedChapters);
    } else {
      // Ultimate fallback: create basic segments
      const basicChapters = [
        { id: 0, start: 0, end: 300, thumbnail: thumb, title: 'Beginning' },
        { id: 1, start: 300, end: 600, thumbnail: thumb, title: 'Middle' },
        { id: 2, start: 600, end: 900, thumbnail: thumb, title: 'End' },
      ];

      console.log('Generated basic fallback chapters:', basicChapters.length);
      setClips(basicChapters);
      setChapters(basicChapters);
    }
  };

  // Auto-generate clips from transcript when video is loaded
  useEffect(() => {
    const id = extractVideoId(youtubeUrl);
    if (id) {
      setVideoId(id);
      console.log('VideoId extracted:', id);

      // Clear existing clips and state when URL changes
      setClips([]);
      setSelectedClip(null);
      setDuration(0);
      setCurrentTime(0);
      setVideoStartTime("00:00:00");
      setVideoEndTime("00:00:00");
      setTemporaryLink(null);
    }
  }, [youtubeUrl]);

  // Fetch chapters when both videoId and duration are available
  useEffect(() => {
    console.log('Effect triggered - videoId:', videoId, 'duration:', duration, 'clips length:', clips.length, 'useDropboxPlayer:', useDropboxPlayer);
    if (videoId && duration > 0 && clips.length === 0) {
      console.log('Conditions met - fetching chapters for video:', videoId);
      fetchYouTubeChapters(videoId);
    } else if (!videoId && useDropboxPlayer && duration > 0 && clips.length === 0) {
      // Dropbox-only mode: no YouTube videoId, generate chapters from transcript or time-based
      console.log('Dropbox mode - generating chapters from transcript/duration');
      generateTranscriptChapters(null);
    }
  }, [videoId, duration, clips.length, useDropboxPlayer]);

  // Auto-generate clips from transcript sections
  const autoGenerateClips = () => {
    if (!duration || clips.length > 0) return;

    const generatedClips = [];
    for (let i = 0; i < transcript.length; i++) {
      const startTime = transcript[i].seconds;
      const endTime = i < transcript.length - 1 ? transcript[i + 1].seconds : startTime + 10;

      generatedClips.push({
        id: i,
        start: startTime,
        end: endTime,
        thumbnail: getClipThumbnail(),
        title: transcript[i].time,
      });
    }

    setClips(generatedClips);
  };

  const handleUrlChange = (url) => {
    setYoutubeUrl(url);
    console.log('YouTube URL changed to:', url);
    validateAndFetchVideo(url);
  };

  const validateAndFetchVideo = async (url) => {
    setIsLoadingVideo(true);
    try {
      // Step 1: Validate the YouTube URL with backend
      const validateResponse = await fetch(route('user.youtube.validate'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ youtube_url: url })
      });

      // Check if response is ok before parsing
      if (!validateResponse.ok) {
        Swal.fire({
          icon: 'error',
          title: t('mainVideoPage.messages.invalidUrl'),
          text: t('mainVideoPage.messages.invalidUrlText'),
          confirmButtonColor: '#E11D48'
        });
        return;
      }

      const result = await validateResponse.json();
      console.log('Validation response:', result);
      const transcriptData = result?.video?.speakers_data || [];
      const videoDbId = result?.video?.id;

      // Store video ID for tagging
      if (videoDbId) {
        setCurrentVideoId(videoDbId);
        console.log('Video DB ID set:', videoDbId);
      }

      // Parse and set transcript data (timestamps are in milliseconds from AssemblyAI)
      const parsedTranscript = transcriptData.map(item => {
        // Handle both millisecond and second formats
        const startSec = item.start > 10000 ? item.start / 1000 : item.start;
        const endSec = item.end > 10000 ? item.end / 1000 : item.end;

        return {
          time: `${formatTime(startSec)} - ${formatTime(endSec)}`, // display only
          start: startSec,          // numeric start (seconds)
          end: endSec,              // numeric end (seconds)
          seconds: startSec,        // you can keep this if used elsewhere
          speaker: item.speaker || 'Unknown',
          // Use actualSpeaker from backend (set by SpeakerTaggingService)
          actualSpeaker: item.actualSpeaker || item.speaker || 'Unknown',
          text: item.text || ''
        };
      });


      setTranscript(parsedTranscript);
      console.log('Transcript data set:', parsedTranscript);

      // Check if transcript has actual speaker names (already tagged)
      const hasActualSpeakers = parsedTranscript.some(item =>
        item.actualSpeaker &&
        !['A', 'B', 'C', 'D', 'Unknown'].includes(item.actualSpeaker)
      );

      setIsSpeakerTagged(hasActualSpeakers);
      console.log('Has actual speaker names:', hasActualSpeakers, 'First speaker:', parsedTranscript[0]?.actualSpeaker);

      // Automatically tag speakers if not already tagged
      if (!hasActualSpeakers && videoDbId && transcriptData.length > 0) {
        console.log('ðŸ¤– Auto-tagging speakers automatically...');
        // Tag speakers and wait for completion to update transcript
        await autoTagSpeakers(videoDbId);
      }

      // Check if validation was successful and path exists
      if (result?.video?.path) {
        const videoPath = result.video.path;
        // Store dropbox path for potential fallback
        setStoredDropboxPath(videoPath);

        // Step 2: Fetch the temporary download link
        const tempLink = await getVideoTemporaryLink(videoPath);

        console.log('Temporary video link:', tempLink);
      } else if (result?.video?.dropbox_path) {
        // Alternative field name
        setStoredDropboxPath(result.video.dropbox_path);
        const tempLink = await getVideoTemporaryLink(result.video.dropbox_path);
        console.log('Temporary video link from dropbox_path:', tempLink);
      } else {
        console.warn('Video path not returned from validation.');
      }
    } catch (error) {
      console.error('Error during YouTube URL processing:', error);
    } finally {
      setIsLoadingVideo(false);
    }
  };

  const getVideoTemporaryLink = async (videoPath, youtubeVideoId) => {
    try {
      const response = await fetch(route('video.link'), {
        method: 'POST', // Use POST if sending a body
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ dropbox_path: videoPath })
      });

      const result = await response.json();
      setTemporaryLink(result?.temporary_link || null);

      // Use the passed YouTube video ID instead of state variable
      if (youtubeVideoId) {
        fetchVideoDetails(youtubeVideoId, document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
      }

      console.log('Temporary link response:', result);
      return result?.temporary_link || null;
    } catch (error) {
      console.error('Failed to fetch temporary video link:', error);
      return null;
    }
  };

  // Tag speakers in video transcript (manual trigger)
  const handleTagSpeakers = async () => {
    if (!currentVideoId) {
      Swal.fire({
        icon: 'warning',
        title: t('mainVideoPage.messages.noVideo'),
        text: t('mainVideoPage.messages.noVideoText'),
        confirmButtonColor: '#E11D48'
      });
      return;
    }

    if (taggingInProgress) {
      return;
    }

    setTaggingInProgress(true);
    console.log('Starting speaker tagging for video ID:', currentVideoId);

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

      const response = await fetch(`/user/videos/${currentVideoId}/tag-speakers`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken
        }
      });

      const result = await response.json();

      if (result.success) {
        console.log('Speaker tagging successful:', result);

        // Store speaker mapping
        setSpeakerMapping(result.data.speaker_mapping);
        setIsSpeakerTagged(true);


        // Refresh transcript with tagged data
        await fetchTaggedTranscript();

        alert(`âœ… Speakers tagged successfully!\n\nTagged: ${result.data.tagged_segments_count} segments\nUntagged: ${result.data.untagged_segments_count} segments`);
      } else {
        console.error('Speaker tagging failed:', result.message);
        alert(`Failed to tag speakers: ${result.message}`);
      }
    } catch (error) {
      Swal.fire({
        icon: 'error',
        title: t('common.error'),
        text: t('mainVideoPage.messages.tagError'),
        confirmButtonColor: '#E11D48'
      });
    } finally {
      setTaggingInProgress(false);
    }
  };

  // Auto-tag speakers silently in background
  const autoTagSpeakers = async (videoId) => {
    if (!videoId || taggingInProgress) {
      return;
    }

    setTaggingInProgress(true);
    console.log('ðŸ¤– Auto-tagging speakers for video ID:', videoId);

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

      const response = await fetch(`/user/videos/${videoId}/tag-speakers`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken
        }
      });

      const result = await response.json();

      if (result.success) {
        console.log('âœ… Auto-tagging successful:', result);
        console.log(`ðŸ“Š Tagged ${result.data.tagged_segments_count} segments, ${result.data.untagged_segments_count} untagged`);
        console.log('ðŸŽ­ Speaker mapping received:', result.data.speaker_mapping);

        // Store speaker mapping
        setSpeakerMapping(result.data.speaker_mapping);
        setIsSpeakerTagged(true);

        // Refresh transcript with tagged data silently
        await fetchTaggedTranscript();

        console.log('ðŸŽ‰ Speakers automatically tagged with names:', result.data.speaker_mapping);
      } else {
        console.warn('âš ï¸ Auto-tagging failed (may not have diarization data):', result.message);
        // Don't show alert for auto-tagging failures
      }
    } catch (error) {
      console.warn('Auto-tagging error (video may not be ready):', error);
      // Silently fail - don't interrupt user experience
    } finally {
      setTaggingInProgress(false);
    }
  };

  // Fetch tagged transcript from server
  const fetchTaggedTranscript = async () => {
    if (!currentVideoId) {
      console.warn('No video ID available for fetching tagged transcript');
      return;
    }

    try {
      console.log('ðŸ”„ Fetching tagged transcript for video:', currentVideoId);
      const response = await fetch(`/user/videos/${currentVideoId}/tagged-transcript`);
      const result = await response.json();

      if (result.success) {
        console.log('âœ… Tagged transcript fetched successfully');
        console.log('ðŸ“Š Full API response:', JSON.stringify(result, null, 2));

        const taggedData = result.data.transcript || [];
        console.log(`ðŸ“ Transcript array length: ${taggedData.length}`);

        // Log first transcript item to see exact structure
        if (taggedData.length > 0) {
          console.log('ðŸ“„ First transcript item:', JSON.stringify(taggedData[0], null, 2));
        }

        // Parse and update transcript with actual speaker names
        const parsedTranscript = taggedData.map((item, index) => {
          // Timestamps are already in milliseconds from backend
          const startSec = (item.start || 0) / 1000; // Convert ms to seconds
          const endSec = (item.end || 0) / 1000;

          // Log first few items for debugging
          if (index < 3) {
            console.log(`Segment ${index}:`, {
              original: item,
              speaker: item.speaker,
              actualSpeaker: item.actualSpeaker,
              startMs: item.start,
              startSec: startSec
            });
          }

          return {
            time: `${formatTime(startSec)} - ${formatTime(endSec)}`,
            start: startSec,
            end: endSec,
            seconds: startSec,
            speaker: item.speaker || 'Unknown',
            actualSpeaker: item.actualSpeaker || item.speaker || 'Unknown',
            text: item.text || ''
          };
        });

        setTranscript(parsedTranscript);

        // Update speaker mapping
        if (result.data.speaker_mapping && Object.keys(result.data.speaker_mapping).length > 0) {
          setSpeakerMapping(result.data.speaker_mapping);
          setIsSpeakerTagged(true);
          console.log('ðŸŽ­ Speaker mapping:', JSON.stringify(result.data.speaker_mapping, null, 2));
          console.log('ðŸ—£ï¸ First segment actualSpeaker after parse:', parsedTranscript[0]?.actualSpeaker);
        } else {
          console.warn('âš ï¸  No speaker mapping found in response');
        }

        console.log(`âœ… Transcript state updated with ${parsedTranscript.length} segments`);
      } else {
        console.error('âŒ Failed to fetch tagged transcript:', result.message);
      }
    } catch (error) {
      console.error('âŒ Error fetching tagged transcript:', error);
    }
  };


  const handlePlayPause = () => {
    if (isPlaying) {
      playerPause();
    } else {
      playerPlay();
    }
  };

  const handleProgress = (state) => {
    const current = state.playedSeconds;
    setCurrentTime(current);

    // ðŸ”¥ Find which transcript line is currently active
    if (transcript.length > 0) {
      const activeIndex = transcript.findIndex(
        (item) => current >= item.start && current < item.end
      );

      if (activeIndex !== -1 && activeIndex !== selectedTranscript) {
        setSelectedTranscript(activeIndex);
      }
    }

    // (rest of your existing logic)
    if (isPlaying) {
      console.log('Video time changed:', {
        currentTime: state.playedSeconds,
        formatted: formatTime(state.playedSeconds),
        played: state.played,
        playedSeconds: state.playedSeconds,
        loaded: state.loaded,
        loadedSeconds: state.loadedSeconds
      });
    }

    if (duration === 0 && videoId) {
      const dur = state.loadedSeconds || state.loaded || state.duration;
      if (dur && dur > 0) {
        console.log('Video duration from progress state:', dur);
        setDuration(dur);
        console.log('Calling fetchYouTubeChapters with videoId:', videoId);
        fetchYouTubeChapters(videoId);
      }
    }
  };


  const handleDuration = (dur) => {
    console.log('9:', dur);

    // ReactPlayer's onDuration callback provides the duration directly as a number
    // But we need to handle both cases just in case
    if (typeof dur === 'number' && dur > 0) {
      console.log('Setting duration:', dur);
      setDuration(dur);
      setVideoEndTime(formatTime(dur));

      // Fetch YouTube chapters when we have the duration
      if (videoId) {
        console.log('Calling fetchYouTubeChapters with videoId:', videoId);
        fetchYouTubeChapters(videoId);
      } else {
        console.log('VideoId not ready yet:', videoId);
      }
    } else {
      console.log('Invalid duration received:', dur);
    }
  };

  // YouTube IFrame API onReady event handler
  const onYouTubePlayerReady = (event) => {
    console.log('YouTube player ready!');

    // Get video duration
    const dur = event.target.getDuration();
    if (dur && dur > 0) {
      console.log('Video duration:', dur);
      setDuration(dur);
      setVideoEndTime(formatTime(dur));
    }

    // Start time update polling
    startTimeUpdatePolling();
  };

  // YouTube IFrame API onStateChange event handler
  const onYouTubePlayerStateChange = (event) => {
    console.log('YouTube player state changed:', event.data);

    // -1 (unstarted)
    // 0 (ended)
    // 1 (playing)
    // 2 (paused)
    // 3 (buffering)
    // 5 (video cued)

    if (event.data === 1) {
      // Playing
      setIsPlaying(true);
    } else if (event.data === 2) {
      // Paused
      setIsPlaying(false);
    } else if (event.data === 0) {
      // Ended
      setIsPlaying(false);
    }
  };

  // YouTube IFrame API onError event handler - triggers Dropbox fallback
  const onYouTubePlayerError = (event) => {
    // Error codes: 2=invalid param, 5=HTML5 error, 100=not found/private, 101/150=embed blocked
    console.error('YouTube player error:', event.data);
    const errorCode = event.data;

    if ([2, 5, 100, 101, 150].includes(errorCode)) {
      console.log('YouTube video unavailable (error code:', errorCode, '), attempting Dropbox fallback...');
      const dropboxPath = storedDropboxPath;
      if (dropboxPath || temporaryLink) {
        switchToDropboxPlayer(dropboxPath);
      } else {
        Swal.fire({
          icon: 'error',
          title: t('mainVideoPage.messages.videoUnavailable'),
          text: t('mainVideoPage.messages.videoUnavailableYt'),
          confirmButtonColor: '#E11D48',
        });
      }
    }
  };

  // Start time update polling using YouTube IFrame API (skip in Dropbox mode)
  const startTimeUpdatePolling = () => {
    // In Dropbox mode, ReactPlayer handles time updates via onProgress
    if (useDropboxPlayer) return;

    // Clear any existing interval
    if (timeUpdateIntervalRef.current) {
      clearInterval(timeUpdateIntervalRef.current);
    }

    console.log('Starting YouTube time polling with native API...');

    // Poll current time every 100ms for smooth updates
    timeUpdateIntervalRef.current = setInterval(() => {
      if (youtubePlayerRef.current && typeof youtubePlayerRef.current.getCurrentTime === 'function') {
        try {
          const currentSeconds = youtubePlayerRef.current.getCurrentTime();
          const playerState = youtubePlayerRef.current.getPlayerState();

          // Update time if video is loaded
          if (currentSeconds !== undefined && !isNaN(currentSeconds) && currentSeconds >= 0) {
            setCurrentTime(currentSeconds);

            // Check if we're previewing a clip and reached its end
            if (isPreviewingClipRef.current && clipPreviewEndRef.current && currentSeconds >= clipPreviewEndRef.current) {
              handleStopPreview();
            }

            // Auto-highlight current transcript (only when playing)
            if (playerState === 1 && transcript.length > 0) {
              const activeIndex = transcript.findIndex(
                (item) => currentSeconds >= item.start && currentSeconds < item.end
              );

              if (activeIndex !== -1 && activeIndex !== selectedTranscript) {
                setSelectedTranscript(activeIndex);
              }
            }
          }
        } catch (error) {
          // Silently handle errors - player might not be ready yet
        }
      }
    }, 100);
  };

  // Cleanup interval on unmount
  useEffect(() => {
    return () => {
      if (timeUpdateIntervalRef.current) {
        clearInterval(timeUpdateIntervalRef.current);
      }
    };
  }, []);

  // Restart polling when transcript changes (to update active transcript tracking)
  useEffect(() => {
    if (!useDropboxPlayer && youtubePlayerRef.current && transcript.length > 0) {
      console.log('Transcript updated, restarting time polling with', transcript.length, 'items');
    }
  }, [transcript, useDropboxPlayer]);

  const handleCreateClip = () => {
    const start = Math.max(0, currentTime - 5);
    const end = Math.min(duration, currentTime + 5);
    setTrimStart(start);
    setTrimEnd(end);
    setOpenTrimDialog(true);
  };

  const handleSaveClip = () => {
    const newClip = {
      id: Date.now(),
      start: trimStart,
      end: trimEnd,
      thumbnail: getClipThumbnail(),
      title: `Custom Clip ${customClips.length + 1}`,
    };
    // Add to custom clips instead of regular clips
    setCustomClips([...customClips, newClip]);
    setSelectedClip(customClips.length);
    setOpenTrimDialog(false);

    // Reset form
    setTrimStart(0);
    setTrimEnd(0);
  };

  // Delete individual clip
  const handleDeleteClip = (e, clipIndex) => {
    e.stopPropagation(); // Prevent clip selection when clicking delete

    Swal.fire({
      title: t('mainVideoPage.messages.deleteClip'),
      text: t('mainVideoPage.messages.deleteClipText'),
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#E11D48',
      cancelButtonColor: '#6B7280',
      confirmButtonText: t('mainVideoPage.messages.yesDeleteIt'),
      cancelButtonText: t('common.cancel')
    }).then((result) => {
      if (result.isConfirmed) {
        const newClips = customClips.filter((_, idx) => idx !== clipIndex);
        setCustomClips(newClips);

        // Reset selection if deleted clip was selected
        if (selectedClip === clipIndex) {
          setSelectedClip(null);
        } else if (selectedClip > clipIndex) {
          setSelectedClip(selectedClip - 1);
        }

        Swal.fire({
          icon: 'success',
          title: t('mainVideoPage.messages.deleted'),
          text: t('mainVideoPage.messages.clipDeleted'),
          confirmButtonColor: '#E11D48',
          timer: 2000
        });
      }
    });
  };

  // Delete all clips
  const handleDeleteAllClips = () => {
    Swal.fire({
      title: t('mainVideoPage.messages.deleteAllClipsTitle'),
      text: t('mainVideoPage.messages.deleteAllClipsText', { count: customClips.length }),
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#E11D48',
      cancelButtonColor: '#6B7280',
      confirmButtonText: t('mainVideoPage.messages.yesDeleteAll'),
      cancelButtonText: t('common.cancel')
    }).then((result) => {
      if (result.isConfirmed) {
        setCustomClips([]);
        setSelectedClip(null);

        Swal.fire({
          icon: 'success',
          title: t('mainVideoPage.messages.allClipsDeleted'),
          text: t('mainVideoPage.messages.allClipsDeletedText'),
          confirmButtonColor: '#E11D48',
          timer: 2000
        });
      }
    });
  };

  // Delete individual clip


  const fetchVideoDetails = async (videoId, csrfToken) => {
    try {
      const response = await fetch(route('user.youtube.details'), {
        method: 'POST',
        body: JSON.stringify({ video_id: videoId }),
        headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' }
      });
      const data = await response.json();

      if (data.success && data.duration_seconds) {
        // Update duration from API if available
        setDuration(data.duration_seconds);
        setVideoEndTime(data.duration_formatted || "00:00:00");
      }

      console.log('Video details fetched:', data);
      return data;
    } catch (error) {
      console.error('Error fetching video details:', error);
      return null;
    }
  };


  const handleClipClick = (clip) => {
    console.log('Clip clicked:', clip);
    if (isPlayerReady()) {
      playerSeekTo(clip.start);
      console.log('Seeking to:', clip.start, 'seconds');
      playerPlay();
    } else {
      console.error('Player not available');
    }
  };

  // Preview clip functionality
  const handlePreviewClip = () => {
    if (selectedClip === null || !customClips[selectedClip]) {
      console.error('No clip selected for preview');
      return;
    }

    const clip = customClips[selectedClip];

    if (isPlayerReady()) {
      playerSeekTo(clip.start);
      playerPlay();
      isPreviewingClipRef.current = true;
      clipPreviewEndRef.current = clip.end;
      setIsPreviewingClip(true);
      setClipPreviewStart(clip.start);
      setClipPreviewEnd(clip.end);

      console.log('Previewing clip from', clip.start, 'to', clip.end);
    } else {
      console.error('Player not available');
    }
  };

  const handleStopPreview = () => {
    playerPause();
    isPreviewingClipRef.current = false;
    clipPreviewEndRef.current = null;
    setIsPreviewingClip(false);
    setClipPreviewStart(null);
    setClipPreviewEnd(null);
    console.log('Stopped clip preview');
  };

  const handleDownloadClip = async (clip) => {
    if (!ffmpegReady) {
      Swal.fire({
        icon: 'info',
        title: t('mainVideoPage.messages.pleaseWait'),
        text: t('mainVideoPage.messages.trimmerLoading'),
        confirmButtonColor: '#E11D48'
      });
      return;
    }

    if (!temporaryLink) {
      Swal.fire({
        icon: 'warning',
        title: t('mainVideoPage.messages.noVideoLink'),
        text: t('mainVideoPage.messages.noVideoLinkText'),
        confirmButtonColor: '#E11D48'
      });
      return;
    }

    setTrimmingClipId(clip.id);
    setDownloadProgress(0);
    setDownloadStatus(t('mainVideoPage.messages.preparingDownload'));

    try {
      const ffmpeg = ffmpegRef.current;

      // Clean up any previous files
      try {
        await ffmpeg.deleteFile("input.mp4");
        await ffmpeg.deleteFile("output.mp4");
      } catch (e) {
        // Files might not exist
      }

      setDownloadStatus(t('mainVideoPage.messages.fetchingVideo'));
      setDownloadProgress(20);
      console.log("Fetching video from:", temporaryLink);
      const fileData = await fetchFile(temporaryLink);

      setDownloadStatus(t('mainVideoPage.messages.processingVideo'));
      setDownloadProgress(40);
      await ffmpeg.writeFile("input.mp4", fileData);

      console.log(`Trimming from ${clip.start}s to ${clip.end}s (duration: ${clip.end - clip.start}s)`);

      setDownloadStatus(t('mainVideoPage.messages.trimmingClip'));
      setDownloadProgress(60);

      // Use copy codec for fast trimming without re-encoding
      const command = [
        "-ss", String(clip.start),
        "-i", "input.mp4",
        "-t", String(clip.end - clip.start),
        "-c", "copy",
        "-avoid_negative_ts", "make_zero",
        "-y",
        "output.mp4"
      ];

      console.log("FFmpeg command:", command.join(" "));
      await ffmpeg.exec(command);

      setDownloadStatus(t('mainVideoPage.messages.finalizing'));
      setDownloadProgress(80);
      console.log("Reading output file...");
      const data = await ffmpeg.readFile("output.mp4");

      if (!data || data.length === 0) {
        throw new Error("Trimming produced an empty file");
      }

      setDownloadProgress(90);
      const blob = new Blob([data], { type: "video/mp4" });
      const url = URL.createObjectURL(blob);

      // Download the file
      const a = document.createElement('a');
      a.href = url;
      const startTime = formatTime(clip.start).replace(/:/g, '-');
      const endTime = formatTime(clip.end).replace(/:/g, '-');
      a.download = `Clip [${startTime} to ${endTime}].mp4`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);

      setDownloadProgress(100);
      setDownloadStatus(t('mainVideoPage.messages.complete'));

      Swal.fire({
        icon: 'success',
        title: t('mainVideoPage.messages.successTitle'),
        text: t('mainVideoPage.messages.clipDownloadedSuccess'),
        confirmButtonColor: '#E11D48',
        timer: 2000,
        showConfirmButton: false
      });
    } catch (error) {
      Swal.fire({
        icon: 'error',
        title: t('mainVideoPage.messages.downloadFailed'),
        text: t('mainVideoPage.messages.errorTrimming') + ' ' + error.message,
        confirmButtonColor: '#E11D48'
      });
    } finally {
      setTrimmingClipId(null);
      setDownloadProgress(0);
      setDownloadStatus('');
    }
  };

  const handleDownloadAllClips = async () => {
    if (customClips.length === 0) {
      Swal.fire({
        icon: 'info',
        title: t('mainVideoPage.messages.noClipsTitle'),
        text: t('mainVideoPage.messages.noClipsText'),
        confirmButtonColor: '#E11D48'
      });
      return;
    }

    if (!ffmpegReady) {
      Swal.fire({
        icon: 'info',
        title: t('mainVideoPage.messages.pleaseWait'),
        text: t('mainVideoPage.messages.trimmerLoading'),
        confirmButtonColor: '#E11D48'
      });
      return;
    }

    if (!temporaryLink) {
      Swal.fire({
        icon: 'warning',
        title: t('mainVideoPage.messages.noVideoLink'),
        text: t('mainVideoPage.messages.noVideoLinkText'),
        confirmButtonColor: '#E11D48'
      });
      return;
    }

    setDownloadingAll(true);
    setDownloadProgress(0);
    setDownloadStatus(t('mainVideoPage.messages.startingBatchDownload'));

    try {
      const ffmpeg = ffmpegRef.current;
      const zip = new JSZip();

      for (let i = 0; i < customClips.length; i++) {
        const clip = customClips[i];
        const clipProgress = ((i / customClips.length) * 100).toFixed(0);

        setDownloadProgress(parseInt(clipProgress));
        setDownloadStatus(`Processing clip ${i + 1} of ${customClips.length}...`);

        console.log(`Processing clip ${i + 1} of ${customClips.length}: ${clip.title}`);

        // Fetch file data for each clip to avoid ArrayBuffer detachment
        console.log(`Fetching video file for clip ${i + 1}...`);
        const fileData = await fetchFile(temporaryLink);

        // Clean up previous files
        try {
          await ffmpeg.deleteFile('input.mp4');
          await ffmpeg.deleteFile('output.mp4');
        } catch (e) {
          // Files might not exist
        }

        // Write input file
        await ffmpeg.writeFile('input.mp4', fileData);

        // FFmpeg command
        const command = [
          "-ss", String(clip.start),
          "-i", "input.mp4",
          "-t", String(clip.end - clip.start),
          "-c", "copy",
          "-avoid_negative_ts", "make_zero",
          "-y",
          "output.mp4"
        ];

        console.log(`FFmpeg command for clip ${i + 1}:`, command.join(' '));
        await ffmpeg.exec(command);

        // Read the output file and add to ZIP
        const outputData = await ffmpeg.readFile('output.mp4');
        const startTime = formatTime(clip.start).replace(/:/g, '-');
        const endTime = formatTime(clip.end).replace(/:/g, '-');
        const filename = `Clip ${String(i + 1).padStart(2, '0')} [${startTime} to ${endTime}].mp4`;
        zip.file(filename, outputData);

        console.log(`Clip ${i + 1} added to ZIP`);
      }

      setDownloadStatus(t('mainVideoPage.messages.generatingZip'));
      setDownloadProgress(95);
      console.log('Generating ZIP file...');
      const zipBlob = await zip.generateAsync({ type: 'blob' });

      setDownloadStatus('Downloading...');
      setDownloadProgress(98);

      // Download the ZIP file
      const url = URL.createObjectURL(zipBlob);
      const link = document.createElement('a');
      link.href = url;
      const dateStr = new Date().toISOString().slice(0, 10);
      link.download = `Video Clips (${customClips.length} clips) ${dateStr}.zip`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);

      setDownloadProgress(100);
      setDownloadStatus(t('mainVideoPage.messages.complete'));

      Swal.fire({
        icon: 'success',
        title: t('mainVideoPage.messages.downloadComplete'),
        text: t('mainVideoPage.messages.allClipsDownloadedZip', { count: customClips.length }),
        confirmButtonColor: '#E11D48',
        timer: 3000
      });
    } catch (error) {
      Swal.fire({
        icon: 'error',
        title: t('mainVideoPage.messages.downloadFailed'),
        text: t('mainVideoPage.messages.errorDownloadingClips') + ' ' + error.message,
        confirmButtonColor: '#E11D48'
      });
    } finally {
      setDownloadingAll(false);
      setDownloadProgress(0);
      setDownloadStatus('');
    }
  };

  const handleTrimAndAddClip = () => {
    if (!previewClip) return;

    // Check for overlaps with existing clips
    const hasOverlap = customClips.some(clip =>
      (previewClip.start >= clip.start && previewClip.start < clip.end) ||
      (previewClip.end > clip.start && previewClip.end <= clip.end) ||
      (previewClip.start <= clip.start && previewClip.end >= clip.end)
    );

    if (hasOverlap) {
      Swal.fire({
        icon: 'warning',
        title: t('mainVideoPage.messages.overlapDetected'),
        text: t('mainVideoPage.messages.overlapAddText'),
        confirmButtonColor: '#E11D48'
      });
      setPreviewClip(null);
      setTempClipStart(null);
      setTempClipEnd(null);
      return;
    }

    const newClip = {
      id: Date.now(),
      start: previewClip.start,
      end: previewClip.end,
      thumbnail: getClipThumbnail(),
      title: `Custom Clip ${customClips.length + 1}`,
    };

    setCustomClips([...customClips, newClip]);
    setSelectedClip(customClips.length);
    setPreviewClip(null);
    setTempClipStart(null);
    setTempClipEnd(null);
  };

  // Helper to get clientX from mouse or touch event
  const getClientX = (e) => {
    if (e.touches && e.touches.length > 0) return e.touches[0].clientX;
    if (e.changedTouches && e.changedTouches.length > 0) return e.changedTouches[0].clientX;
    return e.clientX;
  };

  // Handle draggable timeline - select clip by dragging
  const handleTimelineMouseDown = (e, type) => {
    e.preventDefault();

    if (type === 'select') {
      // Start selecting a new clip on timeline
      const rect = timelineRef.current.getBoundingClientRect();
      const clickPos = (getClientX(e) - rect.left) / rect.width;
      const time = clickPos * duration;
      setTempClipStart(Math.max(0, time));
      setTempClipEnd(Math.max(0, time));
      setPreviewClip(null);
      document.addEventListener('mousemove', handleTimelineSelectMove);
      document.addEventListener('mouseup', handleTimelineSelectUp);
      document.addEventListener('touchmove', handleTimelineSelectMove, { passive: false });
      document.addEventListener('touchend', handleTimelineSelectUp);
    } else if (type === 'start') {
      setIsDraggingStart(true);
    } else if (type === 'end') {
      setIsDraggingEnd(true);
    }
  };

  const handleTimelineSelectMove = (e) => {
    if (e.cancelable) e.preventDefault();
    if (tempClipStart !== null && timelineRef.current) {
      const rect = timelineRef.current.getBoundingClientRect();
      const clickPos = (getClientX(e) - rect.left) / rect.width;
      const time = Math.min(duration, Math.max(0, clickPos * duration));
      setTempClipEnd(time);
    }
  };

  const handleTimelineSelectUp = () => {
    document.removeEventListener('mousemove', handleTimelineSelectMove);
    document.removeEventListener('mouseup', handleTimelineSelectUp);
    document.removeEventListener('touchmove', handleTimelineSelectMove);
    document.removeEventListener('touchend', handleTimelineSelectUp);
    if (tempClipStart !== null && tempClipEnd !== null && Math.abs(tempClipEnd - tempClipStart) > 0.5) {
      const newStart = Math.min(tempClipStart, tempClipEnd);
      const newEnd = Math.max(tempClipStart, tempClipEnd);

      // Check for overlaps with existing clips
      const hasOverlap = customClips.some(clip =>
        (newStart >= clip.start && newStart < clip.end) ||
        (newEnd > clip.start && newEnd <= clip.end) ||
        (newStart <= clip.start && newEnd >= clip.end)
      );

      if (hasOverlap) {
        Swal.fire({
          icon: 'warning',
          title: t('mainVideoPage.messages.overlapDetected'),
          text: t('mainVideoPage.messages.overlapCreateText'),
          confirmButtonColor: '#E11D48'
        });
        setTempClipStart(null);
        setTempClipEnd(null);
        return;
      }

      setPreviewClip({
        start: newStart,
        end: newEnd,
      });
    }
  };

  const handleTimelineMouseMove = (e) => {
    if (e.cancelable) e.preventDefault();
    if (!isDraggingStart && !isDraggingEnd) return;
    if (!timelineRef.current || !duration) return;

    const rect = timelineRef.current.getBoundingClientRect();
    const x = Math.max(0, Math.min(getClientX(e) - rect.left, rect.width));
    const percent = x / rect.width;
    const time = percent * duration;

    if (isDraggingStart && selectedClip !== null) {
      const newClips = [...customClips];
      const currentClip = newClips[selectedClip];
      const proposedStart = Math.max(0, Math.min(time, currentClip.end - 1));

      // Check for overlaps with other clips (excluding current clip)
      const hasOverlap = customClips.some((clip, idx) =>
        idx !== selectedClip &&
        (proposedStart < clip.end && currentClip.end > clip.start)
      );

      if (!hasOverlap) {
        newClips[selectedClip].start = proposedStart;
        setCustomClips(newClips);
        setVideoStartTime(formatTime(newClips[selectedClip].start));
      }
    } else if (isDraggingEnd && selectedClip !== null) {
      const newClips = [...customClips];
      const currentClip = newClips[selectedClip];
      const proposedEnd = Math.min(duration, Math.max(time, currentClip.start + 1));

      // Check for overlaps with other clips (excluding current clip)
      const hasOverlap = customClips.some((clip, idx) =>
        idx !== selectedClip &&
        (currentClip.start < clip.end && proposedEnd > clip.start)
      );

      if (!hasOverlap) {
        newClips[selectedClip].end = proposedEnd;
        setCustomClips(newClips);
        setVideoEndTime(formatTime(newClips[selectedClip].end));
      }
    }
  };

  const handleTimelineMouseUp = () => {
    setIsDraggingStart(false);
    setIsDraggingEnd(false);
  };

  useEffect(() => {
    if (isDraggingStart || isDraggingEnd) {
      window.addEventListener('mousemove', handleTimelineMouseMove);
      window.addEventListener('mouseup', handleTimelineMouseUp);
      window.addEventListener('touchmove', handleTimelineMouseMove, { passive: false });
      window.addEventListener('touchend', handleTimelineMouseUp);
      return () => {
        window.removeEventListener('mousemove', handleTimelineMouseMove);
        window.removeEventListener('mouseup', handleTimelineMouseUp);
        window.removeEventListener('touchmove', handleTimelineMouseMove);
        window.removeEventListener('touchend', handleTimelineMouseUp);
      };
    }
  }, [isDraggingStart, isDraggingEnd, selectedClip, customClips, duration]);

  const formatTime = (seconds) => {
    if (isNaN(seconds) || seconds === 0) return '00:00:00';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);
    return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  };

  // Copy transcript or summary to clipboard
  const handleCopyContent = async () => {
    let textToCopy = '';

    if (tabValue === 0) {
      // Copy transcript with speakers and timestamps
      const currentTranscript = showAlternativeTranscript ? alternativeTranscript : transcript;
      if (currentTranscript.length === 0) {
        Swal.fire({
          icon: 'warning',
          title: t('mainVideoPage.messages.noContentTitle'),
          text: t('mainVideoPage.messages.noTranscriptCopy'),
          confirmButtonColor: '#E11D48'
        });
        return;
      }

      textToCopy = currentTranscript.map(item => {
        const speaker = item.actualSpeaker || item.speaker || t('mainVideoPage.messages.unknownSpeaker');
        return `[${item.time}] ${speaker}:\n${item.text}`;
      }).join('\n\n');

    } else if (tabValue === 1) {
      // Copy summary
      const currentSummary = showAlternativeSummary ? alternativeSummary : summary;
      if (!currentSummary) {
        Swal.fire({
          icon: 'warning',
          title: t('mainVideoPage.messages.noContentTitle'),
          text: t('mainVideoPage.messages.noSummaryCopy'),
          confirmButtonColor: '#E11D48'
        });
        return;
      }
      textToCopy = currentSummary;
    }

    try {
      await navigator.clipboard.writeText(textToCopy);
      Swal.fire({
        icon: 'success',
        title: t('mainVideoPage.messages.copiedTitle'),
        text: tabValue === 0 ? t('mainVideoPage.messages.transcriptCopied') : t('mainVideoPage.messages.summaryCopied'),
        confirmButtonColor: '#E11D48',
        timer: 2000,
        showConfirmButton: false
      });
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: t('mainVideoPage.messages.copyFailed'),
        text: t('mainVideoPage.messages.copyFailedText'),
        confirmButtonColor: '#E11D48'
      });
    }
  };

  // Download transcript or summary as PDF
  const handleDownloadPDF = (language = 'current') => {
    // Close dialog if open
    setDownloadDialogOpen(false);

    // Helper function to generate PDF content
    const generatePDF = (transcriptData, summaryData, langLabel, isUrdu = false) => {
      let content = '';
      let title = '';

      if (tabValue === 0) {
        // Download transcript
        if (!transcriptData || transcriptData.length === 0) {
          return null;
        }

        title = `Video Transcript (${langLabel})`;

        // Build HTML content for transcript
        content = transcriptData.map(item => {
          const speaker = item.actualSpeaker || item.speaker || 'Unknown';
          const isNamedSpeaker = speaker && !['A', 'B', 'C', 'D', 'Unknown'].includes(speaker);
          return `
            <div style="margin-bottom: 16px; padding: 12px; background: #f9f9f9; border-radius: 8px; border-left: 4px solid ${isNamedSpeaker ? '#22C55E' : '#3B82F6'};">
              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="color: #666; font-size: 12px;">${item.time}</span>
                <span style="background: ${isNamedSpeaker ? '#22C55E20' : '#3B82F620'}; color: ${isNamedSpeaker ? '#22C55E' : '#3B82F6'}; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;">${speaker}</span>
              </div>
              <p style="margin: 0; line-height: 1.6; color: #333; ${isUrdu ? 'direction: rtl; text-align: right;' : ''}">${item.text}</p>
            </div>
          `;
        }).join('');

      } else if (tabValue === 1) {
        // Download summary
        if (!summaryData) {
          return null;
        }

        title = `Video Summary (${langLabel})`;

        // Build HTML content for summary with proper formatting
        content = summaryData.split('\n').filter(line => line.trim()).map(line => {
          const isBullet = line.trim().startsWith('-') || line.trim().startsWith('•');
          let cleanLine = isBullet ? line.trim().substring(1).trim() : line.trim();

          // Convert **bold** to <strong>
          cleanLine = cleanLine.replace(/\*\*([^*]+)\*\*/g, '<strong style="color: #E11D48;">$1</strong>');

          return `
            <div style="display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px; ${isUrdu ? 'direction: rtl; flex-direction: row-reverse;' : ''}">
              ${isBullet ? '<span style="width: 8px; height: 8px; background: #E11D48; border-radius: 50%; margin-top: 8px; flex-shrink: 0;"></span>' : ''}
              <p style="margin: 0; line-height: 1.7; color: #333; flex: 1; ${isUrdu ? 'text-align: right;' : ''}">${cleanLine}</p>
            </div>
          `;
        }).join('');
      }

      return { content, title, isUrdu };
    };

    // Determine which content to download based on language selection
    let pdfData = null;

    if (language === 'current') {
      // Download currently displayed content
      if (tabValue === 0) {
        const currentTranscript = showAlternativeTranscript ? alternativeTranscript : transcript;
        const langLabel = showAlternativeTranscript
          ? (alternativeLanguage === 'ur' ? 'Urdu' : 'English') + ' Translation'
          : (detectedLanguage === 'ur' ? 'Urdu' : detectedLanguage === 'en' ? 'English' : 'Original');
        const isUrdu = showAlternativeTranscript ? alternativeLanguage === 'ur' : detectedLanguage === 'ur';
        pdfData = generatePDF(currentTranscript, null, langLabel, isUrdu);
      } else {
        const currentSummary = showAlternativeSummary ? alternativeSummary : summary;
        const langLabel = showAlternativeSummary
          ? (alternativeLanguage === 'ur' ? 'Urdu' : 'English') + ' Translation'
          : (detectedLanguage === 'ur' ? 'Urdu' : detectedLanguage === 'en' ? 'English' : 'Original');
        const isUrdu = showAlternativeSummary ? alternativeLanguage === 'ur' : detectedLanguage === 'ur';
        pdfData = generatePDF(null, currentSummary, langLabel, isUrdu);
      }
    } else if (language === 'urdu') {
      // Download Urdu version
      if (tabValue === 0) {
        const urduTranscript = detectedLanguage === 'ur' ? transcript : alternativeTranscript;
        pdfData = generatePDF(urduTranscript, null, 'Urdu', true);
      } else {
        const urduSummary = detectedLanguage === 'ur' ? summary : alternativeSummary;
        pdfData = generatePDF(null, urduSummary, 'Urdu', true);
      }
    } else if (language === 'english') {
      // Download English version
      if (tabValue === 0) {
        const englishTranscript = detectedLanguage === 'en' ? transcript : alternativeTranscript;
        pdfData = generatePDF(englishTranscript, null, 'English', false);
      } else {
        const englishSummary = detectedLanguage === 'en' ? summary : alternativeSummary;
        pdfData = generatePDF(null, englishSummary, 'English', false);
      }
    } else if (language === 'both') {
      // Download both languages in one PDF
      let urduContent = '';
      let englishContent = '';

      if (tabValue === 0) {
        const urduTranscript = detectedLanguage === 'ur' ? transcript : alternativeTranscript;
        const englishTranscript = detectedLanguage === 'en' ? transcript : alternativeTranscript;

        const urduPdf = generatePDF(urduTranscript, null, 'Urdu', true);
        const englishPdf = generatePDF(englishTranscript, null, 'English', false);

        urduContent = urduPdf?.content || '<p style="color: #999;">Urdu transcript not available</p>';
        englishContent = englishPdf?.content || '<p style="color: #999;">English transcript not available</p>';

        pdfData = {
          title: 'Video Transcript (Bilingual)',
          content: `
            <h2 style="color: #E11D48; border-bottom: 2px solid #E11D48; padding-bottom: 10px; margin-bottom: 20px;">🇺🇸 English Version</h2>
            ${englishContent}
            <div style="page-break-before: always;"></div>
            <h2 style="color: #E11D48; border-bottom: 2px solid #E11D48; padding-bottom: 10px; margin-bottom: 20px; direction: rtl; text-align: right;">🇵🇰 اردو ورژن</h2>
            ${urduContent}
          `,
          isUrdu: false
        };
      } else {
        const urduSummary = detectedLanguage === 'ur' ? summary : alternativeSummary;
        const englishSummary = detectedLanguage === 'en' ? summary : alternativeSummary;

        const urduPdf = generatePDF(null, urduSummary, 'Urdu', true);
        const englishPdf = generatePDF(null, englishSummary, 'English', false);

        urduContent = urduPdf?.content || '<p style="color: #999;">Urdu summary not available</p>';
        englishContent = englishPdf?.content || '<p style="color: #999;">English summary not available</p>';

        pdfData = {
          title: 'Video Summary (Bilingual)',
          content: `
            <h2 style="color: #E11D48; border-bottom: 2px solid #E11D48; padding-bottom: 10px; margin-bottom: 20px;">🇺🇸 English Version</h2>
            ${englishContent}
            <div style="page-break-before: always;"></div>
            <h2 style="color: #E11D48; border-bottom: 2px solid #E11D48; padding-bottom: 10px; margin-bottom: 20px; direction: rtl; text-align: right;">🇵🇰 اردو ورژن</h2>
            ${urduContent}
          `,
          isUrdu: false
        };
      }
    }

    if (!pdfData) {
      Swal.fire({
        icon: 'warning',
        title: 'No Content',
        text: tabValue === 0 ? 'No transcript available to download' : 'No summary available to download',
        confirmButtonColor: '#E11D48'
      });
      return;
    }

    // Create printable HTML document
    const printWindow = window.open('', '_blank');
    const htmlContent = `
      <!DOCTYPE html>
      <html>
      <head>
        <meta charset="UTF-8">
        <title>${pdfData.title}</title>
        <style>
          @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
          @import url('https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;500;600;700&display=swap');
          body {
            font-family: 'Inter', 'Noori Nastaliq', 'Jameel Noori Nastaliq', 'Alvi Nastaliq', 'Noto Nastaliq Urdu', 'Urdu Typesetting', 'Arabic Typesetting', sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 40px 20px;
            background: white;
            color: #333;
          }
          .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #E11D48;
          }
          .header h1 {
            color: #E11D48;
            margin: 0 0 10px 0;
            font-size: 24px;
          }
          .header p {
            color: #666;
            margin: 0;
            font-size: 14px;
          }
          .content {
            margin-top: 20px;
          }
          @media print {
            body { padding: 20px; }
            .no-print { display: none; }
          }
        </style>
      </head>
      <body ${pdfData.isUrdu ? 'dir="rtl"' : ''}>
        <div class="header">
          <h1>${pdfData.title}</h1>
          <p>Generated on ${new Date().toLocaleDateString()} at ${new Date().toLocaleTimeString()}</p>
        </div>
        <div class="content">
          ${pdfData.content}
        </div>
        <script>
          window.onload = function() {
            window.print();
          };
        </script>
      </body>
      </html>
    `;

    printWindow.document.write(htmlContent);
    printWindow.document.close();
  };

  return (
    <UserAuthenticateLayout>
      {/* Loading Overlay */}
      {isLoadingVideo && (
        <Box sx={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          bgcolor: 'rgba(0, 0, 0, 0.8)',
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
          zIndex: 9999,
          backdropFilter: 'blur(4px)'
        }}>
          <CircularProgress size={60} sx={{ color: '#E11D48', mb: 3 }} />
          <Typography sx={{ color: 'white', fontSize: '1.2rem', fontWeight: 500, mb: 1 }}>
            {t('mainVideoPage.messages.loadingVideo')}
          </Typography>
          <Typography sx={{ color: '#9CA3AF', fontSize: '0.9rem' }}>
            {t('mainVideoPage.messages.loadingVideoWait')}
          </Typography>
        </Box>
      )}

      <Box sx={{ bgcolor: '#2A2A2A', minHeight: '100vh', p: { xs: 1.5, sm: 2, md: 3 } }}>
        {/* YouTube URL Input */}
        <Box sx={{ mb: { xs: 2, sm: 2.5, md: 3 } }}>
          <TextField
            fullWidth
            label={t('mainVideoPage.videoInput.label')}
            value={youtubeUrl}
            InputProps={{
              readOnly: true,
              endAdornment: youtubeUrl && (
                <IconButton
                  size="small"
                  onClick={() => {
                    navigator.clipboard.writeText(youtubeUrl);
                    Swal.fire({
                      icon: 'success',
                      title: t('mainVideoPage.messages.copiedTitle'),
                      text: t('mainVideoPage.messages.urlCopied'),
                      timer: 1500,
                      showConfirmButton: false,
                      background: '#1A1A1A',
                      color: 'white',
                    });
                  }}
                  sx={{
                    color: '#9CA3AF',
                    '&:hover': { color: '#E11D48', bgcolor: '#E11D4810' }
                  }}
                >
                  <ContentCopyIcon fontSize="small" />
                </IconButton>
              )
            }}
            sx={{
              bgcolor: '#1A1A1A',
              borderRadius: 1,
              '& .MuiInputLabel-root': { color: '#9CA3AF' },
              '& .MuiInputBase-input': { color: 'white', cursor: 'default' },
              '& .MuiOutlinedInput-root': {
                '& fieldset': { borderColor: '#4A4A4A' },
                '&:hover fieldset': { borderColor: '#6A6A6A' },
                '&.Mui-focused fieldset': { borderColor: '#4A4A4A' },
                'label': { color: '#BE123C' }
              },
            }}
          />
        </Box>

        {videoTitle && (
          <Typography sx={{ color: 'white', fontWeight: 500, fontSize: { xs: '0.85rem', sm: '0.9rem', md: '0.95rem' }, mb: { xs: 2, sm: 2.5, md: 3 } }}>
            {videoTitle}
          </Typography>
        )}

        <Box sx={{ display: 'flex', flexDirection: { xs: 'column', lg: 'row' }, gap: { xs: 1.5, sm: 2 } }}>
          {/* Left Column - Chapters List (Image 2) */}
          <Box sx={{
            flex: { xs: 'none', lg: '0 0 200px' },
            width: { xs: '100%', lg: 'auto' },
            bgcolor: '#1A1A1A',
            borderRadius: 1.5,
            display: 'flex',
            flexDirection: 'column',
            p: { xs: 1.5, sm: 1.5 },
            height: { xs: 'auto', lg: '80vh' },
            maxHeight: { xs: '50vh', lg: '80vh' },
            minHeight: 0, // allow child overflow scrolling
            overflow: 'hidden', // contain children scrolling
          }}>
            {/* Header with count and delete all button */}
            <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 1 }}>
              <Typography sx={{ color: '#9CA3AF', fontSize: '0.85rem', fontWeight: 600 }}>
                {t('mainVideoPage.tabs.customClips')}
              </Typography>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
                {customClips.length > 0 && (
                  <>
                    <Box sx={{
                      bgcolor: '#E11D48',
                      color: 'white',
                      fontSize: '0.7rem',
                      fontWeight: 700,
                      px: 0.75,
                      py: 0.25,
                      borderRadius: 1,
                      minWidth: '20px',
                      textAlign: 'center'
                    }}>
                      {customClips.length}
                    </Box>
                    <IconButton
                      size="small"
                      onClick={handleDeleteAllClips}
                      sx={{
                        color: '#EF4444',
                        padding: '4px',
                        '&:hover': {
                          bgcolor: '#EF444420',
                          color: '#DC2626'
                        }
                      }}
                      title={t('mainVideoPage.messages.deleteAllClips')}
                    >
                      <ClearAllIcon sx={{ fontSize: '1rem' }} />
                    </IconButton>
                  </>
                )}
              </Box>
            </Box>

            {/* Scrollable list, button stays fixed at bottom */}
            <Box sx={{
              flex: 1,
              minHeight: 0,
              display: 'flex',
              flexDirection: 'column',
              gap: 1.5,
              overflowY: 'auto',
              pr: 0.5,
              pb: 1,
              scrollbarWidth: 'thin',
              '&::-webkit-scrollbar': {
                width: '6px',
              },
              '&::-webkit-scrollbar-thumb': {
                backgroundColor: '#4A4A4A',
                borderRadius: '3px',
              },
            }}>
              {customClips.length === 0 ? (
                <Box sx={{ textAlign: 'center', py: 2, color: '#9CA3AF', fontSize: '0.85rem' }}>
                  {t('mainVideoPage.customClips.noClips')}
                </Box>
              ) : (
                customClips.map((clip, idx) => (
                  <Box
                    key={clip.id}
                    sx={{
                      cursor: 'pointer',
                      borderRadius: 1,
                      overflow: 'auto',
                      border: selectedClip === idx ? '2px solid #FFD700' : '2px solid transparent',
                      bgcolor: '#2A2A2A',
                      minHeight: { xs: '180px', sm: '170px', md: '190px' },
                      transition: 'all 0.2s',
                      '&:hover': {
                        borderColor: '#FFD700',
                      }
                    }}
                    onClick={() => {
                      console.log('Custom clip clicked, index:', idx, 'clip:', clip);
                      setSelectedClip(idx);
                      handleClipClick(clip);
                    }}
                  >
                    {/* Chapter Thumbnail */}
                    <Box
                      sx={{
                        width: '100%',
                        height: { xs: 80, sm: 90, md: 100 },
                        backgroundImage: `url(${clip.thumbnail})`,
                        backgroundSize: 'cover',
                        backgroundPosition: 'center',
                        position: 'relative',
                      }}
                    >
                      {/* Delete Icon */}
                      <IconButton
                        size="small"
                        onClick={(e) => handleDeleteClip(e, idx)}
                        sx={{
                          position: 'absolute',
                          top: 4,
                          right: 4,
                          bgcolor: 'rgba(239, 68, 68, 0.9)',
                          color: 'white',
                          padding: '4px',
                          '&:hover': {
                            bgcolor: 'rgba(220, 38, 38, 1)',
                          },
                          zIndex: 2
                        }}
                      >
                        <DeleteIcon sx={{ fontSize: '0.9rem' }} />
                      </IconButton>

                      {/* Time Badge */}
                      <Box
                        sx={{
                          position: 'absolute',
                          bottom: 4,
                          right: 4,
                          bgcolor: 'rgba(0,0,0,0.8)',
                          color: 'white',
                          fontSize: '0.65rem',
                          px: 0.75,
                          py: 0.25,
                          borderRadius: 0.5,
                          fontWeight: 500,
                        }}
                      >
                        {formatTime(clip.start)}
                      </Box>
                    </Box>

                    {/* Chapter Title and Download */}
                    <Box sx={{ p: 1, bgcolor: selectedClip === idx ? '#252525' : '#1A1A1A' }}>
                      <Typography
                        sx={{
                          color: 'white',
                          fontWeight: 500,
                          fontSize: '0.75rem',
                          mb: 0.5,
                          overflow: 'hidden',
                          textOverflow: 'ellipsis',
                          whiteSpace: 'nowrap',
                        }}
                      >
                        {clip.title}
                      </Typography>
                      <Typography
                        sx={{
                          color: '#9CA3AF',
                          fontSize: '0.7rem',
                          mt: 0.5,
                          mb: 0.75,
                        }}
                      >
                        {formatTime(clip.start)} - {formatTime(clip.end)}
                      </Typography>
                      {/* Download Single Clip Button */}
                      <Button
                        size="small"
                        variant="contained"
                        disabled={trimmingClipId !== null || !ffmpegReady}
                        startIcon={trimmingClipId === clip.id ? <CircularProgress size={12} color="inherit" /> : undefined}
                        sx={{
                          width: '100%',
                          bgcolor: '#E11D48',
                          color: 'white',
                          fontWeight: 500,
                          fontSize: { xs: '0.6rem', sm: '0.65rem' },
                          py: { xs: 0.5, sm: 0.4 },
                          textTransform: 'none',
                          '&:hover': {
                            bgcolor: '#BE123C',
                          },
                          '&:disabled': {
                            bgcolor: '#666',
                            color: '#999',
                          }
                        }}
                        onClick={() => handleDownloadClip(clip)}
                      >
                        {trimmingClipId === clip.id
                          ? (downloadProgress > 0 ? `${downloadProgress}%` : t('common.loading'))
                          : t('mainVideoPage.clipActions.download')}
                      </Button>
                    </Box>
                  </Box>
                ))
              )}
            </Box>

            {/* Download Progress Indicator */}
            {(downloadingAll || trimmingClipId !== null) && downloadProgress > 0 && (
              <Box sx={{ mt: 1, px: 1 }}>
                <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 0.5 }}>
                  <Typography sx={{ color: '#9CA3AF', fontSize: '0.7rem' }}>
                    {downloadStatus}
                  </Typography>
                  <Typography sx={{ color: '#E11D48', fontSize: '0.7rem', fontWeight: 600 }}>
                    {downloadProgress}%
                  </Typography>
                </Box>
                <Box sx={{
                  width: '100%',
                  height: 6,
                  bgcolor: '#2A2A2A',
                  borderRadius: 1,
                  overflow: 'hidden'
                }}>
                  <Box sx={{
                    width: `${downloadProgress}%`,
                    height: '100%',
                    bgcolor: '#b6e11d',
                    transition: 'width 0.3s ease',
                    borderRadius: 1
                  }} />
                </Box>
              </Box>
            )}

            {/* Download All Clips Button - Pinned at bottom */}
            {customClips.length > 0 && (
              <Button
                variant="contained"
                fullWidth
                disabled={downloadingAll || !ffmpegReady}
                startIcon={downloadingAll ? <CircularProgress size={14} color="inherit" /> : undefined}
                sx={{
                  bgcolor: '#10B981',
                  color: 'white',
                  fontWeight: 600,
                  fontSize: { xs: '0.75rem', sm: '0.8rem' },
                  py: { xs: 1.2, sm: 1 },
                  mt: { xs: 1.5, sm: 1 },
                  textTransform: 'none',
                  borderRadius: 1,
                  '&:hover': {
                    bgcolor: '#059669',
                  },
                  '&:disabled': {
                    bgcolor: '#666',
                    color: '#999',
                  }
                }}
                onClick={handleDownloadAllClips}
              >
                {downloadingAll
                  ? `${downloadStatus || t('mainVideoPage.messages.processing')} (${downloadProgress}%)`
                  : `${t('mainVideoPage.clipActions.downloadAll')}`}
              </Button>
            )}
          </Box>

          {/* Middle Column - Video Player + Timeline */}
          <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column', gap: { xs: 1.5, sm: 2 }, order: { xs: -1, lg: 0 } }}>
            {/* Main Video Player */}
            <Box sx={{
              bgcolor: '#000',
              borderRadius: 1,
              position: 'relative',
              overflow: 'hidden',
              aspectRatio: '16/9',
            }}>
              {useDropboxPlayer && dropboxStreamUrl ? (
                /* Dropbox Streaming Player (Native HTML5 Video) */
                <video
                  ref={reactPlayerRef}
                  src={dropboxStreamUrl}
                  controls
                  playsInline
                  preload="auto"
                  style={{ position: 'absolute', top: 0, left: 0, width: '100%', height: '100%', objectFit: 'contain' }}
                  onTimeUpdate={(e) => {
                    const current = e.target.currentTime;
                    setCurrentTime(current);

                    // Auto-highlight current transcript
                    if (transcript.length > 0) {
                      const activeIndex = transcript.findIndex(
                        (item) => current >= item.start && current < item.end
                      );
                      if (activeIndex !== -1 && activeIndex !== selectedTranscript) {
                        setSelectedTranscript(activeIndex);
                      }
                    }

                    // Check if we're previewing a clip and reached its end
                    if (isPreviewingClip && clipPreviewEnd && current >= clipPreviewEnd) {
                      handleStopPreview();
                    }
                  }}
                  onLoadedMetadata={(e) => {
                    const dur = e.target.duration;
                    if (dur && dur > 0 && isFinite(dur)) {
                      console.log('Dropbox video duration:', dur);
                      setDuration(dur);
                      setVideoEndTime(formatTime(dur));
                    }
                  }}
                  onPlay={() => setIsPlaying(true)}
                  onPause={() => setIsPlaying(false)}
                  onEnded={() => setIsPlaying(false)}
                  onError={(e) => {
                    console.error('Dropbox video error:', e.target.error);
                    // If Dropbox link expired, try to refresh it
                    if (storedDropboxPath) {
                      console.log('Attempting to refresh Dropbox link...');
                      getVideoTemporaryLink(storedDropboxPath).then(newLink => {
                        if (newLink) {
                          setDropboxStreamUrl(newLink);
                        }
                      });
                    }
                  }}
                />
              ) : (
                /* YouTube IFrame API Player */
                <Box
                  id="youtube-player"
                  sx={{
                    width: '100%',
                    height: '100%',
                    '& iframe': {
                      width: '100%',
                      height: '100%',
                      border: 'none'
                    }
                  }}
                />
              )}

              {/* Dropbox streaming indicator */}
              {useDropboxPlayer && (
                <Box sx={{
                  position: 'absolute',
                  top: 8,
                  left: 8,
                  bgcolor: 'rgba(16, 185, 129, 0.9)',
                  color: 'white',
                  px: 1.5,
                  py: 0.5,
                  borderRadius: 1,
                  fontSize: '0.7rem',
                  fontWeight: 600,
                  zIndex: 10,
                  display: 'flex',
                  alignItems: 'center',
                  gap: 0.5,
                }}>
                  {t('mainVideoPage.messages.cloudStream')}
                </Box>
              )}
            </Box>

            {/* Timeline Section (Image 3) - Below Video with Golden Markers */}
            <Box sx={{ bgcolor: '#1A1A1A', borderRadius: 1.5, p: 2 }}>
              {/* Video Duration Display */}
              <Box sx={{ mb: 1.5, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <Typography sx={{ color: '#9CA3AF', fontSize: '0.85rem', fontWeight: 500 }}>
                  {t('mainVideoPage.customClips.dragToCreate')}
                </Typography>
                <Typography sx={{ color: '#FFD700', fontSize: '0.9rem', fontWeight: 600 }}>
                  {t('mainVideoPage.messages.totalDuration')} {formatTime(duration)}
                </Typography>
              </Box>

              {/* Timeline with chapter markers */}
              <Box sx={{ position: 'relative', mb: 1.5 }}>
                {/* Timeline bar */}
                <Box
                  ref={timelineRef}
                  onMouseDown={(e) => handleTimelineMouseDown(e, 'select')}
                  onTouchStart={(e) => handleTimelineMouseDown(e, 'select')}
                  sx={{
                    position: 'relative',
                    height: { xs: 40, sm: 50, md: 60 },
                    bgcolor: '#2A2A2A',
                    borderRadius: 0.5,
                    overflow: 'hidden',
                    cursor: 'crosshair',
                    touchAction: 'none',
                  }}
                >
                  {/* Progress bar */}
                  <Box
                    sx={{
                      position: 'absolute',
                      left: 0,
                      top: 0,
                      height: '100%',
                      width: `${duration > 0 ? (currentTime / duration) * 100 : 0}%`,
                      bgcolor: 'rgba(225, 29, 72, 0.3)',
                      transition: 'width 0.1s',
                    }}
                  />

                  {/* Preview clip being selected - Light blue */}
                  {tempClipStart !== null && tempClipEnd !== null && (
                    <Box
                      sx={{
                        position: 'absolute',
                        left: `${duration > 0 ? (Math.min(tempClipStart, tempClipEnd) / duration) * 100 : 0}%`,
                        width: `${duration > 0 ? (Math.abs(tempClipEnd - tempClipStart) / duration) * 100 : 0}%`,
                        height: '100%',
                        bgcolor: 'rgba(59, 130, 246, 0.4)',
                        borderRadius: 0.5,
                        pointerEvents: 'none',
                        zIndex: 2,
                      }}
                    />
                  )}

                  {/* Chapter markers - Golden boxes with draggable handles */}
                  {customClips.map((clip, idx) => (
                    <Box
                      key={clip.id}
                      sx={{
                        position: 'absolute',
                        left: `${duration > 0 ? (clip.start / duration) * 100 : 0}%`,
                        width: `${duration > 0 ? ((clip.end - clip.start) / duration) * 100 : 0}%`,
                        height: '100%',
                        border: '2px solid #FFD700',
                        bgcolor: selectedClip === idx ? 'rgba(255, 215, 0, 0.2)' : 'transparent',
                        borderRadius: 0.5,
                        cursor: 'pointer',
                        transition: selectedClip === idx ? 'none' : 'all 0.2s',
                        '&:hover': {
                          bgcolor: 'rgba(255, 215, 0, 0.3)',
                        },
                      }}
                      onClick={(e) => {
                        if (e.target !== e.currentTarget) return;
                        setSelectedClip(idx);
                        setVideoStartTime(formatTime(clip.start));
                        setVideoEndTime(formatTime(clip.end));
                        handleClipClick(clip);
                      }}
                    >
                      {/* Draggable Start Handle */}
                      {selectedClip === idx && (
                        <>
                          <Box
                            onMouseDown={(e) => handleTimelineMouseDown(e, 'start')}
                            onTouchStart={(e) => handleTimelineMouseDown(e, 'start')}
                            sx={{
                              position: 'absolute',
                              left: 0,
                              top: '50%',
                              transform: 'translateY(-50%)',
                              width: 8,
                              height: 30,
                              bgcolor: '#E11D48',
                              borderRadius: '4px 0 0 4px',
                              cursor: 'ew-resize',
                              zIndex: 10,
                              touchAction: 'none',
                              '&:hover': {
                                bgcolor: '#BE123C',
                                width: 10,
                              },
                            }}
                          />
                          {/* Draggable End Handle */}
                          <Box
                            onMouseDown={(e) => handleTimelineMouseDown(e, 'end')}
                            onTouchStart={(e) => handleTimelineMouseDown(e, 'end')}
                            sx={{
                              position: 'absolute',
                              right: 0,
                              top: '50%',
                              transform: 'translateY(-50%)',
                              width: 8,
                              height: 30,
                              bgcolor: '#E11D48',
                              borderRadius: '0 4px 4px 0',
                              touchAction: 'none',
                              cursor: 'ew-resize',
                              zIndex: 10,
                              '&:hover': {
                                bgcolor: '#BE123C',
                                width: 10,
                              },
                            }}
                          />
                        </>
                      )}
                    </Box>
                  ))}

                  {/* Current time marker - Red line */}
                  <Box
                    sx={{
                      position: 'absolute',
                      left: `${duration > 0 ? (currentTime / duration) * 100 : 0}%`,
                      top: 0,
                      height: '100%',
                      width: '2px',
                      bgcolor: '#E11D48',
                      zIndex: 3,
                    }}
                  />
                </Box>

                {/* Time display */}
                <Box sx={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  mt: 1,
                  color: '#9CA3AF',
                  fontSize: '0.75rem'
                }}>
                  <span>{formatTime(currentTime)}</span>
                  <span>{formatTime(duration)}</span>
                </Box>
              </Box>

              {/* Preview Clip Section - Show when dragging on timeline */}
              {previewClip && (
                <Box sx={{
                  bgcolor: '#252525',
                  border: '2px solid #3B82F6',
                  borderRadius: 1.5,
                  p: { xs: 1.5, sm: 2 },
                  mb: { xs: 1.5, sm: 2 },
                }}>
                  <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 1.5 }}>
                    <Typography sx={{ color: 'white', fontWeight: 600, fontSize: '0.95rem' }}>
                      {t('mainVideoPage.messages.previewClipTitle')}
                    </Typography>
                    <Typography sx={{ color: '#3B82F6', fontSize: '0.85rem', fontWeight: 500 }}>
                      {formatTime(previewClip.start)} - {formatTime(previewClip.end)}
                      ({formatTime(previewClip.end - previewClip.start)})
                    </Typography>
                  </Box>

                  {/* Trim Controls for Preview */}
                  <Box sx={{ display: 'flex', gap: 1.5, alignItems: 'center', mb: 2 }}>
                    <TextField
                      size="small"
                      label={t('mainVideoPage.messages.startSeconds')}
                      type="number"
                      value={parseFloat(previewClip.start.toFixed(2))}
                      onChange={(e) => {
                        const val = Math.max(0, parseFloat(e.target.value) || 0);
                        setPreviewClip({ ...previewClip, start: val });
                      }}
                      sx={{
                        flex: 1,
                        '& .MuiInputLabel-root': { color: '#9CA3AF', fontSize: '0.75rem' },
                        '& .MuiInputBase-input': { color: 'white', fontSize: '0.8rem' },
                        '& .MuiOutlinedInput-root': {
                          '& fieldset': { borderColor: '#4A4A4A' },
                          '&:hover fieldset': { borderColor: '#6A6A6A' },
                          '&.Mui-focused fieldset': { borderColor: '#3B82F6' },
                        },
                      }}
                    />
                    <Typography sx={{ color: '#9CA3AF', fontSize: '0.8rem' }}>{t('mainVideoPage.messages.to')}</Typography>
                    <TextField
                      size="small"
                      label={t('mainVideoPage.messages.endSeconds')}
                      type="number"
                      value={parseFloat(previewClip.end.toFixed(2))}
                      onChange={(e) => {
                        const val = Math.min(duration, parseFloat(e.target.value) || 0);
                        setPreviewClip({ ...previewClip, end: val });
                      }}
                      sx={{
                        flex: 1,
                        '& .MuiInputLabel-root': { color: '#9CA3AF', fontSize: '0.75rem' },
                        '& .MuiInputBase-input': { color: 'white', fontSize: '0.8rem' },
                        '& .MuiOutlinedInput-root': {
                          '& fieldset': { borderColor: '#4A4A4A' },
                          '&:hover fieldset': { borderColor: '#6A6A6A' },
                          '&.Mui-focused fieldset': { borderColor: '#3B82F6' },
                        },
                      }}
                    />
                  </Box>

                  {/* Trim Button */}
                  <Button
                    variant="contained"
                    fullWidth
                    sx={{
                      bgcolor: '#3B82F6',
                      color: 'white',
                      fontWeight: 600,
                      fontSize: '0.9rem',
                      textTransform: 'none',
                      py: 1.2,
                      borderRadius: 1,
                      '&:hover': {
                        bgcolor: '#2563EB',
                      },
                    }}
                    onClick={handleTrimAndAddClip}
                  >
                    {t('mainVideoPage.messages.trimAddToClips')}
                  </Button>
                </Box>
              )}


              {/* Selected Chapter Info & Trim Controls */}
              {selectedClip !== null && customClips[selectedClip] && (
                <Box>
                  <Box sx={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 2,
                    mb: 2,
                    p: 1.5,
                    bgcolor: '#252525',
                    borderRadius: 1,
                  }}>
                    <Box
                      sx={{
                        width: 80,
                        height: 50,
                        borderRadius: 0.5,
                        backgroundImage: `url(${customClips[selectedClip]?.thumbnail})`,
                        backgroundSize: 'cover',
                        backgroundPosition: 'center',
                      }}
                    />
                    <Box sx={{ flex: 1 }}>
                      <Typography sx={{ color: 'white', fontWeight: 500, fontSize: '0.85rem', mb: 0.5 }}>
                        {customClips[selectedClip]?.title}
                      </Typography>
                      <Typography sx={{ color: '#9CA3AF', fontSize: '0.75rem' }}>
                        {formatTime(customClips[selectedClip]?.start)} - {formatTime(customClips[selectedClip]?.end)}
                      </Typography>
                    </Box>
                  </Box>

                  {/* Trim Controls */}
                  <Box sx={{ mb: 2 }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: '0.75rem', mb: 1 }}>
                      {t('mainVideoPage.messages.adjustClipDuration')}
                    </Typography>
                    <Box sx={{ display: 'flex', gap: 1.5, alignItems: 'center' }}>
                      <TextField
                        size="small"
                        label={t('mainVideoPage.messages.start')}
                        type="number"
                        value={customClips[selectedClip]?.start || 0}
                        onChange={(e) => {
                          const newClips = [...customClips];
                          newClips[selectedClip].start = Math.max(0, parseFloat(e.target.value) || 0);
                          setCustomClips(newClips);
                        }}
                        sx={{
                          flex: 1,
                          '& .MuiInputLabel-root': { color: '#9CA3AF', fontSize: '0.75rem' },
                          '& .MuiInputBase-input': { color: 'white', fontSize: '0.8rem' },
                          '& .MuiOutlinedInput-root': {
                            '& fieldset': { borderColor: '#4A4A4A' },
                            '&:hover fieldset': { borderColor: '#6A6A6A' },
                            '&.Mui-focused fieldset': { borderColor: '#E11D48' },
                          },
                        }}
                      />
                      <Typography sx={{ color: '#9CA3AF', fontSize: '0.8rem' }}>{t('mainVideoPage.messages.to')}</Typography>
                      <TextField
                        size="small"
                        label={t('mainVideoPage.messages.end')}
                        type="number"
                        value={customClips[selectedClip]?.end || 0}
                        onChange={(e) => {
                          const newClips = [...customClips];
                          newClips[selectedClip].end = Math.min(duration, parseFloat(e.target.value) || 0);
                          setCustomClips(newClips);
                        }}
                        sx={{
                          flex: 1,
                          '& .MuiInputLabel-root': { color: '#9CA3AF', fontSize: '0.75rem' },
                          '& .MuiInputBase-input': { color: 'white', fontSize: '0.8rem' },
                          '& .MuiOutlinedInput-root': {
                            '& fieldset': { borderColor: '#4A4A4A' },
                            '&:hover fieldset': { borderColor: '#6A6A6A' },
                            '&.Mui-focused fieldset': { borderColor: '#E11D48' },
                          },
                        }}
                      />
                    </Box>
                  </Box>
                </Box>
              )}

              {/* Preview and Download Buttons */}
              <Box sx={{ display: 'flex', gap: 2, mb: 2 }}>
                {!isPreviewingClip ? (
                  <Button
                    variant="outlined"
                    disabled={selectedClip === null}
                    startIcon={<PlayArrowIcon />}
                    sx={{
                      color: '#FFD700',
                      borderColor: '#FFD700',
                      bgcolor: 'transparent',
                      fontWeight: 500,
                      fontSize: { xs: '0.8rem', sm: '0.85rem' },
                      borderRadius: 1,
                      flex: 1,
                      textTransform: 'none',
                      py: { xs: 1.2, sm: 1 },
                      '&:hover': {
                        borderColor: '#FFC700',
                        bgcolor: 'rgba(255, 215, 0, 0.1)',
                      },
                      '&:disabled': {
                        color: '#666',
                        borderColor: '#333',
                      }
                    }}
                    onClick={handlePreviewClip}
                  >
                    {t('mainVideoPage.tabs.previewClip')}
                  </Button>
                ) : (
                  <Button
                    variant="outlined"
                    startIcon={<PauseIcon />}
                    sx={{
                      color: '#EF4444',
                      borderColor: '#EF4444',
                      bgcolor: 'transparent',
                      fontWeight: 500,
                      fontSize: '0.85rem',
                      borderRadius: 1,
                      flex: 1,
                      textTransform: 'none',
                      py: 1,
                      '&:hover': {
                        borderColor: '#DC2626',
                        bgcolor: 'rgba(239, 68, 68, 0.1)',
                      },
                    }}
                    onClick={handleStopPreview}
                  >
                    <div className='px-2'>{t('mainVideoPage.tabs.stopPreview')}</div>
                  </Button>
                )}
                <Button
                  variant="contained"
                  disabled={selectedClip === null}
                  startIcon={<ScissorsIcon />}
                  sx={{
                    bgcolor: '#8B5CF6',
                    color: 'white',
                    fontWeight: 500,
                    fontSize: '0.85rem',
                    borderRadius: 1,
                    flex: 1,
                    textTransform: 'none',
                    py: 1,
                    '&:hover': {
                      bgcolor: '#7C3AED',
                    },
                    '&:disabled': {
                      bgcolor: '#666',
                      color: '#999',
                    }
                  }}
                  onClick={handleCreateClip}
                >
                  <div className='px-2'> {t('mainVideoPage.tabs.createClip')}</div>
                </Button>
              </Box>

              <Box sx={{ display: 'flex', flexDirection: { xs: 'column', sm: 'row' }, gap: { xs: 1.5, sm: 2 } }}>
                <Button
                  variant="outlined"
                  disabled={!videoId}
                  sx={{
                    color: 'white',
                    borderColor: '#4A4A4A',
                    bgcolor: '#1A1A1A',
                    fontWeight: 400,
                    fontSize: '0.85rem',
                    borderRadius: 1,
                    flex: 1,
                    textTransform: 'none',
                    py: 1,
                    '&:hover': {
                      borderColor: '#6A6A6A',
                      bgcolor: '#252525',
                    },
                    '&:disabled': {
                      color: '#666',
                      borderColor: '#333',
                    }
                  }}
                  onClick={handleCreateClip}
                >

                  <div className='px-2'>{t('mainVideoPage.tabs.createNewClip')}</div>
                </Button>
                <Button
                  variant="contained"
                  disabled={selectedClip === null || trimmingClipId !== null || !ffmpegReady}
                  startIcon={trimmingClipId !== null && selectedClip !== null && trimmingClipId === customClips[selectedClip]?.id ? <CircularProgress size={16} color="inherit" /> : <DownloadIcon />}
                  sx={{
                    bgcolor: '#E11D48',
                    color: 'white',
                    fontWeight: 500,
                    fontSize: '0.85rem',
                    borderRadius: 1,
                    flex: 1,
                    textTransform: 'none',
                    py: 1,
                    '&:hover': {
                      bgcolor: '#BE123C',
                    },
                    '&:disabled': {
                      bgcolor: '#666',
                      color: '#999',
                    }
                  }}
                  onClick={() => selectedClip !== null && handleDownloadClip(customClips[selectedClip])}
                >
                  {trimmingClipId !== null && selectedClip !== null && trimmingClipId === customClips[selectedClip]?.id
                    ? (downloadProgress > 0 ? `${t('mainVideoPage.messages.downloading')} ${downloadProgress}%` : t('mainVideoPage.messages.trimming'))
                    : !ffmpegReady
                      ? t('mainVideoPage.messages.loadingText')
                      : <div className="px-2">{t('mainVideoPage.tabs.downloadClip')}</div>
                  }
                </Button>
              </Box>
            </Box>
          </Box>

          {/* Right Column: Transcript + Clips Panel */}
          <Box sx={{
            flex: '0 0 33%',
            bgcolor: '#1A1A1A',
            borderRadius: 1.5,
            display: 'flex',
            flexDirection: 'column',
            maxHeight: '80vh',
          }}>
            {/* Transcript Tabs Header */}
            <Box sx={{
              display: 'flex',
              flexDirection: { xs: 'column', sm: 'row' },
              alignItems: { xs: 'stretch', sm: 'center' },
              justifyContent: 'space-between',
              borderBottom: '1px solid #2A2A2A',
              px: { xs: 1.5, sm: 2 },
              pt: { xs: 1, sm: 1.5 },
              gap: { xs: 1, sm: 0 },
            }}>
              <Tabs
                value={tabValue}
                onChange={(e, v) => setTabValue(v)}
                TabIndicatorProps={{
                  style: {
                    backgroundColor: '#E11D48',
                    height: '3px'
                  }
                }}
                sx={{
                  minHeight: 'auto',
                  '& .MuiTab-root': {
                    color: '#9CA3AF',
                    fontWeight: 400,
                    fontSize: { xs: '0.8rem', sm: '0.85rem', md: '0.9rem' },
                    textTransform: 'none',
                    minHeight: 'auto',
                    py: 1,
                    px: 2,
                    '&:hover': {
                      color: '#E5E7EB',
                    }
                  },
                  '& .Mui-selected': {
                    color: '#E11D48 !important',
                    fontWeight: 600,
                  }
                }}
              >
                <Tab label={t('mainVideoPage.tabs.transcript')} />
                <Tab label={t('mainVideoPage.messages.summary')} />
              </Tabs>
              <Box sx={{ display: 'flex', gap: 0.5 }}>
                <IconButton
                  size="small"
                  sx={{
                    color: '#9CA3AF',
                    '&:hover': { color: '#E11D48', bgcolor: '#E11D4810' }
                  }}
                  onClick={handleCopyContent}
                  title={tabValue === 0 ? t('mainVideoPage.messages.copyTranscript') : t('mainVideoPage.messages.copySummary')}
                >
                  <ContentCopyIcon fontSize="small" />
                </IconButton>
                <IconButton
                  size="small"
                  sx={{
                    color: '#9CA3AF',
                    '&:hover': { color: '#E11D48', bgcolor: '#E11D4810' }
                  }}
                  onClick={() => setDownloadDialogOpen(true)}
                  title={tabValue === 0 ? t('mainVideoPage.messages.downloadTranscriptPdf') : t('mainVideoPage.messages.downloadSummaryPdf')}
                >
                  <DownloadIcon fontSize="small" /> 
                </IconButton>
              </Box>
            </Box>

            {/* Download Language Selection Dialog */}
            <Dialog
              open={downloadDialogOpen}
              onClose={() => setDownloadDialogOpen(false)}
              fullScreen={{ xs: true, sm: false }}
              PaperProps={{
                sx: {
                  bgcolor: '#1A1A1A',
                  borderRadius: { xs: 0, sm: 2 },
                  minWidth: { xs: '100%', sm: 320 },
                  m: { xs: 0, sm: 2 },
                }
              }}
            >
              <DialogTitle sx={{ color: '#E11D48', fontWeight: 600, pb: 1 }}>
                {t('mainVideoPage.messages.downloadTitle', { type: tabValue === 0 ? t('mainVideoPage.tabs.transcript') : t('mainVideoPage.messages.summary') })}
              </DialogTitle>
              <DialogContent>
                <Typography sx={{ color: '#9CA3AF', fontSize: '0.85rem', mb: 2 }}>
                  {t('mainVideoPage.messages.selectLanguageVersion')}
                </Typography>
                <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1 }}>
                  <Button
                    fullWidth
                    variant="outlined"
                    onClick={() => handleDownloadPDF('english')}
                    sx={{
                      color: '#3B82F6',
                      borderColor: '#3B82F640',
                      justifyContent: 'flex-start',
                      textTransform: 'none',
                      py: 1.5,
                      '&:hover': { bgcolor: '#3B82F610', borderColor: '#3B82F6' }
                    }}
                  >
                    {t('mainVideoPage.messages.englishVersion')}
                  </Button>
                  <Button
                    fullWidth
                    variant="outlined"
                    onClick={() => handleDownloadPDF('urdu')}
                    sx={{
                      color: '#22C55E',
                      borderColor: '#22C55E40',
                      justifyContent: 'flex-start',
                      textTransform: 'none',
                      py: 1.5,
                      '&:hover': { bgcolor: '#22C55E10', borderColor: '#22C55E' }
                    }}
                  >
                    {t('mainVideoPage.messages.urduVersion')}
                  </Button>
                  <Button
                    fullWidth
                    variant="contained"
                    onClick={() => handleDownloadPDF('both')}
                    sx={{
                      bgcolor: '#E11D48',
                      color: 'white',
                      justifyContent: 'flex-start',
                      textTransform: 'none',
                      py: 1.5,
                      mt: 1,
                      '&:hover': { bgcolor: '#BE123C' }
                    }}
                  >
                    {t('mainVideoPage.messages.bothLanguages')}
                  </Button>
                </Box>
              </DialogContent>
              <DialogActions sx={{ px: 3, pb: 2 }}>
                <Button
                  onClick={() => setDownloadDialogOpen(false)}
                  sx={{ color: '#9CA3AF', textTransform: 'none' }}
                >
                  {t('common.cancel')}
                </Button>
              </DialogActions>
            </Dialog>

            {/* Speaker Info Section - Auto-tagged */}
            {tabValue === 0 && currentVideoId && (
              <Box sx={{
                px: 2,
                py: 1.5,
                borderBottom: '1px solid #2A2A2A',
                bgcolor: '#1F1F1F'
              }}>
                {taggingInProgress ? (
                  <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                    <CircularProgress size={16} sx={{ color: '#E11D48' }} />
                    <Typography sx={{ color: '#9CA3AF', fontSize: '0.75rem' }}>
                      {t('mainVideoPage.speakerTagging.inProgress')}
                    </Typography>
                  </Box>
                ) : speakerMapping && Object.keys(speakerMapping).length > 0 ? (
                  <Box>
                    <Typography sx={{ color: '#22C55E', fontSize: '0.75rem', fontWeight: 600, mb: 0.75 }}>
                        {t('mainVideoPage.messages.speakers')}
                    </Typography>
                    <Box sx={{ display: 'flex', gap: 0.5, flexWrap: 'wrap' }}>
                      {Object.entries(speakerMapping).map(([key, name]) => (
                        <Box
                          key={key}
                          sx={{
                            bgcolor: '#22C55E20',
                            color: '#22C55E',
                            border: '1px solid #22C55E40',
                            px: 1,
                            py: 0.25,
                            borderRadius: 1,
                            fontSize: '0.7rem',
                            fontWeight: 600,
                          }}
                        >
                          {name}
                        </Box>
                      ))}
                    </Box>
                  </Box>
                ) : null}
              </Box>
            )}

            {/* Transcript Content */}
            {tabValue === 0 && (
              <Box sx={{
                flex: { xs: '0 0 auto', sm: '0 0 250px' },
                maxHeight: { xs: '40vh', sm: '250px' },
                overflowY: 'auto',
                px: { xs: 1.5, sm: 2 },
                py: { xs: 1.5, sm: 2 },
                borderBottom: '1px solid #2A2A2A',
                '&::-webkit-scrollbar': {
                  width: '6px',
                },
                '&::-webkit-scrollbar-thumb': {
                  backgroundColor: '#4A4A4A',
                  borderRadius: '3px',
                },
              }}>
                {taggingInProgress ? (
                  <Box sx={{
                    display: 'flex',
                    flexDirection: 'column',
                    alignItems: 'center',
                    justifyContent: 'center',
                    py: 5,
                    gap: 2
                  }}>
                    <CircularProgress size={32} sx={{ color: '#E11D48' }} />
                    <Typography sx={{ color: '#9CA3AF', fontSize: '0.85rem', textAlign: 'center' }}>
                      🏷️ {t('mainVideoPage.speakerTagging.inProgress')}<br />
                      <span style={{ fontSize: '0.75rem' }}>{t('common.loading')}</span>
                    </Typography>
                  </Box>
                ) : (showAlternativeTranscript ? alternativeTranscript : transcript).length === 0 ? (
                  <Box sx={{ textAlign: 'center', py: 3, color: '#9CA3AF', fontSize: '0.85rem' }}>
                    {showAlternativeTranscript
                        ? t('mainVideoPage.messages.noAltTranslation')
                        : t('mainVideoPage.messages.noTranscriptAvailable')}
                  </Box>
                ) : (
                  (showAlternativeTranscript ? alternativeTranscript : transcript).map((item, idx) => {
                    // Check if current time is within this transcript segment
                    const isActive = currentTime >= item.start && currentTime < item.end;
                    const displaySpeaker = item.actualSpeaker || item.speaker || t('mainVideoPage.messages.unknownSpeaker');
                    const isNamedSpeaker = displaySpeaker && !['A', 'B', 'C', 'D', t('mainVideoPage.messages.unknownSpeaker')].includes(displaySpeaker);

                    // Detect if text is Urdu (RTL)
                    const isUrduText = showAlternativeTranscript
                      ? alternativeLanguage === 'ur'
                      : detectedLanguage === 'ur';

                    // Speaker color mapping for visual distinction
                    const getSpeakerColor = (speaker) => {
                      const colors = {
                        'A': '#3B82F6',
                        'B': '#10B981',
                        'C': '#F59E0B',
                        'D': '#8B5CF6',
                      };
                      return colors[speaker] || '#E11D48';
                    };

                    return (
                      <Box
                        key={idx}
                        sx={{
                          py: 1.5,
                          px: 1.5,
                          cursor: 'pointer',
                          borderRadius: 1,
                          bgcolor: isActive ? '#1e293b' : 'transparent',
                          borderLeft: isActive ? '3px solid #E11D48' : '3px solid transparent',
                          transition: 'all 0.3s ease',
                          direction: isUrduText ? 'rtl' : 'ltr',
                          '&:hover': {
                            bgcolor: '#252525',
                          }
                        }}
                        onClick={() => {
                          setSelectedTranscript(idx);
                          if (item.seconds !== undefined) {
                            playerSeekTo(item.seconds);
                            playerPlay();
                          }
                        }}
                      >
                        <Box sx={{
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'space-between',
                          mb: 0.5,
                          flexDirection: isUrduText ? 'row-reverse' : 'row'
                        }}>
                          <Typography
                            sx={{
                              color: isActive ? '#E11D48' : '#9CA3AF',
                              fontWeight: isActive ? 700 : 400,
                              fontSize: '0.75rem',
                            }}
                          >
                            {item.time}
                          </Typography>
                          {displaySpeaker && (
                            <Box
                              sx={{
                                bgcolor: isNamedSpeaker ? '#22C55E20' : getSpeakerColor(displaySpeaker) + '20',
                                color: isNamedSpeaker ? '#22C55E' : getSpeakerColor(displaySpeaker),
                                px: 1,
                                py: 0.25,
                                borderRadius: 1,
                                fontSize: '0.65rem',
                                fontWeight: 600,
                                border: `1px solid ${isNamedSpeaker ? '#22C55E' : getSpeakerColor(displaySpeaker)}40`,
                              }}
                            >
                              {displaySpeaker}
                            </Box>
                          )}
                        </Box>
                        <Typography
                          sx={{
                            color: isActive ? 'white' : '#9CA3AF',
                            fontSize: '0.75rem',
                            lineHeight: 1.5,
                            fontWeight: isActive ? 500 : 400,
                            textAlign: isUrduText ? 'right' : 'left',
                            fontFamily: isUrduText ? '"Noori Nastaliq", "Jameel Noori Nastaliq", "Alvi Nastaliq", "Noto Nastaliq Urdu", "Urdu Typesetting", "Arabic Typesetting", sans-serif' : 'inherit',
                          }}
                        >
                          {item.text}
                        </Typography>
                      </Box>
                    );
                  })
                )}
              </Box>
            )}

            {tabValue === 1 && (
              <Box sx={{
                p: { xs: 1.5, sm: 2 },
                color: '#E5E7EB',
                fontSize: { xs: '0.8rem', sm: '0.85rem' },
                overflowY: 'auto',
                maxHeight: { xs: '50vh', sm: '60vh', lg: '70vh' },
                '&::-webkit-scrollbar': {
                  width: '8px',
                },
                '&::-webkit-scrollbar-thumb': {
                  backgroundColor: '#4A4A4A',
                  borderRadius: '4px',
                },
              }}>
                {(showAlternativeSummary ? alternativeSummary : summary) ? (
                  <>
                    {/* Render summary with proper formatting */}
                    <Box sx={{
                      lineHeight: 1.8,
                      direction: showAlternativeSummary
                        ? (alternativeLanguage === 'ur' ? 'rtl' : 'ltr')
                        : (detectedLanguage === 'ur' ? 'rtl' : 'ltr'),
                    }}>
                      {(showAlternativeSummary ? alternativeSummary : summary)
                        .split('\n')
                        .filter(line => line.trim())
                        .map((line, index) => {
                          // Check if line is a bullet point
                          const isBullet = line.trim().startsWith('-') || line.trim().startsWith('•');
                          const cleanLine = isBullet ? line.trim().substring(1).trim() : line.trim();

                          // Detect if text is Urdu (RTL)
                          const isUrduText = showAlternativeSummary
                            ? alternativeLanguage === 'ur'
                            : detectedLanguage === 'ur';

                          // Parse bold text (**text** or __text__)
                          const parseBold = (text) => {
                            const parts = text.split(/(\*\*[^*]+\*\*)/g);
                            return parts.map((part, i) => {
                              if (part.startsWith('**') && part.endsWith('**')) {
                                const boldText = part.slice(2, -2);
                                return (
                                  <Typography
                                    key={i}
                                    component="span"
                                    sx={{
                                      fontWeight: 700,
                                      color: '#E11D48',
                                      fontSize: 'inherit',
                                      fontFamily: isUrduText ? '"Noori Nastaliq", "Jameel Noori Nastaliq", "Alvi Nastaliq", "Noto Nastaliq Urdu", "Urdu Typesetting", "Arabic Typesetting", sans-serif' : 'inherit',
                                    }}
                                  >
                                    {boldText}
                                  </Typography>
                                );
                              }
                              return part;
                            });
                          };

                          return (
                            <Box
                              key={index}
                              sx={{
                                display: 'flex',
                                alignItems: 'flex-start',
                                gap: 1.5,
                                mb: 1.5,
                                flexDirection: 'row',
                              }}
                            >
                              {isBullet && (
                                <Box
                                  sx={{
                                    width: 6,
                                    height: 6,
                                    borderRadius: '50%',
                                    bgcolor: '#E11D48',
                                    mt: 0.8,
                                    flexShrink: 0,
                                  }}
                                />
                              )}
                              <Typography
                                sx={{
                                  color: '#E5E7EB',
                                  fontSize: '0.85rem',
                                  lineHeight: 1.7,
                                  flex: 1,
                                  textAlign: isUrduText ? 'right' : 'left',
                                  fontFamily: isUrduText ? '"Noori Nastaliq", "Jameel Noori Nastaliq", "Alvi Nastaliq", "Noto Nastaliq Urdu", "Urdu Typesetting", "Arabic Typesetting", sans-serif' : 'inherit',
                                }}
                              >
                                {parseBold(cleanLine)}
                              </Typography>
                            </Box>
                          );
                        })}
                    </Box>
                  </>
                ) : (
                  <Box sx={{ textAlign: 'center', py: 3, color: '#9CA3AF', fontSize: '0.85rem' }}>
                      {t('mainVideoPage.messages.noSummaryAvailable')}
                  </Box>
                )}
              </Box>
            )}

            {/* Language Selector - Works for both Transcript and Summary */}
            <Box sx={{
              px: { xs: 1.5, sm: 2 },
              py: { xs: 1, sm: 1.5 },
              borderTop: '1px solid #2A2A2A',
              display: 'flex',
              flexDirection: { xs: 'column', sm: 'row' },
              gap: { xs: 0.75, sm: 1 },
            }}>
              <Button
                size="small"
                variant={(!showAlternativeTranscript && !showAlternativeSummary) ? 'contained' : 'outlined'}
                onClick={() => {
                  setShowAlternativeTranscript(false);
                  setShowAlternativeSummary(false);
                }}
                sx={{
                  flex: 1,
                  color: (!showAlternativeTranscript && !showAlternativeSummary) ? 'white' : '#9CA3AF',
                  bgcolor: (!showAlternativeTranscript && !showAlternativeSummary) ? '#E11D48' : 'transparent',
                  borderColor: '#4A4A4A',
                  textTransform: 'none',
                  fontSize: '0.75rem',
                  py: 0.75,
                  '&:hover': {
                    bgcolor: (!showAlternativeTranscript && !showAlternativeSummary) ? '#BE123C' : '#2A2A2A',
                    borderColor: '#6A6A6A',
                  },
                }}
              >
                {detectedLanguage === 'ur' ? t('mainVideoPage.messages.urduLabel') : t('mainVideoPage.messages.englishLabel')}
              </Button>
              <Button
                size="small"
                variant={(showAlternativeTranscript || showAlternativeSummary) ? 'contained' : 'outlined'}
                onClick={() => {
                  if (alternativeTranscript.length > 0 || alternativeSummary) {
                    setShowAlternativeTranscript(alternativeTranscript.length > 0);
                    setShowAlternativeSummary(!!alternativeSummary);
                  }
                }}
                disabled={alternativeTranscript.length === 0 && !alternativeSummary}
                sx={{
                  flex: 1,
                  color: (showAlternativeTranscript || showAlternativeSummary) ? 'white' : '#9CA3AF',
                  bgcolor: (showAlternativeTranscript || showAlternativeSummary) ? '#E11D48' : 'transparent',
                  borderColor: '#4A4A4A',
                  textTransform: 'none',
                  fontSize: '0.75rem',
                  py: 0.75,
                  '&:hover': {
                    bgcolor: (showAlternativeTranscript || showAlternativeSummary) ? '#BE123C' : '#2A2A2A',
                    borderColor: '#6A6A6A',
                  },
                  '&:disabled': {
                    color: '#6A6A6A',
                    borderColor: '#3A3A3A',
                  }
                }}
              >
                {alternativeLanguage === 'ur' ? t('mainVideoPage.messages.urduLabel') : t('mainVideoPage.messages.englishLabel')} {t('mainVideoPage.messages.translation')}
              </Button>
            </Box>
          </Box>
        </Box>

        {/* Trim Dialog */}
        <Dialog
          open={openTrimDialog}
          onClose={() => setOpenTrimDialog(false)}
          fullScreen={{ xs: true, sm: false }}
          PaperProps={{
            sx: {
              bgcolor: '#1A1A1A',
              color: 'white',
              minWidth: { xs: '100%', sm: 400 },
              m: { xs: 0, sm: 2 },
            }
          }}
        >
          <DialogTitle sx={{ color: 'white' }}>{t('mainVideoPage.messages.createClipDialog')}</DialogTitle>
          <DialogContent>
            <Box sx={{ pt: 2, display: 'flex', flexDirection: 'column', gap: 2 }}>
              <TextField
                label={t('mainVideoPage.messages.startTimeSeconds')}
                type="number"
                value={trimStart}
                onChange={(e) => setTrimStart(Math.max(0, Math.min(parseFloat(e.target.value) || 0, duration)))}
                fullWidth
                sx={{
                  '& .MuiInputLabel-root': { color: '#9CA3AF' },
                  '& .MuiInputBase-input': { color: 'white' },
                  '& .MuiOutlinedInput-root': {
                    '& fieldset': { borderColor: '#4A4A4A' },
                    '&:hover fieldset': { borderColor: '#6A6A6A' },
                    '&.Mui-focused fieldset': { borderColor: '#E11D48' },
                  },
                }}
              />
              <TextField
                label={t('mainVideoPage.messages.endTimeSeconds')}
                type="number"
                value={trimEnd}
                onChange={(e) => setTrimEnd(Math.max(trimStart, Math.min(parseFloat(e.target.value) || 0, duration)))}
                fullWidth
                sx={{
                  '& .MuiInputLabel-root': { color: '#9CA3AF' },
                  '& .MuiInputBase-input': { color: 'white' },
                  '& .MuiOutlinedInput-root': {
                    '& fieldset': { borderColor: '#4A4A4A' },
                    '&:hover fieldset': { borderColor: '#6A6A6A' },
                    '&.Mui-focused fieldset': { borderColor: '#E11D48' },
                  },
                }}
              />
              <Typography sx={{ color: '#9CA3AF', fontSize: '0.85rem' }}>
                {t('mainVideoPage.messages.durationLabel')} {formatTime(trimEnd - trimStart)}
              </Typography>
            </Box>
          </DialogContent>
          <DialogActions sx={{ p: 2 }}>
            <Button
              onClick={() => setOpenTrimDialog(false)}
              sx={{ color: '#9CA3AF' }}
            >
              {t('common.cancel')}
            </Button>
            <Button
              onClick={handleSaveClip}
              variant="contained"
              sx={{
                bgcolor: '#E11D48',
                '&:hover': { bgcolor: '#BE123C' }
              }}
            >
              {t('mainVideoPage.messages.saveClip')}
            </Button>
          </DialogActions>
        </Dialog>
      </Box>
    </UserAuthenticateLayout>
  );
}
