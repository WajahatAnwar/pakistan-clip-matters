import React, { useEffect, useState, useCallback, useRef } from 'react';
import { AppBar, Toolbar, IconButton, Typography, Box, Button, Avatar, Menu, MenuItem, TextField, InputAdornment, Switch, Snackbar, Alert, CircularProgress, Select, FormControl, ToggleButtonGroup, ToggleButton, Paper, List, ListItemButton, ListItemText, Tooltip } from '@mui/material';
import HafizNaeemImage from '@/Images/Hafiz_Naeem_ur_Rehman.webp.png';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import { LocalizationProvider } from '@mui/x-date-pickers/LocalizationProvider';
import { AdapterDayjs } from '@mui/x-date-pickers/AdapterDayjs';
import MenuIcon from '@mui/icons-material/Menu';
import LogoutIcon from '@mui/icons-material/Logout';
import { usePage, Link, router } from '@inertiajs/react';
import '../../css/style.css';
import MainLogo from '@/Images/main-logo.svg';
import SearchIcon from '@mui/icons-material/Search';
import MicIcon from '@mui/icons-material/Mic';
import ClearIcon from '@mui/icons-material/Close';
import PersonIcon from '@mui/icons-material/Person';
import PsychologyIcon from '@mui/icons-material/Psychology';
import TuneIcon from '@mui/icons-material/Tune';
import VideoFileIcon from '@mui/icons-material/VideoFile';
import SpeechRecognition, { useSpeechRecognition } from 'react-speech-recognition';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import Swal from 'sweetalert2';
import { useTranslation } from 'react-i18next';
import dayjs from 'dayjs';

export default function UserAuthenticateLayout({ children }) {
  const { t } = useTranslation();
  const user = usePage().props.auth?.user;
  const MAX_QUERY_LENGTH = 1000;
  const [anchorEl, setAnchorEl] = useState(null);
  const open = Boolean(anchorEl);

  const [searchText, setSearchText] = useState('');
  const [isHafizNaeemOnly, setIsHafizNaeemOnly] = useState(false);
  const [isSearching, setIsSearching] = useState(false);

  // Search mode: 'semantic' (AI/vector) or 'simple' (structured filters)
  const [searchMode, setSearchMode] = useState('semantic');
  // Active filter type for simple mode (only one at a time)
  const [activeFilterType, setActiveFilterType] = useState(null);

  // Date filter states
  const [dateFilterType, setDateFilterType] = useState('none');
  const [selectedDate, setSelectedDate] = useState(null);

  // Language filter state
  const [selectedLanguage, setSelectedLanguage] = useState('');

  // Video filename suggestions state
  const [suggestions, setSuggestions] = useState([]);
  const [showSuggestions, setShowSuggestions] = useState(false);
  const suggestionsTimerRef = useRef(null);
  const layoutSearchBoxRef = useRef(null);
  const suggestionSelectedRef = useRef(false);

  // Ref to store abort controller for cancelling searches
  const abortControllerRef = useRef(null);
  // Ref to skip abort when the state change is from an intentional search trigger
  const intentionalSearchRef = useRef(false);
  // Ref to track the last search query to detect query changes
  const lastSearchQueryRef = useRef('');

  // Fetch video filename suggestions as user types
  useEffect(() => {
    if (suggestionsTimerRef.current) clearTimeout(suggestionsTimerRef.current);

    // Skip fetch when text was programmatically set from a suggestion click
    if (suggestionSelectedRef.current) {
      suggestionSelectedRef.current = false;
      return;
    }

    if (!searchText.trim() || searchText.trim().length < 1) {
      setSuggestions([]);
      setShowSuggestions(false);
      return;
    }

    suggestionsTimerRef.current = setTimeout(async () => {
      try {
        const res = await fetch(route('user.video.suggestions') + '?q=' + encodeURIComponent(searchText.trim()));
        if (res.ok) {
          const data = await res.json();
          setSuggestions(data);
          setShowSuggestions(data.length > 0);
        }
      } catch { /* ignore */ }
    }, 300);

    return () => {
      if (suggestionsTimerRef.current) clearTimeout(suggestionsTimerRef.current);
    };
  }, [searchText]);

  // Close suggestions when clicking outside
  useEffect(() => {
    const handleClickOutside = (e) => {
      if (layoutSearchBoxRef.current && !layoutSearchBoxRef.current.contains(e.target)) {
        setShowSuggestions(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // Cleanup: abort any ongoing searches when component unmounts
  useEffect(() => {
    return () => {
      if (abortControllerRef.current) {
        console.log('🛑 Aborting search on unmount');
        abortControllerRef.current.abort();
        abortControllerRef.current = null;
      }
    };
  }, []);

  // Snackbar state for notifications
  const [snackbar, setSnackbar] = useState({
    open: false,
    message: '',
    severity: 'info' // 'success', 'error', 'warning', 'info'
  });

  // Abort ongoing search when query or filters change (but NOT when change comes from intentional search trigger)
  useEffect(() => {
    if (intentionalSearchRef.current) {
      intentionalSearchRef.current = false;
      return;
    }

    if (isSearching && abortControllerRef.current) {
      console.log('🛑 Search parameters changed, aborting current search');
      abortControllerRef.current.abort();
      abortControllerRef.current = null;
      setIsSearching(false);
    }
  }, [searchText, searchMode, activeFilterType, dateFilterType, selectedDate, selectedLanguage]);

  // Disable Hafiz Naeem toggle when switching to simple mode with active filter
  useEffect(() => {
    if (searchMode === 'simple' && activeFilterType && isHafizNaeemOnly) {
      console.log('🔄 Auto-disabling Hafiz Naeem toggle - simple mode active');
      setIsHafizNaeemOnly(false);
    }
  }, [searchMode, activeFilterType]);

  // Sync search mode with current page props (e.g. after SearchPage navigates here)
  // NOTE: We intentionally do NOT sync pageQuery into the search bar — the layout
  // search bar should start empty and only reflect what the user types here.
  const pageProps = usePage().props;
  const pageSearchMode = pageProps.searchMode;
  const pageUrl = pageProps.ziggy?.location || window.location.pathname;
  useEffect(() => {
    // Sync search mode only when page explicitly provides it
    if (pageSearchMode) {
      if (pageSearchMode === 'search' || pageSearchMode === 'semantic') {
        setSearchMode('semantic');
        setActiveFilterType(null);
      } else if (pageSearchMode === 'speaker') {
        setSearchMode('semantic');
        setActiveFilterType(null);
        setIsHafizNaeemOnly(true);
      } else if (pageSearchMode.startsWith('simple_')) {
        const filterType = pageSearchMode.replace('simple_', '');
        setSearchMode('simple');
        setActiveFilterType(filterType || null);
        setIsHafizNaeemOnly(false);
      }
    }
  }, [pageSearchMode, pageUrl]);

  // Speech Recognition Hook
  const {
    transcript,
    listening,
    browserSupportsSpeechRecognition,
    resetTranscript
  } = useSpeechRecognition();

  // Update search text when transcript changes
  useEffect(() => {
    if (transcript) {
      setSearchText(transcript);
    }
  }, [transcript]);

  const handleClick = (event) => {
    setAnchorEl(event.currentTarget);
  };

  const handleMenuClose = () => {
    setAnchorEl(null);
  };

  const handleLogout = () => {
    handleMenuClose();
    router.post(route('logout'));
  };

  // Navigate to search page (for logo click or search history)
  const handleNavigateToSearch = () => {
    router.get(route('user.search.page'));
  };

  const handleSnackbarClose = (event, reason) => {
    if (reason === 'clickaway') {
      return;
    }
    setSnackbar({ ...snackbar, open: false });
  };

  // Handle microphone button click
  const handleMicClick = () => {
    if (!browserSupportsSpeechRecognition) {
      alert('Your browser does not support speech recognition. Please try Chrome or Edge.');
      return;
    }


    if (listening) {
      SpeechRecognition.stopListening();
    } else {
      resetTranscript();
      setSearchText('');
      SpeechRecognition.startListening({ 
        language: 'ur-PK',
        continuous: false
      }).catch((error) => {
        console.error('Speech recognition error:', error);
      });
    }
  };

  // Memoize the fetch function — unified search that combines semantic + keyword + exact match
  const fetchSearchResults = useCallback(async (signal, query, speakerFilterActive) => {
    console.log('🔍 Starting search with:', { query, speakerFilterActive, searchMode, activeFilterType, dateFilterType, selectedDate });

    try {
      let requestBody = {
        min_score: 0.50,
        top_k: 200,
        page: 1,
        per_page: 10,
        use_incremental: true, // Enable cursor-based incremental pagination
        batch_size: 10, // Request 10 videos per batch for consistent pagination
      };

      // Helper: split query into clean words (used by both semantic and speaker search)
      const STOP_WORDS = new Set(['a', 'an', 'the', 'this', 'that', 'these', 'those', 'i', 'me', 'my', 'we', 'us', 'our', 'you', 'your', 'he', 'him', 'his', 'she', 'her', 'it', 'its', 'they', 'them', 'their', 'who', 'what', 'which', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'am', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'shall', 'should', 'may', 'might', 'must', 'can', 'could', 'and', 'or', 'but', 'so', 'if', 'then', 'when', 'where', 'while', 'because', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'up', 'down', 'out', 'about', 'very', 'really', 'just', 'also', 'too', 'even', 'now', 'then', 'here', 'there']);
      const normalizeSearchWord = (word) => {
        const raw = (word || '').trim();
        if (!raw) return '';

        // Unicode-safe normalization: keep letters/numbers/marks from all scripts
        // (including Urdu), while trimming punctuation from edges.
        try {
          const normalized = raw
            .replace(/^[^\p{L}\p{N}\p{M}_]+|[^\p{L}\p{N}\p{M}_]+$/gu, '')
            .toLocaleLowerCase();
          if (normalized) return normalized;
        } catch (e) {
          // Fallback for environments that don't support Unicode property escapes.
        }

        return raw.replace(/^[^\w]+|[^\w]+$/g, '').toLowerCase();
      };

      const splitToCleanWords = (text, minLen = 1) => {
        return text
          .split(/\s+/)
          .map(normalizeSearchWord)
          .filter(w => w.length >= minLen && !STOP_WORDS.has(w))
          .slice(0, 8);
      };

      // ── HAFIZ NAEEM SPEAKER SEARCH (priority — works in any mode) ──
      // FIXED: When custom query is provided with toggle, ONLY search the query
      // Don't add speaker filter - let user search any content with their custom query
      if (speakerFilterActive) {
        requestBody.search_mode = 'semantic';

        // Add date filter if applicable
        if (dateFilterType !== 'none' && selectedDate) {
          const date = dayjs(selectedDate);
          if (dateFilterType === 'year') {
            requestBody.filter_year = date.year();
          } else if (dateFilterType === 'month') {
            requestBody.filter_year = date.year();
            requestBody.filter_month = date.month() + 1;
          } else if (dateFilterType === 'date') {
            requestBody.filter_date = date.format('YYYY-MM-DD');
          }
        }

        // CRITICAL FIX: Only send query/words, NOT speaker filter
        // This allows users to search any content when toggle is active
        if (query) {
          requestBody.query = query;
          requestBody.words = splitToCleanWords(query, 2);
        } else {
          // No custom query - default to searching for Hafiz Naeem speaker segments
          requestBody.speaker = "Hafiz Naeem Ur Rehman";
        }
      // ── SIMPLE MODE ──
      } else if (searchMode === 'simple' && activeFilterType) {
        requestBody.search_mode = 'simple';
        requestBody.filter_type = activeFilterType;

        if (activeFilterType === 'date') {
          if (dateFilterType !== 'none' && selectedDate) {
            const date = dayjs(selectedDate);
            if (dateFilterType === 'year') requestBody.filter_year = date.year();
            else if (dateFilterType === 'month') {
              requestBody.filter_year = date.year();
              requestBody.filter_month = date.month() + 1;
            } else if (dateFilterType === 'date') {
              requestBody.filter_date = date.format('YYYY-MM-DD');
            }
          }
        } else if (activeFilterType === 'speaker') {
          requestBody.speaker = query || '';
        } else if (activeFilterType === 'title') {
          requestBody.title = query || '';
        } else if (activeFilterType === 'summary') {
          requestBody.query = query || '';
        } else if (activeFilterType === 'language') {
          requestBody.language = selectedLanguage || '';
          requestBody.query = query || '';
        } else if (activeFilterType === 'text') {
          requestBody.query = query || '';
        }
      } else {
        // ── SEMANTIC MODE (default) ──
        requestBody.search_mode = 'semantic';

        // Add date filter if applicable
        if (dateFilterType !== 'none' && selectedDate) {
          const date = dayjs(selectedDate);
          if (dateFilterType === 'year') {
            requestBody.filter_year = date.year();
          } else if (dateFilterType === 'month') {
            requestBody.filter_year = date.year();
            requestBody.filter_month = date.month() + 1;
          } else if (dateFilterType === 'date') {
            requestBody.filter_date = date.format('YYYY-MM-DD');
          }
        }

        if (query) {
          requestBody.query = query;
          requestBody.words = splitToCleanWords(query, 2);
        }
      }


      console.log('📤 Request body:', requestBody);
      console.log('📍 Route URL:', route('videos.embeddings.search'));

      const response = await fetch(
        route('videos.embeddings.search'),
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
          },
          signal,
          body: JSON.stringify(requestBody)
        }
      );

      console.log('📥 Response status:', response.status);
      const responseText = await response.text();
      let data = {};
      if (responseText) {
        try {
          data = JSON.parse(responseText);
        } catch (parseError) {
          console.warn('⚠️ Failed to parse search response JSON:', parseError);
        }
      }
      console.log('📦 Search Results:', data);

      if (!response.ok) {
        console.error('❌ Search failed:', data);
        // Extract the most useful error message to show the user
        let errorMessage = 'Search failed. Please try again.';
        if (data.status_code === 401 || response.status === 401) {
          errorMessage = 'Authentication failed. Please refresh and try again.';
        } else if (data.status_code === 429 || response.status === 429) {
          errorMessage = 'Too many searches. Please wait a moment and try again.';
        } else if (data?.errors?.query?.[0]) {
          errorMessage = data.errors.query[0];
        } else if (data.message) {
          // Show the actual error message from the backend (e.g., validation errors)
          errorMessage = data.message;
        } else {
          errorMessage = `Search failed (${response.status}). Please try different keywords.`;
        }

        setSnackbar({
          open: true,
          message: errorMessage,
          severity: 'error'
        });
        setIsSearching(false);
        return;

      }
      
      // Check if no results found
      if (data.success && (!data.grouped_by_video || data.grouped_by_video.length === 0)) {
        console.warn('⚠️ No results found');

        // Navigate to SearchedPage with empty results
        // Store the original request body (without page) for pagination reuse
        const { page: _p, ...searchParamsForPagination } = requestBody;
        router.post(route('searched.page'), {
          videos: [],
          query: query,
          searchMode: data.search_mode === 'simple'
            ? `simple_${activeFilterType || 'unknown'}`
            : (speakerFilterActive ? 'speaker' : 'semantic'),
          totalVideos: 0,
          totalSegments: 0,
          searchMetadata: data.search_metadata || {},
          searchRequestBody: searchParamsForPagination,
          cursor: data.cursor || null,
          search_session_id: data.search_session_id || null,
          is_incremental: data.is_incremental || false,
          pagination: {
            current_page: 1,
            per_page: 10,
            total_pages: 0,
            has_next_page: false,
            has_prev_page: false
          }
        }, { onFinish: () => setIsSearching(false) });
        return;
      }

      // Navigate to SearchedPage with results using POST
      if (data.success && data.grouped_by_video && data.grouped_by_video.length > 0) {
        console.log('✅ Navigating to results page with', data.grouped_by_video.length, 'videos');

        // Store the original request body (without page) for pagination reuse
        const { page: _pg, ...searchParamsForPagination2 } = requestBody;
        const postData = {
          videos: data.grouped_by_video,
          query: query,
          searchMode: data.search_mode === 'simple'
            ? `simple_${activeFilterType || 'unknown'}`
            : (speakerFilterActive ? 'speaker' : 'semantic'),
          totalVideos: data.total_videos,
          totalSegments: data.total_segments,
          searchMetadata: data.search_metadata || {},
          searchRequestBody: searchParamsForPagination2,
          pagination: {
            current_page: data.current_page || 1,
            per_page: data.per_page || 10,
            total_pages: data.total_pages || 1,
            has_next_page: data.has_next_page || false,
            has_prev_page: data.has_prev_page || false
          },
          cursor: data.cursor || null,
          search_session_id: data.search_session_id || null,
          is_incremental: data.is_incremental || false
        };

        console.log('🚀 POST data being sent:', {
          videos_is_array: Array.isArray(postData.videos),
          videos_count: postData.videos.length,
          first_video: postData.videos[0],
          query: postData.query,
          searchMode: postData.searchMode,
          totalVideos: postData.totalVideos,
          totalSegments: postData.totalSegments,
          has_metadata: !!postData.searchMetadata,
          has_pagination: !!postData.pagination
        });

        router.post(route('searched.page'), postData, {
          onFinish: () => {
            setIsSearching(false);
          }
        });
      } else {
        console.warn('⚠️ Unexpected response format');
        setSnackbar({
          open: true,
          message: t('searchPage.noMatchingVideos'),
          severity: 'info'
        });
        setIsSearching(false);
      }

    } catch (error) {
      if (error.name === 'AbortError') {
        console.log('🛑 Search aborted');
        setIsSearching(false);
        return;
      }
      console.error('❌ Search Error:', error);
      setSnackbar({
        open: true,
        message: t('searchPage.searchConnectionError'),
        severity: 'error'
      });
      setIsSearching(false);
    } finally {
      console.log('🏁 Search cleanup complete');
    }
  }, [searchMode, activeFilterType, dateFilterType, selectedDate, selectedLanguage]);

  const handleDateFilterChange = (newType) => {
    setDateFilterType(newType);
    if (newType === 'none') {
      setSelectedDate(null);
    }
  };


  const isNumericQuery = (text) => {
    return /^\d+$/.test(text);
  };

  const isSearchEnabled = () => {
    const trimmedSearch = searchText.trim();
    const hasDateFilter = dateFilterType !== 'none' && selectedDate;

    if (trimmedSearch) {
      if (isNumericQuery(trimmedSearch)) {
        return trimmedSearch.length >= 2;
      }
    }

    if (searchMode === 'simple') {
      if (!activeFilterType) return false;
      if (activeFilterType === 'date') return hasDateFilter;
      if (activeFilterType === 'language') return selectedLanguage !== '';
      return trimmedSearch.length >= 1;
    }

    // Semantic mode
    return (trimmedSearch.length >= 2) || hasDateFilter;
  };

  // Handle Enter key press
  const handleKeyPress = (e) => {
    if (e.key === 'Enter') {
      console.log('⌨️ Enter key pressed');

      const trimmedSearch = searchText.trim();
      const hasDateFilter = dateFilterType !== 'none' && selectedDate;
      const numericSearch = isNumericQuery(trimmedSearch);

      if (trimmedSearch.length > MAX_QUERY_LENGTH) {
        setSnackbar({
          open: true,
          message: t('searchPage.queryTooLong'),
          severity: 'warning'
        });
        return;
      }

      console.log('Search text:', trimmedSearch, 'Length:', trimmedSearch.length, 'Has date filter:', hasDateFilter);

      // Cancel any ongoing search before starting new one
      if (abortControllerRef.current) {
        console.log('🛑 Cancelling previous search before starting new one');
        abortControllerRef.current.abort();
        abortControllerRef.current = null;
      }

      if (
        (
          (numericSearch && trimmedSearch.length >= 2) ||
          (!numericSearch && trimmedSearch.length >= 2) ||
          hasDateFilter
        ) &&
        !isSearching
      ) {
        console.log('✅ Starting search...');
        setIsSearching(true);

        // Create new abort controller for this search
        const abortController = new AbortController();
        abortControllerRef.current = abortController;

        // Reset Hafiz Naeem toggle before every new search so it never bleeds into
        // the next query unless the user explicitly re-enables it.
        // Mark intentional so the abort useEffect doesn't cancel the search we're about to start.
        intentionalSearchRef.current = true;
        setIsHafizNaeemOnly(false);

        // Check if query is about Hafiz Naeem
        const isQueryAboutHafizNaeem = trimmedSearch.toLowerCase().includes('hafiz') &&
          trimmedSearch.toLowerCase().includes('naeem');
        const queryChanged = trimmedSearch !== lastSearchQueryRef.current;

        // Calculate shouldUseHafizFilter BEFORE state update (state update is async)
        let shouldUseHafizFilter = false;
        if (isHafizNaeemOnly) {
          if (queryChanged && !isQueryAboutHafizNaeem) {
            // Query changed to something NOT about Hafiz Naeem - disable filter for THIS search
            console.log('🔄 Auto-disabling Hafiz Naeem filter - query changed to:', trimmedSearch);
            shouldUseHafizFilter = false;  // Don't use filter for THIS search
          } else if (isQueryAboutHafizNaeem) {
            // Query is about Hafiz Naeem - keep filter
            shouldUseHafizFilter = true;
          }
        }

        lastSearchQueryRef.current = trimmedSearch;

        fetchSearchResults(abortController.signal, trimmedSearch, shouldUseHafizFilter);

      } else {

        console.log('❌ Search not triggered');

        // Snackbar messages
        if (numericSearch && trimmedSearch.length < 2) {
          setSnackbar({
            open: true,
            message: t('searchPage.numericSearchMinDigits'),
            severity: 'warning'
        });
      }
        else if (!numericSearch && trimmedSearch.length < 2 && !hasDateFilter) {
          setSnackbar({
            open: true,
            message: t('searchPage.minCharsRequired'),
            severity: 'warning'
          });
        }
        else if (isSearching) {
          setSnackbar({
            open: true,
            message: t('searchPage.searchInProgress'),
            severity: 'info'
          });
        }
      }
    }
  };

  return (
    <Box sx={{ height: '100vh', bgcolor: '#0C0E10', overflow: "auto" }}>
      {/* Header */}
      <AppBar position="static" sx={{ bgcolor: '#232323', boxShadow: 'none' }}>
        <Toolbar sx={{ 
          display: 'flex', 
          flexDirection: { xs: 'column', md: 'row' },
          justifyContent: 'space-between',
          gap: { xs: 1.5, md: 0 },
          py: { xs: 1.5, md: 1 },
          px: { xs: 2, sm: 3 },
          minHeight: { xs: 'auto', md: 64 }
        }}>
          {/* Top Row on Mobile: Logo, Language, Profile */}
          <Box sx={{ 
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            width: { xs: '100%', md: 'auto' }
          }}>
            {/* Logo - clickable to go to search page */}
            <Box
              onClick={handleNavigateToSearch}
              sx={{
                display: { xs: 'flex', sm: 'flex' },
                alignItems: 'center',
                cursor: 'pointer',
                '&:hover': {
                  opacity: 0.8
                }
              }}
            >
              <img 
                src={MainLogo} 
                alt="Pakistan Matters" 
                style={{ width: window.innerWidth < 640 ? 100 : 140 }} 
              />
            </Box>

            {/* Language Switcher & Avatar - visible on mobile in top row */}
            <Box sx={{ 
              display: { xs: 'flex', md: 'none' },
              alignItems: 'center',
              gap: 1.5
            }}>
              <LanguageSwitcher />
              <IconButton 
                onClick={handleClick}
                size="small"
                aria-controls={open ? 'account-menu' : undefined}
                aria-haspopup="true"
                aria-expanded={open ? 'true' : undefined}
                sx={{ padding: 0 }}
              >
                <Avatar 
                  sx={{ 
                    width: 32,
                    height: 32,
                    backgroundColor: 'transparent',
                    border: '2px solid #374151',
                    color: 'white',
                    fontSize: '0.875rem',
                    '&:hover': { borderColor: '#4B5563' }
                  }}
                >
                  {user?.name?.charAt(0) || 'U'}
                </Avatar>
              </IconButton>
            </Box>
          </Box>

          {/* Search Bar - Full width on mobile */}
          <Box sx={{ 
            flex: { xs: 'none', md: 1 },
            width: { xs: '100%', md: 'auto' },
            display: 'flex',
            flexDirection: 'column',
            gap: 1,
            justifyContent: 'center',
            px: { xs: 0, md: 2 }
          }}>
            {/* Main Search Row */}
            <Box sx={{ 
              display: 'flex', 
              gap: 1, 
              alignItems: 'center', 
              justifyContent: { xs: 'center', md: 'center' },
              flexWrap: { xs: 'nowrap', md: 'wrap' },
              position: 'relative',
            }}>
              <Box ref={layoutSearchBoxRef} sx={{ flex: { xs: 1, md: '0 1 500px' }, maxWidth: { xs: 'none', md: 500 }, position: 'relative' }}>
              <TextField
                value={searchText}
                onChange={e => setSearchText(e.target.value)}
                onKeyPress={handleKeyPress}
                placeholder={
                  searchMode === 'simple'
                    ? (activeFilterType === 'speaker' ? (t('searchPage.searchBySpeaker') || 'Speaker name...')
                      : activeFilterType === 'title' ? 'Video title...'
                        : activeFilterType === 'language' ? (t('searchPage.selectLanguage') || 'Select language below...')
                          : activeFilterType === 'summary' ? 'Summary keywords...'
                            : activeFilterType === 'text' ? 'Search transcript text...'
                              : activeFilterType === 'date' ? 'Optional text...'
                                : 'Select filter...')
                    : (isHafizNaeemOnly ? t('searchPage.searchBySpeaker') : t('searchPage.searchByMeaning'))
                }
                variant="outlined"
                size="small"
                  fullWidth
                  sx={{
                  bgcolor: '#2C2C30',
                  borderRadius: '9999px',
                  '& .MuiOutlinedInput-root': {
                    borderRadius: '9999px',
                    color: 'white',
                    paddingRight: { xs: 0.5, sm: 1 },
                    fontSize: { xs: '0.8rem', sm: '0.875rem' },
                    bgcolor: '#2C2C30',
                    '& fieldset': { border: 'none' },
                    '&:hover fieldset': { border: 'none' },
                    '&.Mui-focused fieldset': { border: 'none' },
                  },
                  '& .MuiInputAdornment-root': {
                    color: '#9CA3AF',
                  },
                  '& input': {
                    color: 'white',
                    padding: { xs: '6px 0', sm: '8px 0' },
                  },
                }}
                InputProps={{
                  startAdornment: (
                    <InputAdornment position="start">
                      <SearchIcon sx={{ fontSize: { xs: 18, sm: 20 } }} />
                    </InputAdornment>
                  ),
                  endAdornment: (
                    <InputAdornment position="end" sx={{ gap: { xs: 0.25, sm: 0.5 } }}>
                      {isSearching && (
                        <CircularProgress size={16} sx={{ color: '#9CA3AF', mr: 0.5 }} />
                      )}
                      {searchText && !isSearching && (
                        <IconButton 
                          onClick={() => {
                            setSearchText('');
                            setIsHafizNaeemOnly(false);
                          }} 
                          size="small" 
                          sx={{ 
                            color: '#9CA3AF',
                            padding: { xs: '4px', sm: '6px' }
                          }}
                        >
                          <ClearIcon sx={{ fontSize: { xs: 16, sm: 18 } }} />
                        </IconButton>
                      )}
                      <IconButton
                        onClick={handleMicClick}
                        size="small"
                        sx={{
                          color: listening ? '#EF4444' : '#9CA3AF',
                          padding: { xs: '4px', sm: '6px' },
                          animation: listening ? 'pulse 1.5s ease-in-out infinite' : 'none',
                          '@keyframes pulse': {
                            '0%, 100%': { opacity: 1 },
                            '50%': { opacity: 0.5 },
                          },
                        }}
                      >
                        <MicIcon sx={{ fontSize: { xs: 16, sm: 18 } }} />
                      </IconButton>
                    </InputAdornment>
                  ),
                }}
              />

                {/* Video filename suggestions dropdown */}
                {showSuggestions && suggestions.length > 0 && (
                  <Box
                    sx={{
                      position: 'absolute',
                      top: '100%',
                      left: 0,
                      right: 0,
                      mt: 0.5,
                      zIndex: 1300,
                      bgcolor: '#2A303C',
                      borderRadius: '12px',
                      maxHeight: 300,
                      overflow: 'auto',
                      boxShadow: '0 8px 32px rgba(0,0,0,0.5)',
                      border: '1px solid rgba(255,255,255,0.1)',
                    }}
                  >
                    <List dense disablePadding>
                      {suggestions.map((video) => (
                        <ListItemButton
                          key={video.id}
                          onClick={() => {
                            suggestionSelectedRef.current = true;
                            setSearchText(video.title || video.filename);
                            setSuggestions([]);
                            setShowSuggestions(false);
                          }}
                          sx={{
                            px: 2,
                            py: 0.75,
                            '&:hover': { bgcolor: 'rgba(238,29,82,0.15)' },
                            borderBottom: '1px solid rgba(255,255,255,0.05)',
                          }}
                        >
                          <VideoFileIcon sx={{ color: '#EE1D52', mr: 1.5, fontSize: 18 }} />
                          <ListItemText
                            primary={video.title || video.filename}
                            secondary={video.title ? video.filename : null}
                            primaryTypographyProps={{ sx: { color: 'white', fontSize: '0.8rem' } }}
                            secondaryTypographyProps={{ sx: { color: 'rgba(255,255,255,0.5)', fontSize: '0.7rem' } }}
                          />
                        </ListItemButton>
                      ))}
                    </List>
                  </Box>
                )}
              </Box>

              {/* Search Mode Toggle — compact for header */}
              <ToggleButtonGroup
                value={searchMode}
                exclusive
                onChange={(e, newMode) => {
                  if (newMode) {
                    setSearchMode(newMode);
                    if (newMode === 'semantic') {
                      setActiveFilterType(null);
                    } else {
                      setIsHafizNaeemOnly(false);
                    }
                  }
                }}
                size="small"
                sx={{
                  bgcolor: '#374151',
                  borderRadius: '12px',
                  border: 'none',
                  height: { xs: 36, sm: 40 },
                  flexShrink: 0,
                  '& .MuiToggleButton-root': {
                    border: 'none',
                    borderRadius: '12px !important',
                    color: 'rgba(255,255,255,0.5)',
                    textTransform: 'none',
                    px: { xs: 1, sm: 1.5 },
                    py: 0,
                    fontSize: { xs: '0.65rem', sm: '0.7rem' },
                    fontWeight: 500,
                    minWidth: 'auto',
                    '&.Mui-selected': {
                      bgcolor: '#EE1D52 !important',
                      color: 'white !important',
                      fontWeight: 600,
                    },
                  }
                }}
              >
                <ToggleButton value="semantic">
                  <PsychologyIcon sx={{ fontSize: 14, mr: 0.5 }} />
                  AI
                </ToggleButton>
                <ToggleButton value="simple">
                  <TuneIcon sx={{ fontSize: 14, mr: 0.5 }} />
                  {t('searchPage.simple')}
                </ToggleButton>
              </ToggleButtonGroup>

              {/* Search Button */}
              <IconButton
                onClick={() => {
                  if (isSearchEnabled() && !isSearching) {
                    if (abortControllerRef.current) {
                      abortControllerRef.current.abort();
                    }
                    const abortController = new AbortController();
                    abortControllerRef.current = abortController;
                    // Reset Hafiz Naeem toggle on every new search click
                    intentionalSearchRef.current = true;
                    setIsHafizNaeemOnly(false);
                    setIsSearching(true);
                    fetchSearchResults(abortController.signal, searchText.trim(), false);
                  }
                }}
                disabled={isSearching || !isSearchEnabled()}
                sx={{
                  bgcolor: '#EE1D52',
                  color: 'white',
                  width: { xs: 36, sm: 40 },
                  height: { xs: 36, sm: 40 },
                  flexShrink: 0,
                  '&:hover': {
                    bgcolor: '#dc1847',
                  },
                  '&:disabled': {
                    bgcolor: '#6B7280',
                    color: 'rgba(255,255,255,0.5)',
                  },
                }}
              >
                {isSearching ? (
                  <CircularProgress size={18} sx={{ color: 'white' }} />
                ) : (
                  <SearchIcon sx={{ fontSize: { xs: 18, sm: 20 } }} />
                )}
              </IconButton>

              {/* Desktop Controls - Show on same row */}
              <Box sx={{ 
                display: { xs: 'none', md: 'flex' },
                alignItems: 'center',
                gap: 1,
                flexShrink: 0
              }}>
                {/* Simple Mode: Filter Type Selector - Desktop */}
                {searchMode === 'simple' && (
                  <FormControl size="small" sx={{ minWidth: 110 }}>
                    <Select
                      value={activeFilterType || ''}
                      onChange={(e) => {
                        const val = e.target.value || null;
                        setActiveFilterType(val);
                        if (val === 'date') {
                          setDateFilterType('year');
                        } else {
                          setDateFilterType('none');
                          setSelectedDate(null);
                        }
                        if (val === 'language') {
                          setSelectedLanguage('');
                        } else if (val !== 'language') {
                          setSelectedLanguage('');
                        }
                      }}
                      displayEmpty
                      sx={{
                        bgcolor: '#374151',
                        color: 'white',
                        borderRadius: '12px',
                        fontSize: '0.7rem',
                        height: 28,
                        '& .MuiOutlinedInput-notchedOutline': { border: 'none' },
                        '& .MuiSvgIcon-root': { color: 'white', fontSize: '1rem' },
                        '&:hover': { bgcolor: '#4B5563' }
                      }}
                    >
                      <MenuItem value="" sx={{ fontSize: '0.75rem' }}>Filter by...</MenuItem>
                      <MenuItem value="speaker" sx={{ fontSize: '0.75rem' }}>🎤 Speaker</MenuItem>
                      <MenuItem value="title" sx={{ fontSize: '0.75rem' }}>📝 Title</MenuItem>
                      <MenuItem value="date" sx={{ fontSize: '0.75rem' }}>📅 Date</MenuItem>
                      <MenuItem value="language" sx={{ fontSize: '0.75rem' }}>🌐 Language</MenuItem>
                      <MenuItem value="summary" sx={{ fontSize: '0.75rem' }}>📄 Summary</MenuItem>
                      <MenuItem value="text" sx={{ fontSize: '0.75rem' }}>🔤 Text</MenuItem>
                    </Select>
                  </FormControl>
                )}

                {/* Language Filter — Desktop (shown only when language filter is active) */}
                {searchMode === 'simple' && activeFilterType === 'language' && (
                  <FormControl size="small" sx={{ minWidth: 140 }}>
                    <Select
                      value={selectedLanguage}
                      onChange={(e) => {
                        const lang = e.target.value;
                        setSelectedLanguage(lang);
                        // Show selected language name in the search input
                        setSearchText(lang === 'en' ? 'English' : lang === 'ur' ? 'Urdu' : '');
                      }}
                      displayEmpty
                      sx={{
                        bgcolor: '#374151',
                        color: 'white',
                        borderRadius: '12px',
                        fontSize: '0.7rem',
                        height: 28,
                        '& .MuiOutlinedInput-notchedOutline': { border: 'none' },
                        '& .MuiSvgIcon-root': { color: 'white', fontSize: '1rem' },
                        '&:hover': { bgcolor: '#4B5563' }
                      }}
                    >
                      <MenuItem value="" disabled sx={{ fontSize: '0.75rem' }}>{t('searchPage.selectLanguageDropdown')}</MenuItem>
                      <MenuItem value="en" sx={{ fontSize: '0.75rem' }}>🇬🇧 English</MenuItem>
                      <MenuItem value="ur" sx={{ fontSize: '0.75rem' }}>🇵🇰 Urdu</MenuItem>
                    </Select>
                  </FormControl>
                )}

                {/* Hafiz Naeem Avatar — shown in semantic mode, HIDDEN in simple mode with active filter */}
                {!(searchMode === 'simple' && activeFilterType) && (
                  <Tooltip
                    title={isHafizNaeemOnly ? t('searchPage.hafizNaeemActive') || 'Searching Hafiz Naeem - Click to disable' : t('searchPage.hafizNaeemInactive') || 'Click to search Hafiz Naeem only'}
                    arrow
                    placement="bottom"
                  >
                    <Box sx={{ position: 'relative' }}>
                      <IconButton
                        onClick={() => {
                          const newState = !isHafizNaeemOnly;
                          intentionalSearchRef.current = true; // prevent abort useEffect from cancelling this search
                          setIsHafizNaeemOnly(newState);

                          if (newState) {
                            // Activating: Set search text and auto-search
                            const speakerName = 'Hafiz Naeem Ur Rehman';
                            setSearchText(speakerName);
                            const abortController = new AbortController();
                            abortControllerRef.current = abortController;
                            setIsSearching(true);
                            fetchSearchResults(abortController.signal, speakerName, true);
                          } else {
                            // Deactivating: Abort ongoing search and clear
                            if (abortControllerRef.current) {
                              console.log('🛑 Hafiz Naeem toggle OFF - aborting search');
                              abortControllerRef.current.abort();
                              abortControllerRef.current = null;
                            }
                            setIsSearching(false);
                            setSearchText('');
                          }
                        }}
                        disabled={isSearching || (searchMode === 'simple' && activeFilterType)}
                      sx={{
                        p: 0,
                        transition: 'all 0.3s ease',
                        '&:hover': {
                          transform: 'scale(1.1)',
                        }
                      }}
                    >
                      <Avatar
                        src={HafizNaeemImage}
                        alt="Hafiz Naeem ur Rehman"
                        sx={{
                          width: 40,
                          height: 40,
                          border: isHafizNaeemOnly ? '2.5px solid #EE1D52' : '2.5px solid rgba(255, 255, 255, 0.3)',
                          boxShadow: isHafizNaeemOnly
                            ? '0 0 15px rgba(238, 29, 82, 0.6), 0 3px 10px rgba(0, 0, 0, 0.4)'
                            : '0 3px 10px rgba(0, 0, 0, 0.3)',
                          transition: 'all 0.3s ease',
                          cursor: isSearching ? 'not-allowed' : 'pointer',
                          filter: isSearching ? 'brightness(0.7)' : 'brightness(1)',
                          '&:hover': {
                            border: isHafizNaeemOnly ? '2.5px solid #dc1847' : '2.5px solid rgba(255, 255, 255, 0.6)',
                            boxShadow: isHafizNaeemOnly
                              ? '0 0 20px rgba(238, 29, 82, 0.8), 0 4px 12px rgba(0, 0, 0, 0.5)'
                              : '0 4px 12px rgba(0, 0, 0, 0.4)',
                          }
                        }}
                      />
                    </IconButton>
                      {isSearching && (
                      <CircularProgress
                        size={16}
                        sx={{
                          position: 'absolute',
                          top: '50%',
                          left: '50%',
                          marginTop: '-8px',
                          marginLeft: '-8px',
                          color: '#EE1D52',
                        }}
                      />
                    )}
                    </Box>
                  </Tooltip>
                )}

                {/* Date Filter - Desktop — shown in semantic mode or when simple+date filter */}
                {(searchMode === 'semantic' || activeFilterType === 'date') && (
                  <>
                    <FormControl size="small" sx={{ minWidth: 110 }}>
                      <Select
                        value={dateFilterType}
                        onChange={(e) => handleDateFilterChange(e.target.value)}
                        displayEmpty
                        sx={{
                          bgcolor: '#374151',
                          color: 'white',
                          borderRadius: '12px',
                          fontSize: '0.7rem',
                          height: 28,
                          '& .MuiOutlinedInput-notchedOutline': { border: 'none' },
                          '& .MuiSvgIcon-root': { color: 'white', fontSize: '1rem' },
                          '&:hover': { bgcolor: '#4B5563' }
                        }}
                      >
                        {searchMode === 'semantic' && <MenuItem value="none" sx={{ fontSize: '0.75rem' }}>No Date</MenuItem>}
                        <MenuItem value="year" sx={{ fontSize: '0.75rem' }}>By Year</MenuItem>
                        <MenuItem value="month" sx={{ fontSize: '0.75rem' }}>By Month</MenuItem>
                        <MenuItem value="date" sx={{ fontSize: '0.75rem' }}>By Date</MenuItem>
                      </Select>
                    </FormControl>

                    {dateFilterType !== 'none' && (
                      <LocalizationProvider dateAdapter={AdapterDayjs}>
                        <DatePicker
                          value={selectedDate}
                          onChange={(newValue) => setSelectedDate(newValue)}
                          views={
                            dateFilterType === 'year' ? ['year'] :
                              dateFilterType === 'month' ? ['year', 'month'] :
                                ['year', 'month', 'day']
                          }
                          slotProps={{
                            textField: {
                              size: 'small',
                              placeholder: `Select ${dateFilterType}`,
                              sx: {
                                minWidth: 120,
                                '& .MuiOutlinedInput-root': {
                                  bgcolor: '#374151',
                                  color: 'white',
                                  borderRadius: '12px',
                                  fontSize: '0.7rem',
                                  height: 28,
                                  '& fieldset': { border: 'none' },
                                  '& .MuiSvgIcon-root': { color: 'white', fontSize: '1rem' },
                                  '&:hover': { bgcolor: '#4B5563' }
                                },
                                '& input': {
                                  color: 'white',
                                  fontSize: '0.7rem',
                                  padding: '4px 8px'
                                }
                              }
                            },
                            popper: {
                              sx: {
                                '& .MuiPaper-root': { bgcolor: '#2A303C', color: 'white' },
                                '& .MuiPickersDay-root': {
                                  color: 'white',
                                  '&:hover': { bgcolor: '#374151' },
                                  '&.Mui-selected': {
                                    bgcolor: '#EE1D52 !important',
                                    '&:hover': { bgcolor: '#dc1847 !important' }
                                  }
                                },
                                '& .MuiPickersYear-yearButton, & .MuiPickersMonth-monthButton': {
                                  color: 'white',
                                  '&:hover': { bgcolor: '#374151' },
                                  '&.Mui-selected': {
                                    bgcolor: '#EE1D52 !important',
                                    '&:hover': { bgcolor: '#dc1847 !important' }
                                  }
                                },
                                '& .MuiPickersCalendarHeader-root, & .MuiIconButton-root, & .MuiTypography-root': {
                                  color: 'white'
                                }
                              }
                            }
                          }}
                        />
                      </LocalizationProvider>
                    )}
                  </>
                )}
              </Box>
            </Box>

            {/* Mobile Controls - Below search bar */}
            <Box sx={{ 
              display: { xs: 'flex', md: 'none' },
              alignItems: 'center',
              justifyContent: 'center',
              gap: { xs: 0.75, sm: 1 },
              flexWrap: 'wrap'
            }}>
              {/* Simple Mode: Filter Type Selector - Mobile */}
              {searchMode === 'simple' && (
                <FormControl size="small" sx={{ minWidth: { xs: 95, sm: 110 } }}>
                  <Select
                    value={activeFilterType || ''}
                    onChange={(e) => {
                      const val = e.target.value || null;
                      setActiveFilterType(val);
                      if (val === 'date') {
                        setDateFilterType('year');
                      } else {
                        setDateFilterType('none');
                        setSelectedDate(null);
                      }
                      if (val === 'language') {
                        setSelectedLanguage('');
                      } else if (val !== 'language') {
                        setSelectedLanguage('');
                      }
                    }}
                    displayEmpty
                    sx={{
                      bgcolor: '#374151',
                      color: 'white',
                      borderRadius: '12px',
                      fontSize: { xs: '0.65rem', sm: '0.7rem' },
                      height: { xs: 26, sm: 28 },
                      '& .MuiOutlinedInput-notchedOutline': { border: 'none' },
                      '& .MuiSvgIcon-root': { color: 'white', fontSize: { xs: '0.9rem', sm: '1rem' } },
                      '&:hover': { bgcolor: '#4B5563' }
                    }}
                  >
                    <MenuItem value="" sx={{ fontSize: '0.75rem' }}>Filter by...</MenuItem>
                    <MenuItem value="speaker" sx={{ fontSize: '0.75rem' }}>🎤 Speaker</MenuItem>
                    <MenuItem value="title" sx={{ fontSize: '0.75rem' }}>📝 Title</MenuItem>
                    <MenuItem value="date" sx={{ fontSize: '0.75rem' }}>📅 Date</MenuItem>
                    <MenuItem value="language" sx={{ fontSize: '0.75rem' }}>🌐 Language</MenuItem>
                    <MenuItem value="summary" sx={{ fontSize: '0.75rem' }}>📄 Summary</MenuItem>
                    <MenuItem value="text" sx={{ fontSize: '0.75rem' }}>🔤 Text</MenuItem>
                  </Select>
                </FormControl>
              )}

              {/* Language Filter — Mobile (shown only when language filter is active) */}
              {searchMode === 'simple' && activeFilterType === 'language' && (
                <FormControl size="small" sx={{ minWidth: { xs: 120, sm: 140 } }}>
                  <Select
                    value={selectedLanguage}
                    onChange={(e) => {
                      const lang = e.target.value;
                      setSelectedLanguage(lang);
                      // Show selected language name in the search input
                      setSearchText(lang === 'en' ? 'English' : lang === 'ur' ? 'Urdu' : '');
                    }}
                    displayEmpty
                    sx={{
                      bgcolor: '#374151',
                      color: 'white',
                      borderRadius: '12px',
                      fontSize: { xs: '0.65rem', sm: '0.7rem' },
                      height: { xs: 26, sm: 28 },
                      '& .MuiOutlinedInput-notchedOutline': { border: 'none' },
                      '& .MuiSvgIcon-root': { color: 'white', fontSize: { xs: '0.9rem', sm: '1rem' } },
                      '&:hover': { bgcolor: '#4B5563' }
                    }}
                  >
                    <MenuItem value="" disabled sx={{ fontSize: '0.75rem' }}>{t('searchPage.selectLanguageDropdown')}</MenuItem>
                    <MenuItem value="en" sx={{ fontSize: '0.75rem' }}>🇬🇧 English</MenuItem>
                    <MenuItem value="ur" sx={{ fontSize: '0.75rem' }}>🇵🇰 Urdu</MenuItem>
                  </Select>
                </FormControl>
              )}

              {/* Hafiz Naeem Avatar — shown in both semantic and simple modes */}
              <Tooltip
                title={isHafizNaeemOnly ? t('searchPage.hafizNaeemActive') || 'Searching Hafiz Naeem - Click to disable' : t('searchPage.hafizNaeemInactive') || 'Click to search Hafiz Naeem only'}
                arrow
                placement="bottom"
              >
                <Box sx={{ position: 'relative' }}>
                  <IconButton
                    onClick={() => {
                      const newState = !isHafizNaeemOnly;
                      intentionalSearchRef.current = true; // prevent abort useEffect from cancelling this search
                      setIsHafizNaeemOnly(newState);

                      if (newState) {
              // Activating: Set search text and auto-search
                        const speakerName = 'Hafiz Naeem Ur Rehman';
                        setSearchText(speakerName);
                        const abortController = new AbortController();
                        abortControllerRef.current = abortController;
                        setIsSearching(true);
                        fetchSearchResults(abortController.signal, speakerName, true);
                      } else {
                        // Deactivating: Abort ongoing search and clear
                        if (abortControllerRef.current) {
                          console.log('🛑 Hafiz Naeem toggle OFF - aborting search');
                          abortControllerRef.current.abort();
                          abortControllerRef.current = null;
                        }
                        setIsSearching(false);
                        setSearchText('');
                      }
                    }}
                    disabled={isSearching}
                    sx={{
                      p: 0,
                      transition: 'all 0.3s ease',
                      '&:hover': {
                        transform: 'scale(1.1)',
                      }
                    }}
                  >
                    <Avatar
                      src={HafizNaeemImage}
                      alt="Hafiz Naeem ur Rehman"
                      sx={{
                        width: { xs: 36, sm: 40 },
                        height: { xs: 36, sm: 40 },
                        border: isHafizNaeemOnly ? '2.5px solid #EE1D52' : '2.5px solid rgba(255, 255, 255, 0.3)',
                        boxShadow: isHafizNaeemOnly
                          ? '0 0 15px rgba(238, 29, 82, 0.6), 0 3px 10px rgba(0, 0, 0, 0.4)'
                          : '0 3px 10px rgba(0, 0, 0, 0.3)',
                        transition: 'all 0.3s ease',
                        cursor: isSearching ? 'not-allowed' : 'pointer',
                        filter: isSearching ? 'brightness(0.7)' : 'brightness(1)',
                        '&:hover': {
                          border: isHafizNaeemOnly ? '2.5px solid #dc1847' : '2.5px solid rgba(255, 255, 255, 0.6)',
                          boxShadow: isHafizNaeemOnly
                            ? '0 0 20px rgba(238, 29, 82, 0.8), 0 4px 12px rgba(0, 0, 0, 0.5)'
                            : '0 4px 12px rgba(0, 0, 0, 0.4)',
                        }
                      }}
                    />
                  </IconButton>
                  {isSearching && (
                    <CircularProgress
                      size={14}
                      sx={{
                        position: 'absolute',
                        top: '50%',
                        left: '50%',
                        marginTop: '-7px',
                        marginLeft: '-7px',
                        color: '#EE1D52',
                      }}
                    />
                  )}
                </Box>
              </Tooltip>

              {/* Date Filter - Mobile — shown in semantic mode or when simple+date filter */}
              {(searchMode === 'semantic' || activeFilterType === 'date') && (
                <>
                  <FormControl size="small" sx={{ minWidth: { xs: 95, sm: 110 } }}>
                    <Select
                      value={dateFilterType}
                      onChange={(e) => handleDateFilterChange(e.target.value)}
                      displayEmpty
                      sx={{
                        bgcolor: '#374151',
                        color: 'white',
                        borderRadius: '12px',
                        fontSize: { xs: '0.65rem', sm: '0.7rem' },
                        height: { xs: 26, sm: 28 },
                        '& .MuiOutlinedInput-notchedOutline': { border: 'none' },
                        '& .MuiSvgIcon-root': { color: 'white', fontSize: { xs: '0.9rem', sm: '1rem' } },
                        '&:hover': { bgcolor: '#4B5563' }
                      }}
                    >
                      <MenuItem value="none" sx={{ fontSize: '0.75rem' }}>No Date</MenuItem>
                      <MenuItem value="year" sx={{ fontSize: '0.75rem' }}>By Year</MenuItem>
                      <MenuItem value="month" sx={{ fontSize: '0.75rem' }}>By Month</MenuItem>
                      <MenuItem value="date" sx={{ fontSize: '0.75rem' }}>By Date</MenuItem>
                    </Select>
                  </FormControl>

                  {dateFilterType !== 'none' && (
                    <LocalizationProvider dateAdapter={AdapterDayjs}>
                      <DatePicker
                        value={selectedDate}
                        onChange={(newValue) => setSelectedDate(newValue)}
                        views={
                          dateFilterType === 'year' ? ['year'] :
                            dateFilterType === 'month' ? ['year', 'month'] :
                              ['year', 'month', 'day']
                        }
                        slotProps={{
                          textField: {
                            size: 'small',
                            placeholder: `Select ${dateFilterType}`,
                            sx: {
                              minWidth: { xs: 100, sm: 120 },
                              '& .MuiOutlinedInput-root': {
                                bgcolor: '#374151',
                                color: 'white',
                                borderRadius: '12px',
                                fontSize: { xs: '0.65rem', sm: '0.7rem' },
                                height: { xs: 26, sm: 28 },
                                '& fieldset': { border: 'none' },
                                '& .MuiSvgIcon-root': { color: 'white', fontSize: { xs: '0.9rem', sm: '1rem' } },
                                '&:hover': { bgcolor: '#4B5563' }
                              },
                              '& input': {
                                color: 'white',
                                fontSize: { xs: '0.65rem', sm: '0.7rem' },
                                padding: { xs: '3px 6px', sm: '4px 8px' }
                              }
                            }
                          },
                          popper: {
                            sx: {
                              '& .MuiPaper-root': { bgcolor: '#2A303C', color: 'white' },
                              '& .MuiPickersDay-root': {
                                color: 'white',
                                '&:hover': { bgcolor: '#374151' },
                                '&.Mui-selected': {
                                  bgcolor: '#EE1D52 !important',
                                  '&:hover': { bgcolor: '#dc1847 !important' }
                                }
                              },
                              '& .MuiPickersYear-yearButton, & .MuiPickersMonth-monthButton': {
                                color: 'white',
                                '&:hover': { bgcolor: '#374151' },
                                '&.Mui-selected': {
                                  bgcolor: '#EE1D52 !important',
                                  '&:hover': { bgcolor: '#dc1847 !important' }
                                }
                              },
                              '& .MuiPickersCalendarHeader-root, & .MuiIconButton-root, & .MuiTypography-root': {
                                color: 'white'
                              }
                            }
                          }
                        }}
                      />
                    </LocalizationProvider>
                  )}
                </>
              )}
            </Box>
          </Box>

          {/* Language Switcher & User Avatar - Desktop only */}
          <Box sx={{ 
            display: { xs: 'none', md: 'flex' },
            alignItems: 'center',
            gap: 2,
            minWidth: 80,
            justifyContent: 'flex-end'
          }}>
            <LanguageSwitcher />
            <IconButton 
              onClick={handleClick}
              size="small"
              aria-controls={open ? 'account-menu' : undefined}
              aria-haspopup="true"
              aria-expanded={open ? 'true' : undefined}
              sx={{ padding: 0 }}
            >
              <Avatar 
                sx={{ 
                  width: 32,
                  height: 32,
                  backgroundColor: 'transparent',
                  border: '2px solid #374151',
                  color: 'white',
                  fontSize: '0.875rem',
                  '&:hover': { borderColor: '#4B5563' }
                }}
              >
                {user?.name?.charAt(0) || 'U'}
              </Avatar>
            </IconButton>
            <Menu
              id="account-menu"
              anchorEl={anchorEl}
              open={open}
              onClose={handleMenuClose}
              transformOrigin={{ horizontal: 'right', vertical: 'top' }}
              anchorOrigin={{ horizontal: 'right', vertical: 'bottom' }}
              PaperProps={{
                sx: {
                  backgroundColor: '#2A303C',
                  color: 'white',
                  marginTop: 1,
                  minWidth: 180,
                  '& .MuiMenuItem-root': {
                    fontSize: '0.875rem',
                    py: 1,
                    px: 2,
                    '&:hover': { backgroundColor: '#374151' }
                  }
                }
              }}
            >
              <MenuItem onClick={handleMenuClose} sx={{ pointerEvents: 'none' }}>
                <Box sx={{ display: 'flex', flexDirection: 'column' }}>
                  <span style={{ fontWeight: 600 }}>{user?.name || 'User'}</span>
                  <span style={{ fontSize: '0.75rem', color: '#9CA3AF' }}>{user?.email}</span>
                </Box>
              </MenuItem>
              {(user?.can_edit_profile !== false) && (
                <MenuItem
                  onClick={handleMenuClose}
                  component={Link}
                  href={route('user.profile.edit')}
                >
                  <PersonIcon sx={{ mr: 1, fontSize: 18 }} /> Profile
                </MenuItem>
              )}
              {(user?.can_edit_profile === false) && (
                <MenuItem
                  onClick={() => {
                    handleMenuClose();
                    Swal.fire({
                      icon: 'error',
                      title: 'Access Denied',
                      text: 'You do not have permission to edit your profile. Please contact the administrator.',
                      confirmButtonColor: '#EE1D52'
                    });
                  }}
                >
                  <PersonIcon sx={{ mr: 1, fontSize: 18 }} /> Profile
                </MenuItem>
              )}
              <MenuItem onClick={handleLogout} sx={{ color: '#EF4444 !important' }}>
                <LogoutIcon sx={{ mr: 1, fontSize: 18 }} /> Logout
              </MenuItem>
            </Menu>
          </Box>
        </Toolbar>
      </AppBar>

      {/* Page Content */}
      <Box sx={{ width: '100%', bgcolor: '#0C0E10' }}>{children}</Box>

      {/* Snackbar for notifications */}
      <Snackbar
        open={snackbar.open}
        autoHideDuration={4000}
        onClose={handleSnackbarClose}
        anchorOrigin={{ vertical: 'top', horizontal: 'center' }}
        sx={{ marginTop: '80px' }}
      >
        <Alert
          onClose={handleSnackbarClose}
          severity={snackbar.severity}
          variant="filled"
          sx={{
            width: '100%',
            minWidth: '400px',
            maxWidth: '600px',
            borderRadius: '12px',
            boxShadow: '0 8px 32px rgba(0, 0, 0, 0.3)',
            padding: '16px 20px',
            fontSize: '0.95rem',
            lineHeight: '1.6',
            '& .MuiAlert-message': {
              padding: '0',
              whiteSpace: 'pre-line',
              wordBreak: 'break-word'
            },
            '& .MuiAlert-icon': {
              fontSize: '24px',
              marginRight: '12px'
            }
          }}
        >
          {snackbar.message}
        </Alert>
      </Snackbar>
    </Box>
  );
}