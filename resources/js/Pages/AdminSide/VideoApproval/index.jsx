import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import React, { useEffect, useState } from 'react';
import { Box, Tabs, Tab, TextField, InputAdornment, Button, CircularProgress, Dialog, DialogTitle, DialogContent, IconButton, Typography, ButtonGroup } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { usePage } from '@inertiajs/react';
import SearchIcon from '@mui/icons-material/Search';
import SyncIcon from '@mui/icons-material/Sync';
import CloseIcon from '@mui/icons-material/Close';
import DownloadIcon from '@mui/icons-material/Download';
import WarningIcon from '@mui/icons-material/Warning';
import OpenInNewIcon from '@mui/icons-material/OpenInNew';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import Swal from 'sweetalert2';

import Pending from './Pending';
import Approved from './Approved';
import Rejected from './Rejected';
import Archived from './Archived';
import Failed from './Failed';

// Browser-supported video formats for HTML5 video element
const BROWSER_SUPPORTED_FORMATS = ['mp4', 'webm', 'ogg', 'ogv'];

export default function Index({ canViewArchived, canManageVideos }) {
  const { t } = useTranslation();
  const { auth } = usePage().props;
  const [currentTab, setCurrentTab] = useState(0);
  const [videos, setVideos] = useState([]);
  const [paginationModel, setPaginationModel] = useState({
    page: 0,
    pageSize: 10
  });
  const [rowCount, setRowCount] = useState(0);
  const [loading, setLoading] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedRows, setSelectedRows] = useState([]);
  const [isSyncing, setIsSyncing] = useState(false);
  const [sortBy, setSortBy] = useState('newest'); // 'newest' or 'oldest'
  
  // Video preview state
  const [previewOpen, setPreviewOpen] = useState(false);
  const [previewUrl, setPreviewUrl] = useState('');
  const [previewTitle, setPreviewTitle] = useState('');
  const [previewLoading, setPreviewLoading] = useState(false);
  const [previewFormat, setPreviewFormat] = useState('');
  const [previewFilename, setPreviewFilename] = useState('');
  const [dropboxPreviewUrl, setDropboxPreviewUrl] = useState('');
  const [videoError, setVideoError] = useState(false);
  // Tab configuration based on role
  const tabs = canViewArchived 
    ? ['archived', 'pending', 'approved', 'rejected', 'failed']
    : ['pending', 'approved', 'rejected', 'failed'];
  
  const tabLabels = canViewArchived
    ? [t('videoApproval.tabs.archived'), t('videoApproval.tabs.pending'), t('videoApproval.tabs.approved'), t('videoApproval.tabs.rejected'), t('videoApproval.tabs.failed') || 'Failed']
    : [t('videoApproval.tabs.pending'), t('videoApproval.tabs.approved'), t('videoApproval.tabs.rejected'), t('videoApproval.tabs.failed') || 'Failed'];

  const handleTabChange = (event, newValue) => {
    setCurrentTab(newValue);
    setPaginationModel({ page: 0, pageSize: 10 });
    setSearchQuery('');
    setSelectedRows([]);
    setSortBy('newest'); // Reset to newest when changing tabs
  };

  const handleSearchChange = (event) => {
    setSearchQuery(event.target.value);
    setPaginationModel({ page: 0, pageSize: paginationModel.pageSize });
  };

  const fetchVideos = async (page = 0, pageSize = 10, search = '', sort = 'newest') => {
    setLoading(true);
    try {
      const tab = tabs[currentTab];
      const url = `/admin/video-approval/list?page=${page + 1}&per_page=${pageSize}&tab=${tab}${search ? `&search=${encodeURIComponent(search)}` : ''}&sort=${sort}`;
      
      const response = await fetch(url, {
        method: 'GET',
        headers: {
          Accept: 'application/json',
        },
      });

      if (!response.ok) {
        throw new Error('Failed to fetch videos');
      }

      const data = await response.json();
      setVideos(data.data);
      setRowCount(data.total);

    } catch (error) {
      console.error('Error fetching videos:', error);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'Failed to load videos',
      });
    } finally {
      setLoading(false);
    }
  };

  const handlePaginationModelChange = (newPaginationModel) => {
    setPaginationModel(newPaginationModel);
  };

  const handleSelectionChange = (newSelection) => {
    console.log('Raw selection from DataGrid:', newSelection);
    console.log('Type:', typeof newSelection, Array.isArray(newSelection) ? 'array' : '', newSelection instanceof Set ? 'Set' : '');
    console.log('Videos available:', videos.length, videos.map(v => v.id));
    // Convert selection to array format for consistent handling
    let selectionArray = [];

    if (Array.isArray(newSelection)) {
      selectionArray = newSelection;
    } else if (newSelection instanceof Set) {
      selectionArray = Array.from(newSelection);
    } else if (newSelection && typeof newSelection === 'object') {
      // Handle selection model object: {type: 'include'/'exclude', ids: Set}
      if (newSelection.type === 'exclude' && newSelection.ids instanceof Set) {
        // "Select all" on current page: exclude mode with empty set = select all visible rows
        if (newSelection.ids.size === 0) {
          selectionArray = videos.map(video => video.id);
        } else {
          // Some rows excluded - select all except excluded ones
          const excludedIds = Array.from(newSelection.ids);
          selectionArray = videos.filter(video => !excludedIds.includes(video.id)).map(video => video.id);
        }
      } else if (newSelection.type === 'include' && newSelection.ids instanceof Set) {
        // Include mode: select specific rows
        selectionArray = Array.from(newSelection.ids);
      } else if (newSelection.ids instanceof Set) {
        selectionArray = Array.from(newSelection.ids);
      } else if (newSelection.ids) {
        selectionArray = Object.keys(newSelection.ids).map(Number);
      }
    }

    setSelectedRows(selectionArray);
    console.log('Converted to array:', selectionArray);
  };

  // Bulk actions
  const handleBulkAction = async (action) => {
    if (selectedRows.length === 0) {
      Swal.fire({
        icon: 'warning',
        title: 'No Selection',
        text: 'Please select videos first',
      });
      return;
    }

    const actionLabels = {
      approve: 'approve',
      reject: 'reject', 
      archive: 'archive',
      unarchive: 'unarchive',
      'reset-status': 'reset to pending',
    };

    const result = await Swal.fire({
      title: `${actionLabels[action].charAt(0).toUpperCase() + actionLabels[action].slice(1)} Videos?`,
      text: `Are you sure you want to ${actionLabels[action]} ${selectedRows.length} video(s)?`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#EE1D52',
      cancelButtonColor: '#6B7280',
      confirmButtonText: `Yes, ${actionLabels[action]}`,
      ...(action === 'reject' ? {
        input: 'textarea',
        inputLabel: t('videoApproval.reject.reasonLabel') || 'Reason for rejection',
        inputPlaceholder: t('videoApproval.reject.reasonPlaceholder') || 'Enter rejection reason...',
        inputValidator: (value) => {
          if (!value || !value.trim()) {
            return t('videoApproval.reject.reasonRequired') || 'Please provide a reason for rejection';
          }
        }
      } : {}),
    });

    if (!result.isConfirmed) return;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    try {
      const bodyData = { video_ids: selectedRows };
      if (action === 'reject' && result.value) {
        bodyData.rejection_reason = result.value;
      }

      const response = await fetch(`/admin/video-approval/bulk-${action}`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify(bodyData),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || data.message || `Failed to ${action} videos`);
      }

      Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: data.message,
        timer: 2000,
        showConfirmButton: false,
      });

      setSelectedRows([]);
      fetchVideos(paginationModel.page, paginationModel.pageSize, searchQuery);

    } catch (error) {
      console.error(`Error bulk ${action}:`, error);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: error.message,
      });
    }
  };

  // Single video action
  const handleSingleAction = async (videoId, action) => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    // Show rejection reason popup for reject action
    if (action === 'reject') {
      const { value: reason, isConfirmed } = await Swal.fire({
        title: t('videoApproval.reject.title') || 'Reject Video',
        input: 'textarea',
        inputLabel: t('videoApproval.reject.reasonLabel') || 'Reason for rejection',
        inputPlaceholder: t('videoApproval.reject.reasonPlaceholder') || 'Enter rejection reason...',
        showCancelButton: true,
        confirmButtonColor: '#EF4444',
        cancelButtonColor: '#6B7280',
        confirmButtonText: t('videoApproval.buttons.reject') || 'Reject',
        cancelButtonText: t('videoApproval.reject.cancel') || 'Cancel',
        inputValidator: (value) => {
          if (!value || !value.trim()) {
            return t('videoApproval.reject.reasonRequired') || 'Please provide a reason for rejection';
          }
        }
      });

      if (!isConfirmed) return;

      try {
        const response = await fetch(`/admin/video-approval/${videoId}/${action}`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
          },
          body: JSON.stringify({ rejection_reason: reason }),
        });

        const data = await response.json();

        if (!response.ok) {
          throw new Error(data.error || data.message || 'Failed to reject video');
        }

        Swal.fire({
          icon: 'success',
          title: 'Success!',
          text: data.message,
          timer: 1500,
          showConfirmButton: false,
        });

        fetchVideos(paginationModel.page, paginationModel.pageSize, searchQuery);
      } catch (error) {
        console.error('Error rejecting video:', error);
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: error.message,
        });
      }
      return;
    }

    try {
      const response = await fetch(`/admin/video-approval/${videoId}/${action}`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || data.message || `Failed to ${action} video`);
      }

      Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: data.message,
        timer: 1500,
        showConfirmButton: false,
      });

      fetchVideos(paginationModel.page, paginationModel.pageSize, searchQuery);

    } catch (error) {
      console.error(`Error ${action} video:`, error);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: error.message,
      });
    }
  };

  // Preview video from Dropbox
  const handlePreview = async (videoId, videoTitle) => {
    console.log('=== Preview Requested ===');
    console.log('Video ID:', videoId);
    console.log('Video Title:', videoTitle);
    
    // Clear previous video first
    setPreviewUrl('');
    setDropboxPreviewUrl('');
    setPreviewFormat('');
    setPreviewFilename('');
    setVideoError(false);
    setPreviewLoading(true);
    setPreviewTitle(videoTitle);
    
    try {
      console.log('Fetching preview link...');
      const response = await fetch(`/admin/video-approval/${videoId}/preview-link`, {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
        },
      });

      console.log('Response status:', response.status);
      const data = await response.json();
      console.log('Response data:', data);

      if (!response.ok) {
        console.error('API Error:', data.error);
        throw new Error(data.error || 'Failed to get preview link');
      }

      console.log('Preview URL received:', data.preview_url?.substring(0, 100) + '...');
      console.log('Dropbox path:', data.dropbox_path);
      
      // Extract format from dropbox path or filename
      const dropboxPath = data.dropbox_path || '';
      const filename = dropboxPath.split('/').pop() || videoTitle;
      const extension = filename.split('.').pop()?.toLowerCase() || '';
      
      console.log('Video format:', extension);
      console.log('Is browser supported:', BROWSER_SUPPORTED_FORMATS.includes(extension));
      
      // If format is not browser-supported, open Dropbox directly instead of popup
      if (!BROWSER_SUPPORTED_FORMATS.includes(extension) && data.dropbox_preview_url) {
        console.log('Non-supported format, opening Dropbox directly:', data.dropbox_preview_url);
        window.open(data.dropbox_preview_url, '_blank');
        return;
      }
      
      setPreviewUrl(data.preview_url);
      setDropboxPreviewUrl(data.dropbox_preview_url || '');
      setPreviewFormat(extension);
      setPreviewFilename(filename);
      setPreviewOpen(true);
      console.log('Preview dialog opened');
      console.log('Dropbox preview URL:', data.dropbox_preview_url);

    } catch (error) {
      console.error('Error getting preview link:', error);
      Swal.fire({
        icon: 'error',
        title: t('videoApproval.preview.errorTitle') || 'Preview Error',
        text: error.message || t('videoApproval.preview.errorText') || 'Failed to load video preview',
      });
    } finally {
      setPreviewLoading(false);
    }
  };

  const handleClosePreview = () => {
    setPreviewOpen(false);
    setPreviewUrl('');
    setDropboxPreviewUrl('');
    setPreviewTitle('');
    setPreviewFormat('');
    setPreviewFilename('');
    setVideoError(false);
  };

  const handleDownloadVideo = () => {
    if (previewUrl) {
      window.open(previewUrl, '_blank');
    }
  };

  const handleOpenInDropbox = () => {
    if (dropboxPreviewUrl) {
      window.open(dropboxPreviewUrl, '_blank');
    }
  };

  const isBrowserSupported = BROWSER_SUPPORTED_FORMATS.includes(previewFormat);

  // Sync videos from Dropbox (without auto-processing)
  const handleSyncDropbox = async () => {
    const result = await Swal.fire({
      title: t('videoApproval.syncDropbox.confirmTitle') || 'Sync From Dropbox?',
      text: t('videoApproval.syncDropbox.confirmText') || 'This will fetch all videos from Dropbox. Videos will need to be approved before processing.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#EE1D52',
      cancelButtonColor: '#6B7280',
      confirmButtonText: t('videoApproval.syncDropbox.confirmButton') || 'Yes, sync now',
      cancelButtonText: t('videoApproval.syncDropbox.cancelButton') || 'Cancel'
    });

    if (!result.isConfirmed) return;

    setIsSyncing(true);
    Swal.fire({
      icon: 'info',
      title: t('videoApproval.syncDropbox.syncingTitle') || 'Syncing',
      text: t('videoApproval.syncDropbox.syncingText') || 'Fetching videos from Dropbox...',
      timer: 2000,
      showConfirmButton: false,
      timerProgressBar: true
    });

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    try {
      // auto_process=false means only sync, don't process
      const response = await fetch('/list-videos?auto_process=false', {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken
        }
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'Failed to sync videos');
      }

      // Calculate total pending videos (new + existing)
      const totalPending = (data.summary?.synced_pending_approval || 0) + (data.summary?.existing_pending_approval || 0);

      Swal.fire({
        icon: 'success',
        title: t('videoApproval.syncDropbox.successTitle') || 'Sync Complete!',
        html: `
          <div style="text-align: left;">
            <p><strong>${t('videoApproval.syncDropbox.totalInDropbox') || 'Total in Dropbox'}:</strong> ${data.summary?.total_in_dropbox || 0}</p>
            <p style="color: ${totalPending > 0 ? '#F59E0B' : 'inherit'}"><strong>${t('videoApproval.syncDropbox.pendingApproval') || 'Pending Approval'}:</strong> ${totalPending}</p>
            <p style="color: ${(data.summary?.already_processed || 0) > 0 ? '#22C55E' : 'inherit'}"><strong>${t('videoApproval.syncDropbox.alreadyProcessed') || 'Already Processed'}:</strong> ${data.summary?.already_processed || 0}</p>
          </div>
        `,
        timer: 4000,
        showConfirmButton: true
      });

      // Refresh the video list
      fetchVideos(paginationModel.page, paginationModel.pageSize, searchQuery);

    } catch (error) {
      console.error('Error syncing from Dropbox:', error);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: error.message || 'Failed to sync from Dropbox'
      });
    } finally {
      setIsSyncing(false);
    }
  };

  useEffect(() => {
    fetchVideos(paginationModel.page, paginationModel.pageSize, searchQuery, sortBy);
  }, [currentTab, paginationModel, searchQuery, sortBy]);

  const commonProps = {
    videos,
    loading,
    rowCount,
    paginationModel,
    onPaginationModelChange: handlePaginationModelChange,
    selectedRows,
    onSelectionChange: handleSelectionChange,
    onSingleAction: handleSingleAction,
    onPreview: handlePreview,
    previewLoading,
    canViewArchived,
    canManageVideos,
  };

  // Determine which component to render based on current tab
  const renderTabContent = () => {
    const tab = tabs[currentTab];
    switch (tab) {
      case 'archived':
        return <Archived {...commonProps} />;
      case 'approved':
        return <Approved {...commonProps} />;
      case 'rejected':
        return <Rejected {...commonProps} />;
      case 'failed':
        return <Failed {...commonProps} />;
      case 'pending':
      default:
        return <Pending {...commonProps} />;
    }
  };

  // Get bulk action buttons based on current tab
  const getBulkActions = () => {
    // Managers cannot perform bulk actions
    if (!canManageVideos) {
      return [];
    }

    const tab = tabs[currentTab];
    const buttons = [];

    if (tab === 'pending') {
      // Pending tab: Approve + Reject (matching single row actions)
      buttons.push(
        <Button
          key="approve"
          variant="contained"
          disabled={selectedRows.length === 0}
          onClick={() => handleBulkAction('approve')}
          sx={{
            backgroundColor: selectedRows.length === 0 ? '#3C3C3C' : '#22C55E',
            '&:hover': { backgroundColor: '#16A34A' },
            textTransform: 'none',
            fontSize: '14px',
          }}
        >
          {t('videoApproval.buttons.approve')} ({selectedRows.length})
        </Button>,
        <Button
          key="reject"
          variant="contained"
          disabled={selectedRows.length === 0}
          onClick={() => handleBulkAction('reject')}
          sx={{
            backgroundColor: selectedRows.length === 0 ? '#3C3C3C' : '#EF4444',
            '&:hover': { backgroundColor: '#DC2626' },
            textTransform: 'none',
            fontSize: '14px',
          }}
        >
          {t('videoApproval.buttons.reject')} ({selectedRows.length})
        </Button>
      );
    } else if (tab === 'approved') {
      // Approved tab: Archive only (matching single row actions)
      buttons.push(
        <Button
          key="archive"
          variant="contained"
          disabled={selectedRows.length === 0}
          onClick={() => handleBulkAction('archive')}
          sx={{
            backgroundColor: selectedRows.length === 0 ? '#3C3C3C' : '#6B7280',
            '&:hover': { backgroundColor: '#4B5563' },
            textTransform: 'none',
            fontSize: '14px',
          }}
        >
          {t('videoApproval.buttons.archive')} ({selectedRows.length})
        </Button>
      );
    } else if (tab === 'rejected' || tab === 'failed') {
      // Rejected/Failed tab: Reset to Pending only (matching single row actions)
      buttons.push(
        <Button
          key="reset-status"
          variant="contained"
          disabled={selectedRows.length === 0}
          onClick={() => handleBulkAction('reset-status')}
          sx={{
            backgroundColor: selectedRows.length === 0 ? '#3C3C3C' : '#F59E0B',
            '&:hover': { backgroundColor: '#D97706' },
            textTransform: 'none',
            fontSize: '14px',
          }}
        >
          {t('videoApproval.buttons.resetToPending')} ({selectedRows.length})
        </Button>
      );
    } else if (tab === 'archived') {
      // Archived tab: Unarchive only (matching single row actions)
      buttons.push(
        <Button
          key="unarchive"
          variant="contained"
          disabled={selectedRows.length === 0}
          onClick={() => handleBulkAction('unarchive')}
          sx={{
            backgroundColor: selectedRows.length === 0 ? '#3C3C3C' : '#6366F1',
            '&:hover': { backgroundColor: '#4F46E5' },
            textTransform: 'none',
            fontSize: '14px',
          }}
        >
          {t('videoApproval.buttons.unarchive')} ({selectedRows.length})
        </Button>
      );
    }

    return buttons;
  };

  return (
    <AuthenticatedLayout
      header={<h2 className="font-semibold text-2xl text-white leading-tight">{t('videoApproval.title')}</h2>}
    >
      <Box>
        {/* Search Bar and Sort Controls */}
        <Box sx={{ mb: 3, display: 'flex', gap: 2, alignItems: 'center' }}>
          <TextField
            fullWidth
            placeholder={t('videoApproval.searchPlaceholder') || 'Search videos by title, filename, or description...'}
            value={searchQuery}
            onChange={handleSearchChange}
            InputProps={{
              startAdornment: (
                <InputAdornment position="start">
                  <SearchIcon sx={{ color: '#9CA3AF' }} />
                </InputAdornment>
              ),
              sx: {
                bgcolor: '#1F2324',
                borderRadius: 2,
                color: 'white',
                '& .MuiOutlinedInput-notchedOutline': {
                  borderColor: '#374151',
                },
                '&:hover .MuiOutlinedInput-notchedOutline': {
                  borderColor: '#4B5563',
                },
                '&.Mui-focused .MuiOutlinedInput-notchedOutline': {
                  borderColor: '#EE1D52',
                },
              },
            }}
            sx={{
              '& .MuiInputBase-input::placeholder': {
                color: '#6B7280',
                opacity: 1,
              },
            }}
          />
          <ButtonGroup variant="outlined" sx={{ flexShrink: 0 }}>
            <Button
              onClick={() => setSortBy('newest')}
              sx={{
                backgroundColor: sortBy === 'newest' ? '#EE1D52' : 'transparent',
                color: sortBy === 'newest' ? 'white' : '#9CA3AF',
                borderColor: '#374151',
                textTransform: 'none',
                minWidth: '100px',
                '&:hover': {
                  backgroundColor: sortBy === 'newest' ? '#dc1847' : 'rgba(238, 29, 82, 0.1)',
                  borderColor: '#374151',
                },
              }}
              startIcon={<ArrowDownwardIcon />}
            >
              {t('videoApproval.sort.newest') || 'Newest'}
            </Button>
            <Button
              onClick={() => setSortBy('oldest')}
              sx={{
                backgroundColor: sortBy === 'oldest' ? '#EE1D52' : 'transparent',
                color: sortBy === 'oldest' ? 'white' : '#9CA3AF',
                borderColor: '#374151',
                textTransform: 'none',
                minWidth: '100px',
                '&:hover': {
                  backgroundColor: sortBy === 'oldest' ? '#dc1847' : 'rgba(238, 29, 82, 0.1)',
                  borderColor: '#374151',
                },
              }}
              startIcon={<ArrowUpwardIcon />}
            >
              {t('videoApproval.sort.oldest') || 'Oldest'}
            </Button>
          </ButtonGroup>
        </Box>

        {/* Tabs */}
        <Box
          sx={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            mb: 3,
            overflow: { xs: 'auto', sm: 'visible' },
          }}
        >
          <Box sx={{ display: 'flex', gap: { xs: 0, sm: 10 }, width: '100%' }}>
            <Tabs
              value={currentTab}
              onChange={handleTabChange}
              variant="scrollable"
              scrollButtons="auto"
              allowScrollButtonsMobile
              TabIndicatorProps={{
                style: {
                  backgroundColor: '#EE1D52',
                  height: '2px',
                },
              }}
              sx={{
                minHeight: 'unset',
                width: '100%',
                '& .MuiTabs-flexContainer': {
                  gap: { xs: '12px', sm: '20px' },
                },
                '& .MuiTab-root': {
                  color: '#9CA3AF',
                  fontSize: { xs: '14px', sm: '16px' },
                  textTransform: 'none',
                  minHeight: 'unset',
                  padding: { xs: '2px 8px', sm: '2px 2px' },
                  paddingBottom: '4px',
                  whiteSpace: 'nowrap',
                  '&.Mui-selected': {
                    color: '#EE1D52',
                  },
                },
              }}
            >
              {tabLabels.map((label, index) => (
                <Tab key={index} label={label} disableRipple />
              ))}
            </Tabs>
          </Box>
        </Box>

        {/* Bulk Action Buttons + Sync From Dropbox */}
        <Box sx={{ display: 'flex', gap: 2, mb: 3, flexWrap: 'wrap', justifyContent: 'space-between', alignItems: 'center' }}>
          <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap' }}>
            {getBulkActions()}
          </Box>
          {canManageVideos && (
            <Button
              variant="contained"
              onClick={handleSyncDropbox}
              disabled={isSyncing}
              startIcon={isSyncing ? <CircularProgress size={16} color="inherit" /> : <SyncIcon />}
              sx={{
                backgroundColor: '#EE1D52',
                '&:hover': { backgroundColor: '#dc1847' },
                '&:disabled': { backgroundColor: '#6B7280' },
                textTransform: 'none',
                fontSize: '14px',
                px: 3,
              }}
            >
              {isSyncing
                ? (t('videoApproval.syncDropbox.syncing') || 'Syncing...')
                : (t('videoApproval.syncDropbox.button') || 'Sync From Dropbox')
              }
            </Button>
          )}
        </Box>

        {/* Tab Content */}
        <Box sx={{ p: 2 }}>
          {renderTabContent()}
        </Box>
      </Box>

      {/* Video Preview Dialog */}
      <Dialog
        open={previewOpen}
        onClose={handleClosePreview}
        maxWidth="lg"
        fullWidth
        PaperProps={{
          sx: {
            backgroundColor: '#1a1a1a',
            borderRadius: 2,
          }
        }}
      >
        <DialogTitle sx={{ 
          display: 'flex', 
          justifyContent: 'space-between', 
          alignItems: 'center',
          color: 'white',
          borderBottom: '1px solid #333'
        }}>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            {previewTitle || t('videoApproval.preview.title') || 'Video Preview'}
            {previewFormat && (
              <Typography 
                component="span" 
                sx={{ 
                  fontSize: '12px', 
                  color: isBrowserSupported ? '#22C55E' : '#F59E0B',
                  backgroundColor: isBrowserSupported ? 'rgba(34, 197, 94, 0.1)' : 'rgba(245, 158, 11, 0.1)',
                  px: 1,
                  py: 0.5,
                  borderRadius: 1,
                  textTransform: 'uppercase'
                }}
              >
                {previewFormat}
              </Typography>
            )}
          </Box>
          <IconButton onClick={handleClosePreview} sx={{ color: '#9CA3AF' }}>
            <CloseIcon />
          </IconButton>
        </DialogTitle>
        <DialogContent sx={{ p: 0, backgroundColor: '#000' }}>
          {previewUrl && isBrowserSupported && !videoError && (
            <video
              key={previewUrl}
              controls
              autoPlay
              style={{ 
                width: '100%', 
                maxHeight: '70vh',
                display: 'block'
              }}
              src={previewUrl}
              onLoadStart={() => console.log('Video loading started:', previewUrl?.substring(0, 80))}
              onCanPlay={() => console.log('Video can play')}
              onError={(e) => {
                console.error('Video error:', e);
                console.error('Video error code:', e.target.error?.code);
                console.error('Video error message:', e.target.error?.message);
                console.error('Video src:', e.target.src?.substring(0, 100));
                setVideoError(true);
              }}
            >
              {t('videoApproval.preview.unsupported') || 'Your browser does not support the video tag.'}
            </video>
          )}
          {previewUrl && (!isBrowserSupported || videoError) && (
            <Box sx={{ 
              display: 'flex', 
              flexDirection: 'column', 
              alignItems: 'center', 
              justifyContent: 'center',
              minHeight: '300px',
              p: 4,
              textAlign: 'center'
            }}>
              <WarningIcon sx={{ fontSize: 64, color: '#F59E0B', mb: 2 }} />
              <Typography variant="h6" sx={{ color: 'white', mb: 1, fontWeight: 600 }}>
                {videoError
                  ? t('videoApproval.preview.codecNotSupported') || 'Codec Not Supported for Browser Preview'
                  : t('videoApproval.preview.formatNotSupported') || 'Format Not Supported for Browser Preview'}
              </Typography>
              <Typography sx={{ color: '#9CA3AF', mb: 4, maxWidth: '400px', lineHeight: 1.6 }}>
                {videoError
                  ? t('videoApproval.preview.codecNotSupportedDesc') || 'This video uses an unsupported codec (like HEVC/H.265) which cannot be played directly in your browser. You can download the video or view it in Dropbox.'
                  : t('videoApproval.preview.formatNotSupportedDesc') || 'This video format cannot be played directly in the browser. Supported formats are: MP4, WebM, OGG. You can download the video or view it in Dropbox.'}
              </Typography>
              <Typography sx={{ color: '#6B7280', fontSize: '13px', mb: 3 }}>
                {previewFilename}
              </Typography>
              <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', justifyContent: 'center' }}>
                {dropboxPreviewUrl && (
                  <Button
                    variant="contained"
                    startIcon={<OpenInNewIcon />}
                    onClick={handleOpenInDropbox}
                    sx={{
                      backgroundColor: '#0061FF',
                      '&:hover': { backgroundColor: '#0052d4' },
                      textTransform: 'none',
                      px: 4,
                      py: 1.5
                    }}
                  >
                    {t('videoApproval.preview.openInDropbox') || 'Open in Dropbox'}
                  </Button>
                )}
                <Button
                  variant="contained"
                  startIcon={<DownloadIcon />}
                  onClick={handleDownloadVideo}
                  sx={{
                    backgroundColor: '#EE1D52',
                    '&:hover': { backgroundColor: '#dc1847' },
                    textTransform: 'none',
                    px: 4,
                    py: 1.5
                  }}
                >
                  {t('videoApproval.preview.downloadButton') || 'Download Video'}
                </Button>
              </Box>
            </Box>
          )}
        </DialogContent>
      </Dialog>
    </AuthenticatedLayout>
  );
}
