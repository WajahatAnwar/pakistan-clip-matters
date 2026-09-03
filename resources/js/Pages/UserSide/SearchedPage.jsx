import UserAuthenticateLayout from '@/Layouts/UserAuthenticateLayout';
import React, { useState, useMemo, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Box, Typography, Button, IconButton, Tabs, Tab, Avatar, Modal, CircularProgress, LinearProgress, Menu, MenuItem, ListItemIcon, ListItemText, Chip } from '@mui/material';
import YouTubeIcon from '@mui/icons-material/YouTube';

import DownloadIcon from '@mui/icons-material/Download';
import ShareIcon from '@mui/icons-material/Share';
import FilterListIcon from '@mui/icons-material/FilterList';
import HomeOutlinedIcon from '@mui/icons-material/HomeOutlined';
import PreviewIcon from '@mui/icons-material/Preview';
import HistoryOutlinedIcon from '@mui/icons-material/HistoryOutlined';
import CloseIcon from '@mui/icons-material/Close';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import TwitterIcon from '@mui/icons-material/Twitter';
import FacebookIcon from '@mui/icons-material/Facebook';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import SearchIcon from '@mui/icons-material/Search';
import { router, usePage } from '@inertiajs/react';
import RandomImage from '@/Images/random-data-grid.svg';

const uploadDateOptions = [
  'Last Hour',
  'Today',
  'This Week',
  'This Month',
  'This Year',
];
const durationOptions = [
  'Under 2 Minutes',
  '5-12 Minutes',
  'Over 20 Minutes',
];
const otherOptions = [
  'View Count',
  'Rating',
  'Uploaded Date',
];

const rose = '#F43F5E';
const gray = '#9CA3AF';

const decodeCursorSessionId = (cursorValue) => {
  if (!cursorValue || typeof cursorValue !== 'string') return null;
  try {
    // Support standard and URL-safe base64 cursors, with/without padding.
    let normalized = cursorValue.replace(/-/g, '+').replace(/_/g, '/');
    const remainder = normalized.length % 4;
    if (remainder) {
      normalized += '='.repeat(4 - remainder);
    }
    const decoded = JSON.parse(atob(normalized));
    return decoded?.search_session_id || null;
  } catch {
    return null;
  }
};

/**
 * SearchedPage Component
 * 
 * This page handles search results from multiple sources:
 * 1. Direct searches from UserAuthenticateLayout header (semantic/simple/speaker modes)
 * 2. Re-executing searches from search history (History tab)
 * 
 * SEARCH FLOW:
 * - User enters search in header → fetchSearchResults in UserAuthenticateLayout
 * - Backend processes search → Returns grouped video results
 * - Results posted to this page via router.post(route('searched.page'))
 * - Page displays results in Home tab (tabValue = 0)
 * - User can switch to History tab to view past searches
 * - Clicking any history item re-executes that search
 * 
 * TAB MANAGEMENT:
 * - Tab 0 (Home): Shows current search results
 * - Tab 1 (History): Shows user's search history with date/time
 * - Auto-resets to Home tab when new search results arrive
 */
export default function SearchedPage() {
  const { t } = useTranslation();
  const {
    videos,
    query,
    searchMode,
    totalVideos,
    totalSegments,
    searchMetadata,
    pagination,
    searchRequestBody,
    cursor: initialCursor,
    search_session_id: initialSessionId,
    is_incremental: isIncrementalResponse
  } = usePage().props;
  
  // Debug: Log all props
  console.log('📋 SearchedPage Props:', {
    videos_type: typeof videos,
    videos_is_array: Array.isArray(videos),
    videos_length: Array.isArray(videos) ? videos.length : 'N/A',
    query,
    searchMode,
    totalVideos,
    totalSegments,
    pagination,
    is_incremental: isIncrementalResponse,
    cursor: initialCursor
  });

  // Incremental loading state
  const [loadedResults, setLoadedResults] = useState([]);  // All results loaded so far
  const [displayedResults, setDisplayedResults] = useState([]);  // Currently visible results
  const [cursor, setCursor] = useState(initialCursor?.next || null);
  const [hasMore, setHasMore] = useState(initialCursor?.has_more || pagination?.has_next_page || false);
  const [searchSessionId, setSearchSessionId] = useState(() => {
    // Try to restore from sessionStorage if not provided
    const cursorSessionId = decodeCursorSessionId(initialCursor?.next);
    return initialSessionId || cursorSessionId || sessionStorage.getItem('search_session_id') || null;
  });
  const [isLoadingMore, setIsLoadingMore] = useState(false);
  const [totalAvailable, setTotalAvailable] = useState(initialCursor?.total_available || totalVideos || 0);
  const [currentPage, setCurrentPage] = useState(pagination?.current_page || 1);
  const [isPageBased, setIsPageBased] = useState(!(isIncrementalResponse || Boolean(initialCursor?.next || initialCursor?.has_more)));
  const [visibilityWindowStart, setVisibilityWindowStart] = useState(0);  // Start index of visibility window
  const VISIBILITY_WINDOW_SIZE = 30;  // Show max 30 results at a time
  const ENABLE_BACKGROUND_PREFETCH = false;
  const prefetchedDataRef = React.useRef(null);  // Store prefetched data

  // Deduplicate segments within each video result by segment_id and time overlap
  const deduplicateSegments = (videoResults) => {
    return videoResults.map(result => {
      if (!result.segments || result.segments.length <= 1) return result;
      
      // 1. Deduplicate by segment_id
      const byId = new Map();
      for (const seg of result.segments) {
        // Fallback key if segment_id is missing
        const key = seg.segment_id || (seg.start_time + '-' + seg.end_time);
        const existing = byId.get(key);
        if (!existing || (seg.score || 0) > (existing.score || 0)) {
          byId.set(key, seg);
        }
      }
      
      // 2. Filter time overlaps
      const sorted = Array.from(byId.values()).sort((a, b) => (a.start_time || 0) - (b.start_time || 0));
      const finalSegments = [];
      for (const seg of sorted) {
        if (finalSegments.length === 0) {
          finalSegments.push(seg);
        } else {
          const last = finalSegments[finalSegments.length - 1];
          // Overlap condition: segment starts before previous segment ends
          if ((seg.start_time || 0) < (last.end_time || 0)) {
            if ((seg.score || 0) > (last.score || 0)) {
              finalSegments.pop();
              finalSegments.push(seg);
            }
          } else {
            finalSegments.push(seg);
          }
        }
      }
      return { ...result, segments: finalSegments, match_count: finalSegments.length };
    });
  };

  // Handle videos data - now comes directly as array from session
  const parsedVideos = useMemo(() => {
    if (!videos) return [];
    let parsed;
    if (Array.isArray(videos)) {
      parsed = videos;
    } else if (typeof videos === 'string') {
      try { parsed = JSON.parse(videos); } catch { return []; }
    } else {
      return [];
    }
    const deduped = deduplicateSegments(parsed);
    // Sort: tag matches first, then semantic matches (with actual segments), then title-only cards after, both sorted by match_count desc
    return deduped.sort((a, b) => {
      const aIsTagMatch = a.match_types && a.match_types.includes('tag_match') ? 1 : 0;
      const bIsTagMatch = b.match_types && b.match_types.includes('tag_match') ? 1 : 0;
      if (aIsTagMatch !== bIsTagMatch) return bIsTagMatch - aIsTagMatch; // tag matches to top

      const aIsTitleOnly = (!a.segments || a.segments.length === 0) ? 1 : 0;
      const bIsTitleOnly = (!b.segments || b.segments.length === 0) ? 1 : 0;
      if (bIsTitleOnly !== aIsTitleOnly) return aIsTitleOnly - bIsTitleOnly;
      return (b.match_count || 0) - (a.match_count || 0);
    });
  }, [videos]);

  const [openFilters, setOpenFilters] = useState(false);
  // Default to Home tab (0) when there are search results, otherwise History tab can be selected
  const [tabValue, setTabValue] = useState(0);
  const [selectedUploadDate, setSelectedUploadDate] = useState('');
  const [selectedDuration, setSelectedDuration] = useState('');
  const [selectedOther, setSelectedOther] = useState('');
  const [downloadingVideoId, setDownloadingVideoId] = useState(null);
  const [shareMenuAnchor, setShareMenuAnchor] = useState(null);
  const [selectedVideoForShare, setSelectedVideoForShare] = useState(null);
  const [searchHistory, setSearchHistory] = useState([]);
  const [loadingHistory, setLoadingHistory] = useState(false);
  const [searchingFromHistory, setSearchingFromHistory] = useState(false);
  const [lastSearchParams, setLastSearchParams] = useState(null);
  const [expandedVideoSegments, setExpandedVideoSegments] = useState({}); // Track which videos have expanded segments
  const [expandedSegmentTexts, setExpandedSegmentTexts] = useState({}); // Track which individual segment texts are expanded

  // Toggle expanded segments for a video
  const toggleExpandedSegments = (videoId) => {
    setExpandedVideoSegments(prev => ({
      ...prev,
      [videoId]: !prev[videoId]
    }));
  };

  // Toggle expanded text for a specific segment
  const toggleSegmentText = (segKey) => {
    setExpandedSegmentTexts(prev => ({
      ...prev,
      [segKey]: !prev[segKey]
    }));
  };

  // Track whether initial prefetch has been triggered — use the cursor value to prevent duplicates
  const initialPrefetchDoneRef = React.useRef(false);
  const lastPrefetchedCursorRef = React.useRef(null); // Track which cursor was already prefetched
  const lastSearchParamsRef = React.useRef(null); // Ref for prefetch closure to avoid stale state

  // Initialize loaded results from initial page load
  // IMPORTANT: Only depend on parsedVideos (new search results) - NOT on cursor/hasMore/searchSessionId
  // Those change when handleLoadMore runs, which would reset loadedResults and wipe new results
  useEffect(() => {
    // Always reset state on new search, even when results are empty.
    // Without this, a zero-result search leaves the previous search's results visible.
    if (!parsedVideos || parsedVideos.length === 0) {
      setLoadedResults([]);
      setDisplayedResults([]);
      setCursor(null);
      setHasMore(false);
      setTotalAvailable(0);
      setCurrentPage(1);
      setVisibilityWindowStart(0);
      return;
    }

    if (parsedVideos && parsedVideos.length > 0) {
      setLoadedResults(parsedVideos);
      updateVisibilityWindow(parsedVideos, 0);

      // CRITICAL FIX: Sync cursor/hasMore/searchSessionId from Inertia props on every new search.
      // Because Inertia is a SPA, useState initializers don't re-run on SPA navigation — the
      // component stays mounted with stale state. We must explicitly sync from props here.
      const newCursor = initialCursor?.next || null;
      const cursorSessionId = decodeCursorSessionId(newCursor);
      const isIncrementalFromPayload = Boolean(isIncrementalResponse || newCursor || initialCursor?.has_more);
      const isPaginated = !isIncrementalFromPayload;
      const newHasMore = isPaginated ? pagination?.has_next_page : initialCursor?.has_more || false;
      const rawTotalAvailable = isPaginated
        ? (totalVideos || parsedVideos.length)
        : (initialCursor?.total_available ?? parsedVideos.length);
      const newTotalAvailable = Math.max(rawTotalAvailable, parsedVideos.length);
      const newCurrentPage = pagination?.current_page || 1;
      const resolvedSessionId = initialSessionId || cursorSessionId || sessionStorage.getItem('search_session_id') || null;

      setCursor(isPaginated ? null : newCursor);
      setHasMore(newHasMore);
      setTotalAvailable(newTotalAvailable);
      setCurrentPage(newCurrentPage);
      setIsPageBased(isPaginated);
      if (resolvedSessionId) {
        setSearchSessionId(resolvedSessionId);
        sessionStorage.setItem('search_session_id', resolvedSessionId);
      } else {
        setSearchSessionId(null);
        sessionStorage.removeItem('search_session_id');
      }

      // Store search params for "View More" requests
      const params = searchRequestBody || {
        query: query || '',
        search_mode: searchMode || 'semantic',
        use_incremental: true
      };
      setLastSearchParams(params);
      lastSearchParamsRef.current = params; // Keep ref in sync for closures

      console.log('📥 Initialized with results:', {
        total_loaded: parsedVideos.length,
        has_more: newHasMore,
        cursor: newCursor ? 'present' : 'none',
        session_id: resolvedSessionId?.substring(0, 8)
      });

      // Prefetch next batch if more results available
      // Guard: only prefetch if this cursor hasn't been prefetched yet
      if (ENABLE_BACKGROUND_PREFETCH && newHasMore && newCursor && lastPrefetchedCursorRef.current !== newCursor) {
        lastPrefetchedCursorRef.current = newCursor;
        prefetchedDataRef.current = null; // Clear stale prefetch
        setTimeout(() => prefetchNextBatch(newCursor), 500);
      }
    }
  }, [parsedVideos, query, searchMode]);

  // Update visibility window (show subset of results)
  const updateVisibilityWindow = (allResults, startIndex) => {
    const endIndex = Math.min(startIndex + VISIBILITY_WINDOW_SIZE, allResults.length);
    const visibleResults = allResults.slice(startIndex, endIndex);
    setDisplayedResults(visibleResults);
    setVisibilityWindowStart(startIndex);

    console.log('👁️ Updated visibility window:', {
      start: startIndex,
      end: endIndex,
      visible_count: visibleResults.length,
      total_loaded: allResults.length
    });
  };

  // Prefetch next batch in background for faster "View More"
  const prefetchNextBatch = async (overrideCursor = null) => {
    if (!ENABLE_BACKGROUND_PREFETCH) {
      return;
    }

    const cursorToUse = overrideCursor || cursor;
    if (!cursorToUse || prefetchedDataRef.current) {
      return; // Skip if no cursor or already prefetched
    }

    // Use ref to get the latest search params (avoids stale closure)
    const currentParams = lastSearchParamsRef.current || lastSearchParams;
    if (!currentParams || Object.keys(currentParams).length === 0) {
      console.warn('⚠️ Prefetch skipped: no search params available yet');
      return;
    }

    console.log('🔮 Prefetching next batch...');

    try {
      const cursorSessionId = decodeCursorSessionId(cursorToUse);
      const effectiveSessionId = cursorSessionId || searchSessionId || null;

      const requestBody = {
        ...currentParams,
        cursor: cursorToUse,
        batch_size: 10,
        search_session_id: effectiveSessionId,
        use_incremental: true
      };

      const response = await fetch(route('videos.embeddings.search'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify(requestBody)
      });

      const data = await response.json();

      if (data.success && data.grouped_by_video) {
        prefetchedDataRef.current = data;
        console.log('✅ Prefetch complete:', data.grouped_by_video.length, 'results');
      }
    } catch (error) {
      console.warn('⚠️ Prefetch failed (non-critical):', error);
    }
  };

  // Handle "View More" - load next batch of results
  const loadingMoreRef = React.useRef(false);  // Ref guard for concurrent calls
  const handleLoadMore = async () => {
    if (loadingMoreRef.current || isLoadingMore || !hasMore) {
      console.log('⏭️ View More blocked:', { isLoadingMore, hasMore });
      return;
    }

    // For page-based pagination, cursor is not required
    if (!isPageBased && !cursor) {
      console.log('⏭️ Cursor-based search blocked: no cursor available');
      return;
    }

    loadingMoreRef.current = true;
    setIsLoadingMore(true);

    try {
      let data;
      const cursorSessionId = !isPageBased ? decodeCursorSessionId(cursor) : null;
      const effectiveSessionId = cursorSessionId || searchSessionId || null;

      // Use prefetched data if available
      if (ENABLE_BACKGROUND_PREFETCH && prefetchedDataRef.current) {
        console.log('⚡ Using prefetched data');
        data = prefetchedDataRef.current;
        prefetchedDataRef.current = null; // Clear after use
      } else {
        console.log('🔄 Loading more results...', isPageBased ? 'page-based' : 'cursor-based');

        const requestBody = isPageBased ? {
          ...lastSearchParams,
          page: currentPage + 1,
          per_page: lastSearchParams?.per_page || 10,
          use_incremental: false,
          cursor: null,
          search_session_id: null,
        } : {
          ...lastSearchParams,
          cursor: cursor,
          batch_size: 10,
            search_session_id: effectiveSessionId,
          use_incremental: true
        };

        const response = await fetch(route('videos.embeddings.search'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
          },
          body: JSON.stringify(requestBody)
        });

        data = await response.json();
      }

      console.log('✅ View More response:', data);

      if (data.success && data.grouped_by_video) {
        const newResults = Array.isArray(data.grouped_by_video) ? data.grouped_by_video : [];

        // Deduplicate by video_id to prevent duplicates from concurrent/retry requests
        const existingVideoIds = new Set(loadedResults.map(r => r.video?.id || r.video_id));
        const uniqueNewResults = newResults.filter(r => {
          const vid = r.video?.id || r.video_id;
          if (existingVideoIds.has(vid)) {
            console.log('⚠️ Skipping duplicate video:', vid);
            return false;
          }
          existingVideoIds.add(vid);
          return true;
        });
        const updatedResults = [...loadedResults, ...uniqueNewResults];

        // Handle both pagination types
        const newCursor = data.cursor?.next || null;
        const pageHasNext = data.pagination?.has_next_page ?? data.has_next_page ?? false;
        const pageCurrent = data.pagination?.current_page ?? data.current_page ?? currentPage;
        const pageTotal = data.pagination?.total ?? data.totalVideos ?? data.total_videos ?? updatedResults.length;
        const rawCursorTotal = data.cursor?.total_available ?? updatedResults.length;

        const newHasMore = isPageBased
          ? pageHasNext
          : data.cursor?.has_more || false;
        const newTotalAvailable = Math.max(
          isPageBased ? pageTotal : rawCursorTotal,
          updatedResults.length
        );
        const newCurrentPage = pageCurrent;
        const nextCursorSessionId = decodeCursorSessionId(newCursor);
        const resolvedResponseSessionId = data.search_session_id || nextCursorSessionId || effectiveSessionId || null;

        // Safety valve: if backend returns no unique videos and cursor does not advance,
        // stop pagination to prevent endless duplicate "View More" loops.
        const cursorAdvanced = isPageBased ? true : Boolean(newCursor && newCursor !== cursor);
        const effectiveHasMore = (!isPageBased && uniqueNewResults.length === 0 && !cursorAdvanced)
          ? false
          : newHasMore;

        setLoadedResults(updatedResults);
        setCursor(newCursor);
        setHasMore(effectiveHasMore);
        setTotalAvailable(newTotalAvailable);
        setCurrentPage(newCurrentPage);
        setSearchSessionId(resolvedResponseSessionId);
        if (resolvedResponseSessionId) {
          sessionStorage.setItem('search_session_id', resolvedResponseSessionId);
        }

        // Always update visibility window to show new results
        updateVisibilityWindow(updatedResults, visibilityWindowStart);

        console.log('📊 Results updated:', {
          pagination_type: isPageBased ? 'page-based' : 'cursor-based',
          new_batch_size: newResults.length,
          unique_added: uniqueNewResults.length,
          total_loaded: updatedResults.length,
          has_more: effectiveHasMore,
          raw_has_more: newHasMore,
          cursor_advanced: cursorAdvanced,
          total_available: newTotalAvailable,
          current_page: newCurrentPage
        });

        // Prefetch next batch using the NEW cursor (only for cursor-based pagination)
        // Guard: only prefetch if this cursor hasn't been prefetched yet
        if (ENABLE_BACKGROUND_PREFETCH && !isPageBased && effectiveHasMore && newCursor && lastPrefetchedCursorRef.current !== newCursor) {
          lastPrefetchedCursorRef.current = newCursor;
          setTimeout(() => prefetchNextBatch(newCursor), 300);
        }
      }
    } catch (error) {
      console.error('❌ View More error:', error);
    } finally {
      loadingMoreRef.current = false;
      setIsLoadingMore(false);
    }
  };

  // Handle "View Previous" - shift visibility window backward
  const handleViewPrevious = () => {
    if (visibilityWindowStart === 0) return;

    const newStart = Math.max(0, visibilityWindowStart - VISIBILITY_WINDOW_SIZE);
    updateVisibilityWindow(loadedResults, newStart);

    // Scroll to top of results
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  // Handle "View Next Window" - shift visibility window forward (using cached results)
  const handleViewNextWindow = () => {
    const newStart = visibilityWindowStart + VISIBILITY_WINDOW_SIZE;
    if (newStart >= loadedResults.length) return;

    updateVisibilityWindow(loadedResults, newStart);

    // Scroll to top of results
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  // Compute actual total segments across all loaded results (updates after View More)
  const actualTotalSegments = useMemo(() => {
    const source = loadedResults.length > 0 ? loadedResults : parsedVideos;
    return source.reduce((sum, v) => sum + (v.segments?.length || 0), 0);
  }, [loadedResults, parsedVideos]);

  // Reset to Home tab when new search results arrive
  useEffect(() => {
    if (parsedVideos.length > 0 || query) {
      setTabValue(0);
      // Store the original search request body for pagination
      const params = searchRequestBody || {
        query,
        searchMode,
        min_score: 0.50,
        top_k: 200,
        per_page: pagination?.per_page || 10
      };
      setLastSearchParams(params);
      lastSearchParamsRef.current = params; // Keep ref in sync for prefetch closure
      // Clear any loading states when new results arrive
      setSearchingFromHistory(false);
    }
  }, [parsedVideos, query, searchMode, pagination]);

  // Fetch search history when History tab is selected
  useEffect(() => {
    if (tabValue === 1) {
      fetchSearchHistory();
    } else {
      // Clear searching state when switching away from History tab
      setSearchingFromHistory(false);
    }
  }, [tabValue]);

  // Fetch search history from API
  const fetchSearchHistory = async () => {
    setLoadingHistory(true);
    try {
      const response = await fetch(route('user.search.history'));
      const data = await response.json();
      setSearchHistory(data.data || []);
    } catch (error) {
      console.error('Failed to fetch search history:', error);
    } finally {
      setLoadingHistory(false);
    }
  };

  // History items are now read-only - no re-execution of searches
  const handleHistoryItemClick = (historyItem) => {
    console.log('🔍 History item clicked:', historyItem);
    // History is now for reference only, no action needed
  };

  // Get temporary Dropbox link for video download
  const getVideoTemporaryLink = async (videoId) => {
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const response = await fetch(route('video.link'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken
        },
        body: JSON.stringify({ video_id: videoId })
      });

      const result = await response.json();
      console.log('Temporary link response:', result);
      return result?.temporary_link || null;
    } catch (error) {
      console.error('Failed to fetch temporary video link:', error);
      return null;
    }
  };

  // Handle video download
  const handleDownloadVideo = async (video) => {

    setDownloadingVideoId(video.id);

    try {
      // Get temporary Dropbox link
      const temporaryLink = await getVideoTemporaryLink(video.id);

      if (!temporaryLink) {
        alert(t('searchedPage.failedToGetLink') || 'Failed to get download link');
        return;
      }

      // Create a temporary anchor element and trigger download
      const link = document.createElement('a');
      link.href = temporaryLink;
      link.download = video.title || video.filename || 'video.mp4';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);

      // Show success message
      alert(t('searchedPage.downloadStarted') || 'Download started!');
    } catch (error) {
      console.error('Download error:', error);
      alert(t('searchedPage.downloadFailed') || 'Download failed. Please try again.');
    } finally {
      setDownloadingVideoId(null);
    }
  };

  // Open share menu
  const handleShareClick = (event, video) => {
    event.stopPropagation();
    setShareMenuAnchor(event.currentTarget);
    setSelectedVideoForShare(video);
  };

  // Close share menu
  const handleShareMenuClose = () => {
    setShareMenuAnchor(null);
    setSelectedVideoForShare(null);
  };

  // Share to WhatsApp
  const handleShareWhatsApp = () => {
    const shareUrl = selectedVideoForShare.youtube_url || (window.location.origin + route('user.video.page', { id: selectedVideoForShare.id }));
    const shareTitle = selectedVideoForShare.title || selectedVideoForShare.filename || 'Check out this video';
    const whatsappUrl = `https://wa.me/?text=${encodeURIComponent(shareTitle + ' ' + shareUrl)}`;
    window.open(whatsappUrl, '_blank');
    handleShareMenuClose();
  };

  // Share to Twitter
  const handleShareTwitter = () => {
    const shareUrl = selectedVideoForShare.youtube_url || (window.location.origin + route('user.video.page', { id: selectedVideoForShare.id }));
    const shareTitle = selectedVideoForShare.title || selectedVideoForShare.filename || 'Check out this video';
    const twitterUrl = `https://twitter.com/intent/tweet?text=${encodeURIComponent(shareTitle)}&url=${encodeURIComponent(shareUrl)}`;
    window.open(twitterUrl, '_blank');
    handleShareMenuClose();
  };

  // Share to Facebook
  const handleShareFacebook = () => {
    const shareUrl = selectedVideoForShare.youtube_url || (window.location.origin + route('user.video.page', { id: selectedVideoForShare.id }));
    const facebookUrl = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(shareUrl)}`;
    window.open(facebookUrl, '_blank');
    handleShareMenuClose();
  };

  // Copy link to clipboard
  const handleCopyLink = async () => {
    const shareUrl = selectedVideoForShare.youtube_url || (window.location.origin + route('user.video.page', { id: selectedVideoForShare.id }));
    try {
      await navigator.clipboard.writeText(shareUrl);
      alert(t('searchedPage.linkCopied') || 'Link copied to clipboard!');
      handleShareMenuClose();
    } catch (error) {
      console.error('Copy error:', error);
      alert('Failed to copy link');
    }
  };

  // Helper function to extract YouTube video ID
  const getYouTubeId = (url) => {
    if (!url) return null;
    const match = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\s]+)/);
    return match ? match[1] : null;
  };

  // Highlight matching query words in text
  const highlightText = (text, searchQuery) => {
    if (!text || !searchQuery) return text;
    // List of common English stop words that shouldn't be highlighted to prevent 
    // substring matches inside larger words (like "on" inside "Conference")
    const stopWords = new Set(['a', 'an', 'the', 'and', 'or', 'but', 'is', 'am', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'to', 'of', 'in', 'for', 'on', 'with', 'at', 'by', 'from', 'up', 'about', 'into', 'over', 'after', 'this', 'that', 'it']);
    
    // Split query into individual words, filter out stop words and single characters
    const words = searchQuery
      .split(/\s+/)
      .map(w => w.trim())
      .filter(w => w.length >= 2 && !stopWords.has(w.toLowerCase()));
      
    if (words.length === 0) return text;
    
    // Build regex that matches any of the query words (case-insensitive)
    const escaped = words.map(w => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    const pattern = new RegExp(`(${escaped.join('|')})`, 'gi');
    const parts = text.split(pattern);
    return parts.map((part, i) =>
      pattern.test(part)
        ? <span key={i} style={{ backgroundColor: 'rgba(244,63,94,0.35)', color: '#FFF', borderRadius: '2px', padding: '0 2px', fontWeight: 600 }}>{part}</span>
        : part
    );
  };

  // Helper function to format date
  const formatDate = (dateString) => {
    if (!dateString) return 'Unknown date';
    try {
      const date = new Date(dateString);
      // Check if date is valid
      if (isNaN(date.getTime())) return 'Unknown date';

      const now = new Date();
      const diffTime = Math.abs(now - date);
      const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

      if (diffDays < 1) return 'Today';
      if (diffDays === 1) return 'Yesterday';
      if (diffDays < 7) return `${diffDays} days ago`;
      if (diffDays < 30) return `${Math.floor(diffDays / 7)} weeks ago`;
      if (diffDays < 365) return `${Math.floor(diffDays / 30)} months ago`;
      return `${Math.floor(diffDays / 365)} years ago`;
    } catch (e) {
      console.warn('Invalid date format:', dateString, e);
      return 'Unknown date';
    }
  };

  return (
    <UserAuthenticateLayout>
      <Box sx={{
        maxWidth: { xs: '100%', sm: '95vw', md: '90vw', lg: '85vw' },
        mx: 'auto',
        mt: { xs: 2, sm: 3, md: 4 },
        mb: { xs: 2, sm: 3, md: 4 },
        bgcolor: '#1F2324',
        borderRadius: { xs: 2, sm: 3, md: 4 },
        p: { xs: 2, sm: 3, md: 4 }
      }}>
        {/* Tabs */}
        <Box sx={{ px: { xs: 1, sm: 2 }, pt: { xs: 1, sm: 2 } }}>
          <Tabs
            value={tabValue}
            onChange={(e, newValue) => setTabValue(newValue)}
            TabIndicatorProps={{ style: { background: 'none' } }}
            sx={{
              minHeight: 0,
              '& .MuiTab-root': {
                color: gray,
                fontWeight: 500,
                fontSize: { xs: '0.85rem', sm: '0.95rem', md: '1rem' },
                minHeight: 0,
                px: { xs: 1.5, sm: 2, md: 3 },
                textTransform: 'none',
                display: 'flex',
                alignItems: 'center',
                gap: { xs: 0.5, sm: 1 },
              },
              '& .Mui-selected': {
                color: rose + ' !important',
                fontWeight: 700,
                position: 'relative',
                '& .MuiTab-iconWrapper': {
                  color: rose,
                },
                '&:after': {
                  content: '""',
                  display: 'block',
                  position: 'absolute',
                  left: 0,
                  right: 0,
                  bottom: -2,
                  height: '3px',
                  background: rose,
                  borderRadius: 2,
                },
              },
              mb: 2,
            }}
          >
            <Tab icon={<HomeOutlinedIcon sx={{ color: tabValue === 0 ? rose : gray, mr: 1 }} />} iconPosition="start" label={<span style={{ color: tabValue === 0 ? rose : gray }}>{t('searchedPage.home')}</span>} />
            <Tab icon={<HistoryOutlinedIcon sx={{ color: tabValue === 1 ? rose : gray, mr: 1 }} />} iconPosition="start" label={<span style={{ color: tabValue === 1 ? rose : gray }}>{t('searchedPage.history')}</span>} />
          </Tabs>
        </Box>

        {/* Conditional Content Based on Tab */}
        {tabValue === 0 ? (
          <>
            {/* Results Heading with Elastic Search Info */}
            <Box sx={{ mb: { xs: 2, sm: 3 } }}>
              <Typography sx={{ color: 'white', fontWeight: 600, fontSize: { xs: '1rem', sm: '1.15rem', md: '1.25rem' }, mb: 1 }}>
                {t('searchedPage.searchFound')} {totalVideos || parsedVideos.length} {t('searchedPage.results')}
                {actualTotalSegments > 0 && (
                  <Typography component="span" sx={{ color: '#9CA3AF', fontWeight: 400, fontSize: { xs: '0.8rem', sm: '0.9rem', md: '0.95rem' }, ml: { xs: 1, sm: 2 } }}>
                    ({actualTotalSegments} matching segments)
                  </Typography>
                )}
              </Typography>
              {query && (
                <Box sx={{ display: 'flex', alignItems: 'center', gap: { xs: 1, sm: 2 }, flexWrap: 'wrap' }}>
                  <Typography sx={{ color: '#9CA3AF', fontWeight: 400, fontSize: { xs: '0.85rem', sm: '0.95rem', md: '1rem' } }}>
                    {t('searchedPage.for')} "{query}"
                  </Typography>
                  <Chip
                    label={
                      searchMode === 'semantic' || searchMode === 'search'
                        ? `🧠 AI Smart Search`
                        : searchMode === 'speaker'
                          ? `👤 Speaker Search`
                          : searchMode?.startsWith('simple_')
                            ? `🔍 ${t('searchedPage.simple')} — ${searchMode.replace('simple_', '').charAt(0).toUpperCase() + searchMode.replace('simple_', '').slice(1)}`
                            : `📝 Keyword ${t('searchedPage.search')}`
                    }
                    size="small"
                    sx={{
                      bgcolor: searchMode === 'semantic' || searchMode === 'search' ? '#4F46E5'
                        : searchMode === 'speaker' ? '#EE1D52'
                          : searchMode?.startsWith('simple_') ? '#D97706'
                            : '#059669',
                      color: 'white',
                      fontWeight: 500,
                      fontSize: { xs: '0.65rem', sm: '0.7rem', md: '0.75rem' },
                    }}
                  />
                  {searchMetadata?.elastic_features && (
                    <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap' }}>
                      {searchMetadata.elastic_features.fuzzy_matching && (
                        <Chip label="🔍 Flexible Match" size="small" sx={{ bgcolor: '#374151', color: '#9CA3AF', fontSize: '0.7rem' }} />
                      )}
                      {searchMetadata.elastic_features.typo_tolerance && (
                        <Chip label="✨ Spelling Auto-Corrected" size="small" sx={{ bgcolor: '#374151', color: '#9CA3AF', fontSize: '0.7rem' }} />
                      )}
                    </Box>
                  )}
                </Box>
              )}
              {searchMetadata?.response_time_ms && (
                <Typography sx={{ color: '#6B7280', fontSize: '0.75rem', mt: 0.5 }}>
                  Search completed in {searchMetadata.response_time_ms}ms
                </Typography>
              )}
            </Box>

            {/* Results List */}
            <Box sx={{ display: 'flex', flexDirection: 'column', gap: 3, }}>
              {displayedResults.length === 0 ? (
                <Box sx={{
                  textAlign: 'center',
                  py: { xs: 6, sm: 10, md: 12 },
                  px: { xs: 2, sm: 4 },
                  display: 'flex',
                  flexDirection: 'column',
                  alignItems: 'center',
                  gap: 2
                }}>
                  {/* Icon */}
                  <Box sx={{
                    width: { xs: 80, sm: 100 },
                    height: { xs: 80, sm: 100 },
                    borderRadius: '50%',
                    bgcolor: 'rgba(244, 63, 94, 0.1)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    mb: 1
                  }}>
                    <SearchIcon sx={{ fontSize: { xs: 40, sm: 50 }, color: '#F43F5E', opacity: 0.7 }} />
                  </Box>

                  {query ? (
                    <>
                      <Typography sx={{ color: 'white', fontWeight: 600, fontSize: { xs: '1.1rem', sm: '1.3rem', md: '1.5rem' } }}>
                        {t('searchPage.noResults')}
                      </Typography>
                      <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '0.85rem', sm: '0.95rem' }, maxWidth: 440, lineHeight: 1.6 }}>
                        {t('searchPage.noResultsFor', { query })}
                      </Typography>
                    </>
                  ) : (
                    <>
                      <Typography sx={{ color: 'white', fontWeight: 600, fontSize: { xs: '1.1rem', sm: '1.3rem', md: '1.5rem' } }}>
                        {t('searchedPage.performSearch')}
                      </Typography>
                        <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '0.85rem', sm: '0.95rem' }, maxWidth: 440, lineHeight: 1.6 }}>
                          {t('searchPage.helpText')}
                        </Typography>
                      </>
                  )}
                </Box>
              ) : (
                  displayedResults.map((result, idx) => {
                  console.log(`🎥 Rendering video ${idx}:`, {
                    result_keys: Object.keys(result || {}),
                    video_exists: !!result.video,
                    video_id: result.video?.id,
                    video_title: result.video?.title,
                    segments_count: result.segments?.length,
                    match_count: result.match_count
                  });

                  const thumbnailUrl = RandomImage;
    const bestSegment = result.segments?.[0] || {};
    
    return (
      <Box
      key={result.video?.id || idx}
      sx={{
                  bgcolor: '#161616',
        p: { xs: 1.5, sm: 2 },
        borderRadius: { xs: 2, sm: 3 },
      }}>

      <Box
        sx={{
          display: 'flex',
            flexDirection: { xs: 'column', sm: 'row' },
            gap: { xs: 2, sm: 3 },
            alignItems: { xs: 'center', sm: 'stretch' },
         
        }}
      >
        {/* Thumbnail only */}
          <Box onClick={() => {
            console.log('🖼️ Thumbnail clicked for video:', result.video?.id);
            // Store segments in sessionStorage to pass to video page
            if (result.segments && result.segments.length > 0) {
              sessionStorage.setItem('searchSegments', JSON.stringify(result.segments));
              console.log('✅ Stored', result.segments.length, 'segments in sessionStorage');
            }
            if (query) {
              sessionStorage.setItem('searchQuery', query);
            }
            console.log('🚀 Navigating to:', route('user.video.page', { id: result.video?.id }));
            router.get(route('user.video.page', { id: result.video?.id }));
          }} sx={{
            position: 'relative',
            width: { xs: '100%', sm: 220 },
            minWidth: { xs: 'auto', sm: 220 },
            height: { xs: 180, sm: 130 },
            borderRadius: 2,
            overflow: 'hidden',
            bgcolor: '#111',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            cursor: 'pointer'
            }}>
            <img src={thumbnailUrl} alt={result.video?.title || 'Video thumbnail'} style={{ width: '100%', height: '100%', objectFit: 'cover', borderRadius: 8 }} />
          {/* Centered YouTube icon */}
          <Box sx={{ position: 'absolute', top: '50%', left: '50%', transform: 'translate(-50%, -50%)', bgcolor: 'white', borderRadius: '50%', p: 1, boxShadow: 2 }}>
            <YouTubeIcon sx={{ color: 'red', fontSize: 36 }} />
          </Box>
          {/* Match count badge */}
          {result.match_count > 0 && (
            <Box sx={{ position: 'absolute', top: 8, right: 8, bgcolor: '#EE1D52', borderRadius: '12px', px: 1.5, py: 0.5 }}>
              <Typography sx={{ color: 'white', fontSize: '0.75rem', fontWeight: 600 }}>
                {result.match_count} {result.match_count === 1 ? t('searchedPage.match') : t('searchedPage.matches')}
              </Typography>
            </Box>
          )}
        </Box>

        {/* Video Info Section */}
          <Box onClick={() => {
            console.log('📄 Video info clicked for:', result.video?.id);
            // Store segments in sessionStorage to pass to video page
            if (result.segments && result.segments.length > 0) {
              sessionStorage.setItem('searchSegments', JSON.stringify(result.segments));
              console.log('✅ Stored', result.segments.length, 'segments');
            }
            if (query) {
              sessionStorage.setItem('searchQuery', query);
            }
            console.log('🚀 Navigating to video page');
            router.get(route('user.video.page', { id: result.video?.id }));
          }} sx={{ flex: 1, display: 'flex', flexDirection: 'column', gap: { xs: 0.5, sm: 1 }, justifyContent: 'center', cursor: 'pointer' }}>
            <Typography sx={{ color: 'white', fontWeight: 600, fontSize: { xs: '0.95rem', sm: '1rem', md: '1.1rem' } }}>
              {highlightText(result.video?.title || result.video?.filename || 'Untitled Video', query)}
          </Typography>
            <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '0.8rem', sm: '0.85rem', md: '0.95rem' }, fontWeight: 500 }}>
            YouTube - Pakistan Matters
            <span style={{ marginLeft: 8, color: '#9CA3AF', fontWeight: 400 }}>
                {result.video?.video_created_at ? formatDate(result.video.video_created_at) : formatDate(result.video?.created_at)}
            </span>
          </Typography>

            {/* Match type chips */}
            {result.match_types && result.match_types.length > 0 && (
              <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5, mt: 0.5 }}>
                {result.match_types.filter(t => !t.startsWith('matched_in_')).map((type, i) => {
                    const chipConfig = {
                    'semantic': { label: 'AI Meaning Match', color: '#3B82F6', bg: 'rgba(59,130,246,0.15)' },
                    'keyword': { label: 'Exact Word Match', color: '#F59E0B', bg: 'rgba(245,158,11,0.15)' },
                    'title_match': { label: 'Matched in Title', color: '#22C55E', bg: 'rgba(34,197,94,0.15)' },
                    'tag_match': { label: 'TAG MATCH', color: '#22C55E', bg: 'rgba(34,197,94,0.15)' },
                    'speaker_filter': { label: 'Speaker Match', color: '#A855F7', bg: 'rgba(168,85,247,0.15)' },
                    'llm_reranked': { label: 'AI Verified', color: '#EC4899', bg: 'rgba(236,72,153,0.15)' },
                    'simple_speaker_filter': { label: 'Speaker Filter', color: '#A855F7', bg: 'rgba(168,85,247,0.15)' },
                    'simple_title_filter': { label: 'Title Filter', color: '#22C55E', bg: 'rgba(34,197,94,0.15)' },
                    'simple_date_filter': { label: 'Date Filter', color: '#F59E0B', bg: 'rgba(245,158,11,0.15)' },
                    'simple_language_filter': { label: 'Language Filter', color: '#06B6D4', bg: 'rgba(6,182,212,0.15)' },
                    'simple_video_filter': { label: 'Video Filter', color: '#8B5CF6', bg: 'rgba(139,92,246,0.15)' },
                    'simple_summary_filter': { label: 'Summary Filter', color: '#EC4899', bg: 'rgba(236,72,153,0.15)' },
                    'simple_text_filter': { label: 'Text Filter', color: '#F97316', bg: 'rgba(249,115,22,0.15)' },
                    'incremental_lexical_backstop': { label: 'Deep Search', color: '#10B981', bg: 'rgba(16,185,129,0.15)' },
                    'short_query_lexical_hit': { label: 'Exact Match', color: '#8B5CF6', bg: 'rgba(139,92,246,0.15)' },
                  };
                  const cfg = chipConfig[type] || { label: type.replace(/_/g, ' '), color: '#9CA3AF', bg: 'rgba(156,163,175,0.15)' };
                  return (
                    <Box key={i} sx={{ px: 1, py: 0.25, borderRadius: '6px', bgcolor: cfg.bg, border: `1px solid ${cfg.color}30` }}>
                      <Typography sx={{ fontSize: '0.65rem', fontWeight: 600, color: cfg.color, textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                        {cfg.label}
                      </Typography>
                    </Box>
                  );
                })}
                {/* Show matched fields */}
                {result.matched_fields && result.matched_fields.length > 0 && result.matched_fields.map((field, i) => {
                  const fieldLabels = {
                    'video_title': 'Matched in Title',
                    'manual_tags': 'TAG MATCH',
                    'text': 'Matched in Spoken Text',
                    'speaker': 'Matched Speaker',
                    'diarization_speaker': 'Matched Speaker',
                    'Summary_en': 'Found in English Summary',
                    'summary_en': 'Found in English Summary',
                    'Summary_ur': 'Found in Urdu Summary',
                    'summary_ur': 'Found in Urdu Summary',
                  };
                  const label = fieldLabels[field] || field.replace(/_/g, ' ');
                  return (
                    <Box key={`f-${i}`} sx={{ px: 1, py: 0.25, borderRadius: '6px', bgcolor: 'rgba(255,255,255,0.05)', border: '1px solid rgba(255,255,255,0.1)' }}>
                      <Typography sx={{ fontSize: '0.65rem', fontWeight: 500, color: '#D1D5DB' }}>
                        {label}
                      </Typography>
                    </Box>
                  );
                })}
              </Box>
            )}
          </Box>
        </Box>
        {/* Segment previews — show top matching segments with speaker & time */}
        {/* For title-only matches (is_video_only), show a note instead of segment details */}
        {result.is_video_only ? (
          <Box sx={{ mt: 1, px: 1.5, py: 0.75, borderRadius: 1.5, bgcolor: 'rgba(34,197,94,0.08)', border: '1px solid rgba(34,197,94,0.2)' }}>
            <Typography sx={{ color: '#22C55E', fontSize: '0.85rem', fontWeight: 500 }}>
              {result.match_types && result.match_types.includes('tag_match') ? 'Video tags match your search query' : 'Video title matches your search query'}
            </Typography>
          </Box>
        ) : result.segments && result.segments.length > 0 && (
          <Box sx={{ mt: 1, display: 'flex', flexDirection: 'column', gap: 0.75 }}>
              {(expandedVideoSegments[result.video?.id] ? result.segments : result.segments.slice(0, 3)).filter(seg => {
                // Hide segments with no real time range (title matches, missing data, etc.)
                if (!seg.start_time && !seg.end_time) return false;
                return true;
              }).map((seg, segIdx) => {
                // Determine match field styling
                const matchFieldConfig = {
                  'text': { label: '📝 Spoken Text Match', color: '#F97316', border: '#F97316' },
                  'video_title': { label: '🏷️ Title Match', color: '#22C55E', border: '#22C55E' },
                  'speaker': { label: '🎤 Speaker Match', color: '#A855F7', border: '#A855F7' },
                  'diarization_speaker': { label: '🎤 Speaker Match', color: '#A855F7', border: '#A855F7' },
                  'semantic_vector': { label: '🧠 AI Meaning Match', color: '#3B82F6', border: '#3B82F6' },
                  'date_filter': { label: '📅 Date Filter', color: '#F59E0B', border: '#F59E0B' },
                  'Summary_en': { label: '📑 Found in English Summary', color: '#EC4899', border: '#EC4899' },
                  'summary_en': { label: '📑 Found in English Summary', color: '#EC4899', border: '#EC4899' },
                  'Summary_ur': { label: '📑 Found in Urdu Summary', color: '#EC4899', border: '#EC4899' },
                  'summary_ur': { label: '📑 Found in Urdu Summary', color: '#EC4899', border: '#EC4899' },
                };
                const fieldKey = seg.matched_field || (seg.match_types?.find(t => t === 'semantic') ? 'semantic_vector' : 'text');
                const fieldStyle = matchFieldConfig[fieldKey] || { label: '🔍 Match', color: '#9CA3AF', border: '#9CA3AF' };

                return (
                  <Box key={segIdx} sx={{
                    display: 'flex', alignItems: 'flex-start', gap: 1,
                    px: 1.5, py: 0.75, borderRadius: 1.5,
                    bgcolor: `${fieldStyle.color}08`,
                    borderLeft: `3px solid ${fieldStyle.border}`,
                  }}>
                    <Box sx={{ minWidth: 0, flex: 1 }}>
                      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 0.25, flexWrap: 'wrap' }}>
                        {/* Match field label */}
                        <Box sx={{ px: 0.75, py: 0.15, borderRadius: '4px', bgcolor: `${fieldStyle.color}20` }}>
                          <Typography sx={{ fontSize: '0.6rem', fontWeight: 700, color: fieldStyle.color, letterSpacing: '0.3px' }}>
                            {fieldStyle.label}
                          </Typography>
                        </Box>
                        {seg.speaker && (
                          <Typography sx={{ fontSize: '0.7rem', fontWeight: 600, color: '#A855F7' }}>
                            {seg.speaker}
                          </Typography>
                        )}
                        <Typography sx={{ fontSize: '0.65rem', color: '#6B7280' }}>
                          {Math.floor((seg.start_time || 0) / 60)}:{String(Math.floor((seg.start_time || 0) % 60)).padStart(2, '0')}
                          {' → '}
                          {Math.floor((seg.end_time || 0) / 60)}:{String(Math.floor((seg.end_time || 0) % 60)).padStart(2, '0')}
                        </Typography>
                      </Box>
                      {(() => {
                        const segKey = `${result.video?.id}-${segIdx}`;
                        const isTextExpanded = expandedSegmentTexts[segKey];
                        const fullText = seg.text || '';
                        const isLong = fullText.length > 180;
                        const displayText = isLong && !isTextExpanded ? fullText.substring(0, 180) + '...' : fullText;
                        return (
                          <Box>
                            <Typography sx={{ color: '#D1D5DB', fontSize: { xs: '0.8rem', sm: '0.85rem' }, fontWeight: 400, lineHeight: 1.4 }}>
                              {highlightText(displayText, query)}
                            </Typography>
                            {isLong && (
                              <Typography
                                onClick={(e) => { e.stopPropagation(); toggleSegmentText(segKey); }}
                                sx={{ color: '#3B82F6', fontSize: '0.7rem', cursor: 'pointer', mt: 0.25, '&:hover': { textDecoration: 'underline' } }}
                              >
                                {isTextExpanded ? '▲ Show less' : '▼ Show full text'}
                              </Typography>
                            )}
                          </Box>
                        );
                      })()}
                    </Box>
                  </Box>
                );
              })}
              {result.segments.length > 3 && (
                <Typography
                  onClick={(e) => {
                    e.stopPropagation();
                    toggleExpandedSegments(result.video?.id);
                  }}
                  sx={{
                    fontSize: '0.75rem',
                    color: '#EE1D52',
                    pl: 1.5,
                    cursor: 'pointer',
                    '&:hover': { textDecoration: 'underline' }
                  }}
                >
                  {expandedVideoSegments[result.video?.id]
                    ? '− Show less'
                    : `+${result.segments.length - 3} more matching segments`
                  }
                </Typography>
              )}
            </Box>
        )}
        <Box sx={{
          display: 'flex',
          flexDirection: { xs: 'column', sm: 'row' },
          alignItems: { xs: 'flex-start', sm: 'center' },
          justifyContent: 'flex-end',
          mt: 1,
          gap: { xs: 2, sm: 0 },
        }}>
          {/* Actions */}
          <Box sx={{ display: 'flex', gap: { xs: 2, sm: 3, md: 4 }, mr: { xs: 0, sm: 2, md: 4 }, width: { xs: '100%', sm: 'auto' }, justifyContent: { xs: 'space-around', sm: 'flex-start' } }}>
            <Box sx={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: { xs: 0.25, sm: 0.5 } }}>
              <IconButton onClick={(e) => {
                e.stopPropagation();
                console.log('👁️ Preview clicked for:', {
                  video_id: result.video?.id,
                  segments: result.segments,
                  segments_count: result.segments?.length
                });
                // Store segments in sessionStorage to pass to video page
                if (result.segments && result.segments.length > 0) {
                  sessionStorage.setItem('searchSegments', JSON.stringify(result.segments));
                } else {
                  console.warn('⚠️ No segments to store');
                }
                if (query) {
                  sessionStorage.setItem('searchQuery', query);
                }
                router.get(route('user.video.page', { id: result.video?.id }));
              }} sx={{ color: 'white', bgcolor: '#232323', borderRadius: '50%', p: { xs: 0.75, sm: 1 } }}>
                <PreviewIcon sx={{ fontSize: { xs: '14px', sm: '16px' } }} />

              </IconButton>
              <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '0.7rem', sm: '0.75rem', md: '0.85rem' }, display: { xs: 'none', sm: 'block' } }}>{t('searchedPage.seePreview')}</Typography>
            </Box>
            <Box sx={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: { xs: 0.25, sm: 0.5 } }}>
              <IconButton
                onClick={(e) => {
                  console.log('Download button clicked for video ID:', result.video?.id);
                  e.stopPropagation();
                  console.log('Download button clicked for video ID:', result.video?.id);
                  handleDownloadVideo(result.video);
                }}
                disabled={downloadingVideoId === result.video?.id}
                sx={{
                  color: '#22C55E',
                  bgcolor: '#232323',
                  borderRadius: '50%',
                  p: { xs: 0.75, sm: 1 },
                  '&:disabled': {
                    opacity: 0.5,
                    cursor: 'not-allowed'
                  }
                }}
              >
                {downloadingVideoId === result.video?.id ? (
                  <CircularProgress size={14} sx={{ color: '#22C55E' }} />
                ) : (
                    <DownloadIcon sx={{ fontSize: { xs: '14px', sm: '16px' } }} />
                )}
              </IconButton>
              <Typography sx={{ color: '#22C55E', fontSize: { xs: '0.7rem', sm: '0.75rem', md: '0.85rem' }, display: { xs: 'none', sm: 'block' } }}>
                {downloadingVideoId === result.video?.id ? t('searchedPage.downloading') || 'Downloading...' : t('searchedPage.download')}
              </Typography>
            </Box>
            <Box sx={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: { xs: 0.25, sm: 0.5 } }}>
              <IconButton
                onClick={(e) => handleShareClick(e, result.video)}
                sx={{ color: '#EE1D52', bgcolor: '#232323', borderRadius: '50%', p: { xs: 0.75, sm: 1 } }}
              >
                <ShareIcon sx={{ fontSize: { xs: '14px', sm: '16px' } }} />
              </IconButton>
              <Typography sx={{ color: '#EE1D52', fontSize: { xs: '0.7rem', sm: '0.75rem', md: '0.85rem' }, display: { xs: 'none', sm: 'block' } }}>{t('searchedPage.share')}</Typography>
            </Box>
          </Box>
        </Box>
      </Box>
    );
  })
  )}
            </Box>

            {/* Incremental Loading Controls */}
            <Box sx={{ display: 'flex', flexDirection: 'column', alignItems: 'center', mt: { xs: 3, sm: 4 }, gap: 2 }}>
              {/* Result count indicator */}
              <Typography sx={{ color: '#9CA3AF', fontSize: '0.875rem', textAlign: 'center' }}>
                {loadedResults.length > 0 ? (
                  <>
                    Showing {visibilityWindowStart + 1}-{Math.min(visibilityWindowStart + VISIBILITY_WINDOW_SIZE, loadedResults.length)} of{' '}
                    {totalAvailable > 0 ? totalAvailable : loadedResults.length} results
                    {totalAvailable > loadedResults.length && ` (${loadedResults.length} loaded)`}
                  </>
                ) : (
                  'No results'
                )}
              </Typography>

              {/* View Previous button - show when window is shifted forward */}
              {visibilityWindowStart > 0 && (
                <Button
                  variant="outlined"
                  onClick={handleViewPrevious}
                  sx={{
                    color: '#9CA3AF',
                    borderColor: '#4B5563',
                    px: { xs: 3, sm: 4 },
                    py: { xs: 1, sm: 1.5 },
                    borderRadius: '8px',
                    textTransform: 'none',
                    fontSize: { xs: '0.9rem', sm: '1rem' },
                    fontWeight: 500,
                    '&:hover': {
                      borderColor: rose,
                      bgcolor: 'rgba(244, 63, 94, 0.1)'
                    }
                  }}
                >
                  ← View Previous {VISIBILITY_WINDOW_SIZE}
                </Button>
              )}

              <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', justifyContent: 'center' }}>
                {/* View Next Window button - show when there are more loaded results not in current window */}
                {visibilityWindowStart + VISIBILITY_WINDOW_SIZE < loadedResults.length && (
                  <Button
                    variant="outlined"
                    onClick={handleViewNextWindow}
                    sx={{
                      color: '#9CA3AF',
                      borderColor: '#4B5563',
                      px: { xs: 3, sm: 4 },
                      py: { xs: 1, sm: 1.5 },
                      borderRadius: '8px',
                      textTransform: 'none',
                      fontSize: { xs: '0.9rem', sm: '1rem' },
                      fontWeight: 500,
                      '&:hover': {
                        borderColor: rose,
                        bgcolor: 'rgba(244, 63, 94, 0.1)'
                      }
                    }}
                  >
                    View Next {Math.min(VISIBILITY_WINDOW_SIZE, loadedResults.length - (visibilityWindowStart + VISIBILITY_WINDOW_SIZE))} →
                  </Button>
                )}

                {/* All results loaded indicator */}
                {!hasMore && loadedResults.length > 0 && (
                  <Typography sx={{ color: '#6B7280', fontSize: '0.9rem', mt: 1 }}>
                    All {loadedResults.length} results loaded
                  </Typography>
                )}

                {/* View More button - load new results from API */}
                {hasMore && (
                  <Button
                    variant="contained"
                    onClick={handleLoadMore}
                    disabled={isLoadingMore}
                    sx={{
                      bgcolor: rose,
                      color: 'white',
                      px: { xs: 3, sm: 4 },
                      py: { xs: 1, sm: 1.5 },
                      borderRadius: '8px',
                      textTransform: 'none',
                      fontSize: { xs: '0.9rem', sm: '1rem' },
                      fontWeight: 600,
                      '&:hover': {
                        bgcolor: '#e11d48'
                      },
                      '&.Mui-disabled': {
                        bgcolor: '#4B5563',
                        color: '#9CA3AF'
                      }
                    }}
                  >
                    {isLoadingMore ? (
                      <>
                        <CircularProgress size={20} sx={{ color: 'white', mr: 1 }} />
                        Loading...
                      </>
                    ) : (
                      `View More Results (10)`
                    )}
                  </Button>
                )}
              </Box>

              {/* Progress indicator when loading */}
              {isLoadingMore && (
                <Box sx={{ width: '100%', maxWidth: 400 }}>
                  <LinearProgress sx={{ bgcolor: '#374151', '& .MuiLinearProgress-bar': { bgcolor: rose } }} />
                </Box>
              )}
            </Box>
          </>
        ) : (
          <>
            {/* Search History */}
            <Typography sx={{ color: 'white', fontWeight: 600, fontSize: '1.25rem', mb: 3 }}>
              {t('searchedPage.searchHistory') || 'Search History'}
            </Typography>

            {loadingHistory ? (
              <Box sx={{ textAlign: 'center', py: 8 }}>
                <CircularProgress sx={{ color: rose }} />
                <Typography sx={{ color: '#9CA3AF', mt: 2 }}>{t('common.loading')}</Typography>
              </Box>
            ) : searchHistory.length === 0 ? (
              <Box sx={{ textAlign: 'center', py: 8 }}>
                <Typography sx={{ color: '#9CA3AF', fontSize: '1.2rem' }}>
                  {t('searchedPage.noSearchHistory') || 'No search history found'}
                </Typography>
              </Box>
            ) : (
              <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2, position: 'relative' }}>
                {/* Loading overlay for history search */}
                {searchingFromHistory && (
                  <Box
                    sx={{
                      position: 'absolute',
                      top: 0,
                      left: 0,
                      right: 0,
                      bottom: 0,
                      bgcolor: 'rgba(0, 0, 0, 0.7)',
                      display: 'flex',
                      flexDirection: 'column',
                      alignItems: 'center',
                      justifyContent: 'center',
                      zIndex: 10,
                      borderRadius: 3,
                    }}
                  >
                    <CircularProgress size={48} sx={{ color: rose, mb: 2 }} />
                    <Typography sx={{ color: 'white', fontSize: '1rem', fontWeight: 600 }}>
                      {t('searchedPage.searchingHistory') || 'Searching...'}
                    </Typography>
                    <Typography sx={{ color: '#9CA3AF', fontSize: '0.85rem', mt: 1 }}>
                      {t('searchedPage.pleaseWait') || 'Please wait while we fetch results'}
                    </Typography>
                  </Box>
                )}

                {searchHistory.map((item, idx) => {
                  const searchType = item.query ? 'semantic' : (item.word ? 'simple' : 'speaker');
                  const searchText = item.query || item.word || item.speaker;

                  // Safe date handling - check if created_at exists and is valid
                  let formattedDate = 'Unknown date';
                  let formattedTime = '';
                  if (item.created_at) {
                    try {
                      const searchDate = new Date(item.created_at);
                      if (!isNaN(searchDate.getTime())) {
                        // Valid date
                        formattedDate = searchDate.toLocaleDateString();
                        formattedTime = searchDate.toLocaleTimeString();
                      }
                    } catch (e) {
                      console.warn('Invalid date format for search history:', item.created_at);
                    }
                  }

                  return (
                    <Box
                      key={item.id || idx}
                      sx={{
                        bgcolor: '#161616',
                        p: { xs: 2, sm: 2.5, md: 3 },
                        borderRadius: { xs: 2, sm: 3 },
                      }}
                    >
                      <Box sx={{ display: 'flex', flexDirection: { xs: 'column', sm: 'row' }, justifyContent: 'space-between', alignItems: { xs: 'flex-start', sm: 'flex-start' }, gap: { xs: 1, sm: 0 } }}>
                        <Box sx={{ flex: 1 }}>
                          <Box sx={{ display: 'flex', alignItems: 'center', gap: { xs: 1, sm: 2 }, mb: 1, flexWrap: 'wrap' }}>
                            <Typography sx={{ color: 'white', fontWeight: 600, fontSize: { xs: '0.95rem', sm: '1rem', md: '1.1rem' } }}>
                              "{searchText}"
                            </Typography>
                            <Box sx={{
                              bgcolor: searchType === 'semantic' ? '#3B82F6' : (searchType === 'simple' ? '#10B981' : '#F59E0B'),
                              px: 1.5,
                              py: 0.5,
                              borderRadius: '12px'
                            }}>
                              <Typography sx={{ color: 'white', fontSize: '0.75rem', fontWeight: 600 }}>
                                {searchType === 'semantic' ? '🧠 ' + t('searchedPage.semantic') :
                                  searchType === 'simple' ? '📝 ' + t('searchedPage.simple') :
                                    '🎤 ' + t('searchedPage.speaker')}
                              </Typography>
                            </Box>
                          </Box>
                          <Box sx={{ display: 'flex', alignItems: 'center', gap: 3, mt: 2 }}>
                            <Typography sx={{ color: '#9CA3AF', fontSize: '0.9rem' }}>
                              📅 {formattedDate}
                            </Typography>
                            <Typography sx={{ color: '#9CA3AF', fontSize: '0.9rem' }}>
                              🕐 {formattedTime}
                            </Typography>
                            {(item.videos_count > 0 || item.results_count > 0) && (
                              <Typography sx={{ color: '#22C55E', fontSize: '0.9rem', fontWeight: 600 }}>
                                {item.videos_count || item.results_count} {(item.videos_count || item.results_count) === 1 ? t('searchedPage.result') : t('searchedPage.results')}
                              </Typography>
                            )}
                          </Box>
                        </Box>
                      </Box>
                    </Box>
                  );
                })}
              </Box>
            )}
          </>
        )}
      </Box>

      {/* Share Menu */}
      <Menu
        anchorEl={shareMenuAnchor}
        open={Boolean(shareMenuAnchor)}
        onClose={handleShareMenuClose}
        PaperProps={{
          sx: {
            bgcolor: '#232323',
            borderRadius: 2,
            mt: 1,
            minWidth: 200,
            '& .MuiMenuItem-root': {
              color: 'white',
              py: 1.5,
              px: 2,
              '&:hover': {
                bgcolor: '#333',
              },
            },
          },
        }}
      >
        <MenuItem onClick={handleShareWhatsApp}>
          <ListItemIcon>
            <WhatsAppIcon sx={{ color: '#25D366', fontSize: 20 }} />
          </ListItemIcon>
          <ListItemText>{t('searchedPage.shareWhatsApp') || 'Share on WhatsApp'}</ListItemText>
        </MenuItem>
        <MenuItem onClick={handleShareTwitter}>
          <ListItemIcon>
            <TwitterIcon sx={{ color: '#1DA1F2', fontSize: 20 }} />
          </ListItemIcon>
          <ListItemText>{t('searchedPage.shareTwitter') || 'Share on Twitter'}</ListItemText>
        </MenuItem>
        <MenuItem onClick={handleShareFacebook}>
          <ListItemIcon>
            <FacebookIcon sx={{ color: '#4267B2', fontSize: 20 }} />
          </ListItemIcon>
          <ListItemText>{t('searchedPage.shareFacebook') || 'Share on Facebook'}</ListItemText>
        </MenuItem>
        <MenuItem onClick={handleCopyLink}>
          <ListItemIcon>
            <ContentCopyIcon sx={{ color: '#9CA3AF', fontSize: 20 }} />
          </ListItemIcon>
          <ListItemText>{t('searchedPage.copyLink') || 'Copy Link'}</ListItemText>
        </MenuItem>
      </Menu>

      {/* Filter Modal */}
      <Modal open={openFilters} onClose={() => setOpenFilters(false)}>
        <Box sx={{
          position: 'absolute',
          top: '50%',
          left: '50%',
          transform: 'translate(-50%, -50%)',
          bgcolor: '#232323',
          borderRadius: 3,
          boxShadow: 24,
          p: 3,
          minWidth: 340,
          maxWidth: 400,
        }}>
          <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 2 }}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <Typography sx={{ color: 'white', fontWeight: 600, fontSize: '1.1rem' }}>Filters</Typography>
              <FilterListIcon sx={{ color: 'white', fontSize: 20 }} />
            </Box>
            <IconButton onClick={() => setOpenFilters(false)} sx={{ color: '#9CA3AF' }}>
              <CloseIcon />
            </IconButton>
          </Box>
          <Box sx={{ display: 'flex', gap: 4 }}>
            {/* Upload Date */}
            <Box>
              <Typography sx={{ color: '#9CA3AF', fontWeight: 500, mb: 1 }}>Upload Date</Typography>
              {uploadDateOptions.map(option => (
                <Typography
                  key={option}
                  sx={{
                    color: selectedUploadDate === option ? 'white' : '#9CA3AF',
                    fontWeight: selectedUploadDate === option ? 700 : 400,
                    mb: 0.5,
                    cursor: 'pointer',
                    transition: 'color 0.2s',
                  }}
                  onClick={() => setSelectedUploadDate(option)}
                >
                  {option}
                </Typography>
              ))}
            </Box>
            {/* Duration */}
            <Box>
              <Typography sx={{ color: '#9CA3AF', fontWeight: 500, mb: 1 }}>Duration</Typography>
              {durationOptions.map(option => (
                <Typography
                  key={option}
                  sx={{
                    color: selectedDuration === option ? 'white' : '#9CA3AF',
                    fontWeight: selectedDuration === option ? 700 : 400,
                    mb: 0.5,
                    cursor: 'pointer',
                    transition: 'color 0.2s',
                  }}
                  onClick={() => setSelectedDuration(option)}
                >
                  {option}
                </Typography>
              ))}
            </Box>
            {/* Duration/Other */}
            <Box>
              <Typography sx={{ color: '#9CA3AF', fontWeight: 500, mb: 1 }}>Duration</Typography>
              {otherOptions.map(option => (
                <Typography
                  key={option}
                  sx={{
                    color: selectedOther === option ? 'white' : '#9CA3AF',
                    fontWeight: selectedOther === option ? 700 : 400,
                    mb: 0.5,
                    cursor: 'pointer',
                    transition: 'color 0.2s',
                  }}
                  onClick={() => setSelectedOther(option)}
                >
                  {option}
                </Typography>
              ))}
            </Box>
          </Box>
        </Box>
      </Modal>
    </UserAuthenticateLayout>
  );
}
