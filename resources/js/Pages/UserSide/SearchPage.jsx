import React, { useState, useEffect, useCallback, useRef } from 'react';
import { Head, router, usePage, Link } from '@inertiajs/react';
import LogoBgVideo from '@/Images/bg-login--video.mp4';
import MainLogo from '@/Images/main-logo.svg';
import HafizNaeemImage from '@/Images/Hafiz_Naeem_ur_Rehman.webp.png';
import { Box, TextField, Switch, Button, IconButton, InputAdornment, Typography, Avatar, Menu, MenuItem, CircularProgress, Select, FormControl, InputLabel, ToggleButtonGroup, ToggleButton, Paper, List, ListItemButton, ListItemText, Tooltip } from '@mui/material';
import VideoFileIcon from '@mui/icons-material/VideoFile';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import { LocalizationProvider } from '@mui/x-date-pickers/LocalizationProvider';
import { AdapterDayjs } from '@mui/x-date-pickers/AdapterDayjs';
import SearchIcon from '@mui/icons-material/Search';
import MicIcon from '@mui/icons-material/Mic';
import ClearIcon from '@mui/icons-material/Close';
import PersonIcon from '@mui/icons-material/Person';
import LogoutIcon from '@mui/icons-material/Logout';
import CalendarTodayIcon from '@mui/icons-material/CalendarToday';
import PsychologyIcon from '@mui/icons-material/Psychology';
import TuneIcon from '@mui/icons-material/Tune';
import '../../../css/style.css'
import SpeechRecognition, { useSpeechRecognition } from 'react-speech-recognition'
import { useTranslation } from 'react-i18next';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import dayjs from 'dayjs';

export default function SearchPage() {
  const { t } = useTranslation();
  const user = usePage().props.auth?.user;
  const MAX_QUERY_LENGTH = 1000;

  // SearchPage always starts fresh — clear any persisted filters from previous searches
  // so users get a clean landing page experience.
  const [searchText, setSearchText] = useState('');
  const [isHafizNaeemOnly, setIsHafizNaeemOnly] = useState(false);
  const [searchResults, setSearchResults] = useState(null);
  const [isSearching, setIsSearching] = useState(false);
  const [searchError, setSearchError] = useState(null);
  const [anchorEl, setAnchorEl] = useState(null);
  const open = Boolean(anchorEl);

  // Ref to store abort controller for cancelling searches
  const abortControllerRef = useRef(null);

  // Ref to skip abort when state change comes from an intentional search trigger
  const intentionalSearchRef = useRef(false);

  // Ref to track the last executed search query to avoid redundant searches
  const lastSearchQueryRef = useRef('');

  // Ref to store Hafiz Naeem toggle search timer
  const hafizNaeemSearchTimerRef = useRef(null);

  // Video filename suggestions state
  const [suggestions, setSuggestions] = useState([]);
  const [showSuggestions, setShowSuggestions] = useState(false);
  const suggestionsTimerRef = useRef(null);
  const suggestionsAbortControllerRef = useRef(null);
  const searchBoxRef = useRef(null);
  const suggestionSelectedRef = useRef(false);

  // Search type: smart, speaker, title, date, language, transcript, summary
  const [selectedSearchType, setSelectedSearchType] = useState('smart');

  // Date filter states
  const [dateFilterType, setDateFilterType] = useState('none');
  const [selectedDate, setSelectedDate] = useState(null);

  // Language filter state
  const [selectedLanguage, setSelectedLanguage] = useState('');

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

  // Speech Recognition Hook
  const {
    transcript,
    listening,
    browserSupportsSpeechRecognition,
    resetTranscript
  } = useSpeechRecognition();

  // Update search text when transcript changes
  useEffect(() => {
    console.log('Transcript updated:', transcript);
    console.log('Listening status:', listening);
    if (transcript) {
      setSearchText(transcript);
    }
  }, [transcript, listening]);

  // Cleanup: abort any ongoing searches when component unmounts
  useEffect(() => {
    return () => {
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
      if (suggestionsTimerRef.current) {
        clearTimeout(suggestionsTimerRef.current);
      }
      if (suggestionsAbortControllerRef.current) {
        suggestionsAbortControllerRef.current.abort();
      }
      if (hafizNaeemSearchTimerRef.current) {
        clearTimeout(hafizNaeemSearchTimerRef.current);
      }
    };
  }, []);

  // Abort ongoing search when query or filters change
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
      setSearchError(null);
    }
    // Also clear any pending Hafiz Naeem search timers when parameters change
    if (hafizNaeemSearchTimerRef.current) {
      clearTimeout(hafizNaeemSearchTimerRef.current);
      hafizNaeemSearchTimerRef.current = null;
    }
  }, [searchText, selectedSearchType, dateFilterType, selectedDate, selectedLanguage]);

  // Disable Hafiz Naeem toggle when selecting a filter other than smart
  useEffect(() => {
    if (selectedSearchType !== 'smart' && isHafizNaeemOnly) {
      console.log('🔄 Auto-disabling Hafiz Naeem toggle - simple mode active');
      setIsHafizNaeemOnly(false);
    }
  }, [selectedSearchType]);

  // Fetch video filename suggestions as user types
  useEffect(() => {
    if (suggestionsTimerRef.current) clearTimeout(suggestionsTimerRef.current);
    if (suggestionsAbortControllerRef.current) {
      suggestionsAbortControllerRef.current.abort();
      suggestionsAbortControllerRef.current = null;
    }

    // Skip fetch when text was programmatically set from a suggestion click
    if (suggestionSelectedRef.current) {
      suggestionSelectedRef.current = false;
      return;
    }

    if (!searchText.trim() || searchText.trim().length < 2) {
      setSuggestions([]);
      setShowSuggestions(false);
      return;
    }

    suggestionsTimerRef.current = setTimeout(async () => {
      const suggestionController = new AbortController();
      suggestionsAbortControllerRef.current = suggestionController;
      try {
        const res = await fetch('/api/search/suggestions?q=' + encodeURIComponent(searchText.trim()), {
          signal: suggestionController.signal,
        });
        if (res.ok) {
          const jsonResponse = await res.json();
          // The new API returns { success: true, data: [...] }
          const data = jsonResponse.data || [];
          setSuggestions(data);
          setShowSuggestions(data.length > 0);
        }
      } catch (error) {
        if (error?.name !== 'AbortError') {
          console.warn('Autocomplete request failed:', error);
        }
      } finally {
        if (suggestionsAbortControllerRef.current === suggestionController) {
          suggestionsAbortControllerRef.current = null;
        }
      }
    }, 500);

    return () => {
      if (suggestionsTimerRef.current) clearTimeout(suggestionsTimerRef.current);
      if (suggestionsAbortControllerRef.current) {
        suggestionsAbortControllerRef.current.abort();
        suggestionsAbortControllerRef.current = null;
      }
    };
  }, [searchText]);

  // Close suggestions when clicking outside
  useEffect(() => {
    const handleClickOutside = (e) => {
      if (searchBoxRef.current && !searchBoxRef.current.contains(e.target)) {
        setShowSuggestions(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // Memoize the fetch function — unified search that combines semantic + keyword + exact match
  const fetchSearchResults = useCallback(async (signal, query, speakerFilterActive) => {
    console.log('🔍 Starting search:', { query, speakerFilterActive, selectedSearchType, dateFilterType, selectedDate });

    try {
      let requestBody = {
        min_score: 0.50,
        top_k: 200, // Get enough segments for pagination from Railway
        page: 1, // Start with first page
        per_page: 10, // 10 results per page
        use_incremental: true, // Enable cursor-based incremental pagination
        batch_size: 10, // Request 10 videos per batch for consistent pagination
        search_type: selectedSearchType,
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
      if (speakerFilterActive) {
        requestBody.search_type = 'smart'; // Forces semantic mode for Hafiz Naeem

        // CRITICAL FIX: Only send query/words, NOT speaker filter
        // This allows users to search any content when toggle is active
        if (query) {
          requestBody.query = query;
          requestBody.words = splitToCleanWords(query, 2);
        } else {
          // No custom query - default to searching for Hafiz Naeem speaker segments
          requestBody.search_type = 'speaker';
          requestBody.query = "Hafiz Naeem Ur Rehman";
        }
      } else {
        // ── REGULAR SEARCH ──
        if (query) {
          requestBody.query = query;
          requestBody.words = splitToCleanWords(query, 2);
        }
        
        if (selectedSearchType === 'language') {
          requestBody.language = selectedLanguage || '';
        }
      }

      // Add date filter if applicable
      if (dateFilterType !== 'none' && selectedDate) {
        const date = dayjs(selectedDate);
        if (dateFilterType === 'year') {
          requestBody.filter_year = date.year();
        } else if (dateFilterType === 'month') {
          requestBody.filter_month = date.month() + 1;
          requestBody.filter_year = date.year();
        } else if (dateFilterType === 'date') {
          requestBody.filter_date = date.format('YYYY-MM-DD');
        }
      }

      console.log('📤 Search payload:', requestBody);

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
        const errorMsg =
          ([504, 524].includes(response.status)
            ? 'Search timed out. Please retry in a moment.'
            : null) ||
          data?.message ||
          data?.errors?.query?.[0] ||
          data?.errors?.speaker?.[0] ||
          data?.errors?.title?.[0] ||
          `Search failed with status ${response.status}`;
        console.error('❌ Search error:', errorMsg);
        setSearchError(errorMsg);
        setIsSearching(false);
        return;
      }
      
      // Check if no results found
      if (data.success && (!data.grouped_by_video || data.grouped_by_video.length === 0)) {
        // Navigate to SearchedPage with empty results
        const { page: _p, ...searchParamsForPagination } = requestBody;
        router.post(route('searched.page'), {
          videos: [],
          query: query,
          searchMode: data.search_mode === 'simple'
            ? `simple_${data.filter_type || 'unknown'}`
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
        }, {
          onFinish: () => {
            setIsSearching(false);
            // FIXED: Reset Hafiz Naeem toggle after search completes
            if (isHafizNaeemOnly) {
              console.log('🔄 Auto-disabling Hafiz Naeem toggle after search');
              setIsHafizNaeemOnly(false);
            }
          }
        });
        return;
      }

      // Navigate to SearchedPage with results using POST to avoid URL length issues
      if (data.success && data.grouped_by_video && data.grouped_by_video.length > 0) {
        console.log('✅ Navigating to results:', data.total_videos, 'videos,', data.total_segments, 'segments');
        const { page: _pg, ...searchParamsForPagination2 } = requestBody;
        router.post(route('searched.page'), {
          videos: data.grouped_by_video,
          query: query,
          searchMode: data.search_mode === 'simple'
            ? `simple_${data.filter_type || 'unknown'}`
            : (speakerFilterActive ? 'speaker' : 'semantic'),
          totalVideos: data.total_videos,
          totalSegments: data.total_segments,
          searchMetadata: data.search_metadata || {},
          searchRequestBody: searchParamsForPagination2,
          cursor: data.cursor || null,
          search_session_id: data.search_session_id || null,
          is_incremental: data.is_incremental || false,
          pagination: {
            current_page: data.current_page || 1,
            per_page: data.per_page || 10,
            total_pages: data.total_pages || 1,
            has_next_page: data.has_next_page || false,
            has_prev_page: data.has_prev_page || false
          },
        }, {
          onFinish: () => {
            setIsSearching(false);
            // FIXED: Reset Hafiz Naeem toggle after search completes
            if (isHafizNaeemOnly) {
              console.log('🔄 Auto-disabling Hafiz Naeem toggle after search');
              setIsHafizNaeemOnly(false);
            }
          }
        });
      } else {
        console.warn('⚠️ No results in response');
        setSearchError(t('searchPage.noMatchingVideos'));
        setIsSearching(false);
      }

    } catch (error) {
      if (error.name === 'AbortError') {
        console.log('🛑 Search cancelled by user or new search');
        setIsSearching(false);
        return;
      }
      console.error('❌ Search Error:', error);
      setSearchError(
        t('searchPage.searchConnectionError')
      );
      setIsSearching(false);
    } finally {
      console.log('🏁 Search cleanup complete');
    }
  }, [selectedSearchType, dateFilterType, selectedDate, selectedLanguage]);

  const isNumericQuery = (text) => {
    return /^\d+$/.test(text);
  };

  const handleSearch = () => {
    const trimmedSearch = searchText.trim();
    const hasDateFilter = dateFilterType !== 'none' && selectedDate;
    const numericSearch = isNumericQuery(trimmedSearch);

    // Prevent multiple simultaneous searches
    if (isSearching) {
      console.log('⏸️ Search already in progress, ignoring...');
      return;
    }

    if (numericSearch && trimmedSearch.length < 2) {
      setSearchError(t('searchPage.numericSearchMinDigits'));
      return;
    }

    if (trimmedSearch.length > MAX_QUERY_LENGTH) {
      setSearchError(t('searchPage.queryTooLong'));
      return;
    }

    // Cancel any ongoing search before starting new one
    if (abortControllerRef.current) {
      console.log('🛑 Aborting previous search...');
      abortControllerRef.current.abort();
    }

    // Create new abort controller for this search
    const abortController = new AbortController();
    abortControllerRef.current = abortController;

    // Reset Hafiz Naeem toggle before every new search so it never bleeds into
    // the next query unless the user explicitly re-enables it.
    // Mark intentional so the abort useEffect doesn't cancel the search we're about to start.
    intentionalSearchRef.current = true;
    setIsHafizNaeemOnly(false);

    if (selectedSearchType === 'date' && hasDateFilter) {
      console.log('✅ Starting date search...');
      setIsSearching(true);
      setSearchError(null);
      fetchSearchResults(abortController.signal, trimmedSearch, false);
    } else if (selectedSearchType === 'language' && selectedLanguage) {
      console.log('✅ Starting language search...');
      setIsSearching(true);
      setSearchError(null);
      fetchSearchResults(abortController.signal, trimmedSearch, false);
    } else if (['speaker', 'title', 'summary', 'transcript'].includes(selectedSearchType) && trimmedSearch.length >= 1) {
      console.log(`✅ Starting ${selectedSearchType} search...`);
      setIsSearching(true);
      setSearchError(null);
      fetchSearchResults(abortController.signal, trimmedSearch, false);
    } else if (selectedSearchType === 'smart' && (trimmedSearch.length >= 2 || hasDateFilter)) {
      console.log('✅ Starting smart search...');

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

      setIsSearching(true);
      setSearchError(null);
      fetchSearchResults(abortController.signal, trimmedSearch, shouldUseHafizFilter);
    } else {
      console.warn('⚠️ Search conditions not met');
    }
  };

  // Check if search should be enabled
  const isSearchEnabled = () => {
    const trimmedSearch = searchText.trim();
    const hasDateFilter = dateFilterType !== 'none' && selectedDate;
    const numericSearch = isNumericQuery(trimmedSearch);

    if (selectedSearchType === 'date') return hasDateFilter;
    if (selectedSearchType === 'language') return selectedLanguage !== '';
    if (['speaker', 'title', 'summary', 'transcript'].includes(selectedSearchType)) {
      if (numericSearch) return trimmedSearch.length >= 2;
      return trimmedSearch.length >= 1;
    }
    
    // smart mode
    if (numericSearch) return trimmedSearch.length >= 2;
    return trimmedSearch.length >= 2 || hasDateFilter;
  };

  const handleDateFilterChange = (newType) => {
    setDateFilterType(newType);
    if (newType === 'none') {
      setSelectedDate(null);
    }
    // Clear error when changing date filter
    if (searchError) {
      setSearchError(null);
    }
  };

  // Handle microphone button click
  const handleMicClick = () => {
    console.log('Mic button clicked');
    console.log('Browser supports speech:', browserSupportsSpeechRecognition);
    
    if (!browserSupportsSpeechRecognition) {
      alert('Your browser does not support speech recognition. Please try Chrome or Edge.');
      return;
    }

    if (listening) {
      console.log('Stopping speech recognition...');
      SpeechRecognition.stopListening();
    } else {
      console.log('Starting speech recognition...');
      resetTranscript();
      setSearchText('');
      SpeechRecognition.startListening({ 
        language: 'ur-PK',
        continuous: false  // Automatically stops after pause
      }).then(() => {
        console.log('Speech recognition started successfully');
      }).catch((error) => {
        console.error('Error starting speech recognition:', error);
      });
    }
  };

  return (
    <Box sx={{ 
      height: '100vh',
      width: '100%',
      position: 'relative',
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
      overflow: 'hidden'
    }}>
      <Head title="Search" />
      
      {/* Top Right - Language Switcher & Profile */}
      <Box sx={{ 
        position: 'absolute', 
        top: { xs: 12, sm: 16, md: 20 }, 
        right: { xs: 12, sm: 16, md: 20 }, 
        zIndex: 10,
        display: 'flex',
        alignItems: 'center',
        gap: { xs: 1, sm: 1.5, md: 2 }
      }}>
        <LanguageSwitcher />

        {/* User Profile Avatar */}
        {user && (
          <>
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
                  width: { xs: 32, sm: 36 },
                  height: { xs: 32, sm: 36 },
                  backgroundColor: 'rgba(255, 255, 255, 0.1)',
                  border: '2px solid rgba(255, 255, 255, 0.3)',
                  color: 'white',
                  fontSize: { xs: '0.8rem', sm: '0.9rem' },
                  fontWeight: 600,
                  '&:hover': {
                    borderColor: 'rgba(255, 255, 255, 0.5)',
                    backgroundColor: 'rgba(255, 255, 255, 0.2)'
                  }
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
                  marginTop: { xs: 0.5, sm: 1 },
                  minWidth: { xs: 160, sm: 180 },
                  borderRadius: { xs: '8px', sm: '12px' },
                  boxShadow: '0 8px 32px rgba(0, 0, 0, 0.4)',
                  '& .MuiMenuItem-root': {
                    fontSize: { xs: '0.8rem', sm: '0.875rem' },
                    py: { xs: 1, sm: 1.5 },
                    px: { xs: 1.5, sm: 2 },
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
              <MenuItem
                onClick={handleMenuClose}
                component={Link}
                href={route('user.profile.edit')}
              >
                <PersonIcon sx={{ mr: 1, fontSize: 18 }} /> Profile
              </MenuItem>
              <MenuItem onClick={handleLogout} sx={{ color: '#EF4444 !important' }}>
                <LogoutIcon sx={{ mr: 1, fontSize: 18 }} /> Logout
              </MenuItem>
            </Menu>
          </>
        )}
      </Box>
      
      {/* Background Video with Overlay */}
      <Box sx={{ position: 'absolute', width: '100%', height: '100%', zIndex: -1 }}>
        <video
          autoPlay
          loop
          muted
          playsInline
          style={{
            position: 'absolute',
            width: '100%',
            height: '100%',
            objectFit: 'cover'
          }}
        >
          <source src={LogoBgVideo} type="video/mp4" />
        </video>
        {/* Dark Overlay */}
        <Box
          sx={{
            position: 'absolute',
            top: 0,
            left: 0,
            right: 0,
            bottom: 0,
            backgroundColor: 'rgba(0, 0, 0, 0.6)', // Dark overlay
            backdropFilter: 'blur(0px)', // Slight blur effect
          }}
        />
      </Box>

      {/* Content */}
      <Box sx={{ 
        width: '100%',
        maxWidth: { xs: '100%', sm: '540px', md: '600px' },
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: { xs: 2.5, sm: 3, md: 4 },
        p: { xs: 2, sm: 2.5, md: 3 },
        px: { xs: 2.5, sm: 3 }
      }}>
        {/* Logo */}
        <Box 
          component="img" 
          src={MainLogo} 
          alt="Pakistan Matters" 
          sx={{ 
            width: { xs: '200px', sm: '240px', md: '256px' },
            mb: { xs: 4, sm: 6, md: 8 },
            maxWidth: '90%'
          }} 
        />

        {/* Search Input */}
        <Box ref={searchBoxRef} sx={{ width: '100%', display: 'flex', flexDirection: 'column', gap: { xs: 1.5, sm: 2 } }}>
          <Box sx={{ position: 'relative', width: '100%' }}>
          <TextField
            fullWidth
            value={searchText}
            onChange={(e) => {
              setSearchText(e.target.value);
              // Clear error when user starts typing
              if (searchError) {
                setSearchError(null);
              }
            }}
            placeholder={
              selectedSearchType === 'speaker' ? (t('searchPage.searchBySpeaker') || 'Enter speaker name...')
              : selectedSearchType === 'title' ? (t('searchPage.searchByTitle') || 'Enter video title...')
              : selectedSearchType === 'language' ? (t('searchPage.selectLanguage') || 'Select language below...')
              : selectedSearchType === 'summary' ? (t('searchPage.searchBySummary') || 'Enter summary keywords...')
              : selectedSearchType === 'transcript' ? (t('searchPage.searchByText') || 'Search transcript text...')
              : selectedSearchType === 'date' ? (t('searchPage.searchOptional') || 'Optional text (select date below)')
              : (isHafizNaeemOnly ? t('searchPage.searchBySpeaker') : t('searchPage.searchByMeaning'))
            }
            onKeyPress={(e) => {
              if (e.key === 'Enter' && isSearchEnabled()) {
                handleSearch();
              }
            }}
            InputProps={{
              startAdornment: (
                <InputAdornment position="start">
                  <SearchIcon sx={{ color: 'white', fontSize: { xs: 20, sm: 24 } }} />
                </InputAdornment>
              ),
              endAdornment: (
                <InputAdornment position="end" sx={{ gap: { xs: 0.5, sm: 1 } }}>
                  {searchText && (
                    <IconButton 
                      onClick={() => setSearchText('')} 
                      size="small" 
                      sx={{ 
                        color: 'rgba(255, 255, 255, 0.7)',
                        padding: { xs: '6px', sm: '8px' }
                      }}
                    >
                      <ClearIcon sx={{ fontSize: { xs: 18, sm: 20 } }} />
                    </IconButton>
                  )}
                  <IconButton
                    onClick={handleMicClick}
                    size="small"
                    sx={{
                      color: listening ? '#EF4444' : 'white',
                      padding: { xs: '6px', sm: '8px' },
                      animation: listening ? 'pulse 1.5s ease-in-out infinite' : 'none',
                      '@keyframes pulse': {
                        '0%, 100%': {
                          opacity: 1,
                        },
                        '50%': {
                          opacity: 0.5,
                        },
                      },
                    }}
                  >
                    <MicIcon sx={{ fontSize: { xs: 20, sm: 22 } }} />
                  </IconButton>
                </InputAdornment>
              )
            }}
            sx={{
              '& input:-webkit-autofill': {
                WebkitBoxShadow: '0 0 0 1000px #29333C inset',
                WebkitTextFillColor: 'white',
                borderRadius: '9999px',
                transition: 'background-color 5000s ease-in-out 0s',
              },
              '& .MuiOutlinedInput-root': {
                backgroundColor: 'rgba(55, 65, 81, 0.7)',
                borderRadius: '9999px',
                color: 'white',
                paddingRight: { xs: '8px', sm: '12px' },
                '& fieldset': {
                  border: 'none'
                },
                '&:hover fieldset': {
                  border: 'none'
                },
                '&.Mui-focused fieldset': {
                  border: 'none'
                }
              },
              '& .MuiOutlinedInput-input': {
                padding: { xs: '12px 8px', sm: '16px 14px' },
                fontSize: { xs: '0.9rem', sm: '1rem' },
                '&::placeholder': {
                  color: 'rgba(255, 255, 255, 0.7)',
                  opacity: 1
                }
              }
            }}
          />

            {/* Video filename suggestions dropdown */}
            {showSuggestions && suggestions.length > 0 && (
              <Paper
                sx={{
                  position: 'absolute',
                  top: '100%',
                  left: 0,
                  right: 0,
                  mt: 0.5,
                  zIndex: 20,
                  bgcolor: '#2A303C',
                  borderRadius: '12px',
                  maxHeight: 250,
                  overflow: 'auto',
                  boxShadow: '0 8px 32px rgba(0,0,0,0.5)',
                  border: '1px solid rgba(255,255,255,0.1)',
                }}
              >
                <List dense disablePadding>
                  {suggestions.map((item) => (
                    <ListItemButton
                      key={item.id}
                      onClick={() => {
                        suggestionSelectedRef.current = true;
                        setSearchText(item.phrase);
                        setSuggestions([]);
                        setShowSuggestions(false);
                        handleSearch(new Event('submit'));
                      }}
                      sx={{
                        px: 2,
                        py: 1,
                        '&:hover': { bgcolor: 'rgba(238,29,82,0.15)' },
                        borderBottom: '1px solid rgba(255,255,255,0.05)',
                      }}
                    >
                      <SearchIcon sx={{ color: 'rgba(255,255,255,0.5)', mr: 1.5, fontSize: 20 }} />
                      <ListItemText
                        primary={item.phrase}
                        primaryTypographyProps={{ sx: { color: 'white', fontSize: '0.875rem' } }}
                      />
                    </ListItemButton>
                  ))}
                </List>
              </Paper>
            )}
          </Box>

          {/* Search Types Selector */}
          <Box sx={{
            display: 'flex',
            justifyContent: 'center',
            mb: 1
          }}>
            <FormControl size="small" sx={{ minWidth: { xs: 140, sm: 160 } }}>
              <Select
                value={selectedSearchType}
                onChange={(e) => {
                  const val = e.target.value;
                  setSelectedSearchType(val);
                  setSearchError(null);
                  
                  // Handle date specific resets
                  if (val === 'date') {
                    setDateFilterType('year');
                  } else {
                    setDateFilterType('none');
                    setSelectedDate(null);
                  }
                  
                  // Handle language specific resets
                  if (val === 'language') {
                    setSelectedLanguage('');
                    setSearchText('');
                  } else {
                    setSelectedLanguage('');
                  }
                }}
                displayEmpty
                sx={{
                  bgcolor: 'rgba(55, 65, 81, 0.7)',
                  color: 'white',
                  borderRadius: '16px',
                  fontSize: { xs: '0.8rem', sm: '0.85rem' },
                  height: { xs: 36, sm: 40 },
                  '& .MuiOutlinedInput-notchedOutline': { border: 'none' },
                  '& .MuiSvgIcon-root': { color: 'white' },
                  '&:hover': { bgcolor: 'rgba(55, 65, 81, 0.9)' }
                }}
              >
                <MenuItem value="smart">🧠 AI Smart Search</MenuItem>
                <MenuItem value="speaker">🎤 Search by Speaker</MenuItem>
                <MenuItem value="title">📝 Search by Title</MenuItem>
                <MenuItem value="date">📅 Search by Date</MenuItem>
                <MenuItem value="language">🌐 Search by Language</MenuItem>
                <MenuItem value="summary">📄 Search in Summary</MenuItem>
                <MenuItem value="transcript">🔤 Search in Transcript</MenuItem>
              </Select>
            </FormControl>
          </Box>

          {/* Language Filter — shown only when language filter is active */}
          {selectedSearchType === 'language' && (
            <Box sx={{
              display: 'flex',
              justifyContent: 'center',
              gap: { xs: 1, sm: 1.5 }
            }}>
              <FormControl size="small" sx={{ minWidth: { xs: 150, sm: 180 } }}>
                <Select
                  value={selectedLanguage}
                  onChange={(e) => {
                    const lang = e.target.value;
                    setSelectedLanguage(lang);
                    // Show selected language name in the search input
                    setSearchText(lang === 'en' ? 'English' : lang === 'ur' ? 'Urdu' : '');
                    if (searchError) {
                      setSearchError(null);
                    }
                  }}
                  displayEmpty
                  sx={{
                    bgcolor: 'rgba(55, 65, 81, 0.7)',
                    color: 'white',
                    borderRadius: '8px',
                    fontSize: { xs: '0.75rem', sm: '0.85rem' },
                    height: { xs: 32, sm: 36 },
                    '& .MuiOutlinedInput-notchedOutline': { border: 'none' },
                    '& .MuiSvgIcon-root': { color: 'white' },
                    '&:hover': { bgcolor: 'rgba(55, 65, 81, 0.9)' }
                  }}
                >
                  <MenuItem value="" disabled>{t('searchPage.selectLanguageDropdown')}</MenuItem>
                  <MenuItem value="en">🇬🇧 English</MenuItem>
                  <MenuItem value="ur">🇵🇰 Urdu</MenuItem>
                </Select>
              </FormControl>
            </Box>
          )}

          {/* Date Filter — shown for smart and date */}
          {(selectedSearchType === 'smart' || selectedSearchType === 'date') && (
            <Box sx={{
              display: 'flex',
              gap: { xs: 1, sm: 1.5 },
              flexWrap: 'wrap',
              alignItems: 'center',
              justifyContent: 'center'
            }}>
              <FormControl size="small" sx={{ minWidth: { xs: 100, sm: 120 } }}>
                <Select
                  value={dateFilterType}
                  onChange={(e) => handleDateFilterChange(e.target.value)}
                  displayEmpty
                  sx={{
                    bgcolor: 'rgba(55, 65, 81, 0.7)',
                    color: 'white',
                    borderRadius: '8px',
                    fontSize: { xs: '0.75rem', sm: '0.85rem' },
                    height: { xs: 32, sm: 36 },
                    '& .MuiOutlinedInput-notchedOutline': { border: 'none' },
                    '& .MuiSvgIcon-root': { color: 'white' },
                    '&:hover': { bgcolor: 'rgba(55, 65, 81, 0.9)' }
                  }}
                >
                  {selectedSearchType === 'smart' && <MenuItem value="none">No Date Filter</MenuItem>}
                  <MenuItem value="year">Filter by Year</MenuItem>
                  <MenuItem value="month">Filter by Month</MenuItem>
                  <MenuItem value="date">Filter by Date</MenuItem>
                </Select>
              </FormControl>

              {dateFilterType !== 'none' && (
                <LocalizationProvider dateAdapter={AdapterDayjs}>
                  <DatePicker
                    value={selectedDate}
                    onChange={(newValue) => {
                      setSelectedDate(newValue);
                      // Clear error when date changes
                      if (searchError) {
                        setSearchError(null);
                      }
                    }}
                    views={
                      dateFilterType === 'year' ? ['year'] :
                        dateFilterType === 'month' ? ['month', 'year'] :
                          ['year', 'month', 'day']
                    }
                    slotProps={{
                      textField: {
                        size: 'small',
                        placeholder: `Select ${dateFilterType}`,
                        sx: {
                          minWidth: { xs: 120, sm: 150 },
                          '& .MuiOutlinedInput-root': {
                            bgcolor: 'rgba(55, 65, 81, 0.7)',
                            color: 'white',
                            borderRadius: '8px',
                            fontSize: { xs: '0.75rem', sm: '0.85rem' },
                            height: { xs: 32, sm: 36 },
                            '& fieldset': { border: 'none' },
                            '& .MuiSvgIcon-root': { color: 'white' },
                            '&:hover': { bgcolor: 'rgba(55, 65, 81, 0.9)' }
                          },
                          '& input': {
                            color: 'white',
                            fontSize: { xs: '0.75rem', sm: '0.85rem' },
                            padding: { xs: '6px 8px', sm: '8px 12px' }
                          }
                        }
                      },
                      popper: {
                        sx: {
                          '& .MuiPaper-root': {
                            bgcolor: '#2A303C',
                            color: 'white',
                          },
                          '& .MuiPickersDay-root': {
                            color: 'white',
                            '&:hover': { bgcolor: '#374151' },
                            '&.Mui-selected': {
                              bgcolor: '#EE1D52 !important',
                              '&:hover': { bgcolor: '#dc1847 !important' }
                            }
                          },
                          '& .MuiPickersYear-yearButton': {
                            color: 'white',
                            '&:hover': { bgcolor: '#374151' },
                            '&.Mui-selected': {
                              bgcolor: '#EE1D52 !important',
                              '&:hover': { bgcolor: '#dc1847 !important' }
                            }
                          },
                          '& .MuiPickersMonth-monthButton': {
                            color: 'white',
                            '&:hover': { bgcolor: '#374151' },
                            '&.Mui-selected': {
                              bgcolor: '#EE1D52 !important',
                              '&:hover': { bgcolor: '#dc1847 !important' }
                            }
                          },
                          '& .MuiPickersCalendarHeader-root': {
                            color: 'white'
                          },
                          '& .MuiIconButton-root': {
                            color: 'white'
                          },
                          '& .MuiTypography-root': {
                            color: 'white'
                          }
                        }
                      }
                    }}
                  />
                </LocalizationProvider>
              )}
            </Box>
          )}

          {/* Search Controls */}
          <Box sx={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: { xs: 1, sm: 1.5 },
            flexWrap: 'wrap'
          }}>
            {/* Hafiz Naeem Avatar — shown in both semantic and simple modes */}
            <Tooltip
              title={
                (selectedSearchType !== 'smart')
                  ? 'Disabled when a specific search filter is active'
                  : (isHafizNaeemOnly ? t('searchPage.hafizNaeemActive') || 'Searching Hafiz Naeem - Click to disable' : t('searchPage.hafizNaeemInactive') || 'Click to search Hafiz Naeem only')
              }
              arrow
              placement="top"
            >
              <Box sx={{ position: 'relative' }}>
                <IconButton
                  onClick={() => {
                    // Don't allow toggle when a filter other than smart is active
                    if (selectedSearchType !== 'smart') {
                      return;
                    }

                    const newState = !isHafizNaeemOnly;
                    setIsHafizNaeemOnly(newState);

                    if (newState) {
                      // Activating: mark intentional so abort useEffect is skipped,
                      // set search text, then immediately fire the search.
                      intentionalSearchRef.current = true;
                      setSearchText('Hafiz Naeem Ur Rehman');
                      setSearchError(null);

                      if (abortControllerRef.current) {
                        abortControllerRef.current.abort();
                      }
                      const abortController = new AbortController();
                      abortControllerRef.current = abortController;
                      setIsSearching(true);
                      fetchSearchResults(abortController.signal, 'Hafiz Naeem Ur Rehman', true);
                    } else {
                      // Deactivating: Clear search AND cancel any pending timer
                      setSearchText('');
                      setSearchError(null);

                      // CRITICAL: Cancel the pending search timer
                      if (hafizNaeemSearchTimerRef.current) {
                        clearTimeout(hafizNaeemSearchTimerRef.current);
                        hafizNaeemSearchTimerRef.current = null;
                        console.log('🛑 Cancelled pending Hafiz Naeem search');
                      }

                      // Also abort any currently running search
                      if (abortControllerRef.current) {
                        abortControllerRef.current.abort();
                        abortControllerRef.current = null;
                        setIsSearching(false);
                      }
                    }
                  }}
                  disabled={isSearching || selectedSearchType !== 'smart'}
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
                      width: { xs: 44, sm: 50, md: 56 },
                      height: { xs: 44, sm: 50, md: 56 },
                      border: isHafizNaeemOnly ? '3px solid #EE1D52' : '3px solid rgba(255, 255, 255, 0.3)',
                      boxShadow: isHafizNaeemOnly
                        ? '0 0 20px rgba(238, 29, 82, 0.6), 0 4px 12px rgba(0, 0, 0, 0.4)'
                        : '0 4px 12px rgba(0, 0, 0, 0.3)',
                      transition: 'all 0.3s ease',
                      cursor: (isSearching || selectedSearchType !== 'smart') ? 'not-allowed' : 'pointer',
                      filter: (isSearching || selectedSearchType !== 'smart') ? 'brightness(0.7)' : 'brightness(1)',
                      opacity: (isSearching || selectedSearchType !== 'smart') ? 0.5 : 1,
                      '&:hover': {
                        border: isHafizNaeemOnly ? '3px solid #dc1847' : '3px solid rgba(255, 255, 255, 0.6)',
                        boxShadow: isHafizNaeemOnly
                          ? '0 0 25px rgba(238, 29, 82, 0.8), 0 6px 16px rgba(0, 0, 0, 0.5)'
                          : '0 6px 16px rgba(0, 0, 0, 0.4)',
                      }
                    }}
                  />
                </IconButton>
                {isSearching && (
                  <CircularProgress
                    size={20}
                    sx={{
                      position: 'absolute',
                      top: '50%',
                      left: '50%',
                      marginTop: '-10px',
                      marginLeft: '-10px',
                      color: '#EE1D52',
                    }}
                  />
                )}
              </Box>
            </Tooltip>
          </Box>
        </Box>

        {/* Error/Info Messages */}
        {searchError && (
          <Box sx={{
            width: '100%',
            bgcolor: 'rgba(239, 68, 68, 0.1)',
            border: '1px solid rgba(239, 68, 68, 0.3)',
            borderRadius: { xs: '8px', sm: '12px' },
            p: { xs: 1.5, sm: 2 },
            color: '#FCA5A5',
            fontSize: { xs: '0.8rem', sm: '0.875rem' },
            textAlign: 'center'
          }}>
            {searchError}
          </Box>
        )}

        {/* Search Button */}
        <Button
          variant="contained"
          fullWidth
          onClick={handleSearch}
          disabled={isSearching || !isSearchEnabled()}
          sx={{
            bgcolor: isSearching ? '#9CA3AF' : '#F01F32',
            borderRadius: '9999px',
            py: { xs: 1.2, sm: 1.5 },
            fontSize: { xs: '0.9rem', sm: '1rem' },
            textTransform: 'none',
            '&:hover': {
              bgcolor: isSearching ? '#9CA3AF' : '#dc1847'
            },
            '&:disabled': {
              color: 'white',
              opacity: 0.7,
              background:'#9CA3AF'
            }
          }}
        >
          {isSearching ? (
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <CircularProgress size={20} sx={{ color: 'white' }} />
              {t('searchPage.searching')}
            </Box>
          ) : (
            t('searchPage.searchButton')
          )}
        </Button>

        {/* Help Text */}
        <Box sx={{ 
          color: 'white',
          textAlign: 'center',
          opacity: 0.8,
          fontSize: { xs: '0.8rem', sm: '0.875rem' },
          px: { xs: 1, sm: 0 }
        }}>
          {t('searchPage.helpText')}
        </Box>
      </Box>
    </Box>
  )
}
