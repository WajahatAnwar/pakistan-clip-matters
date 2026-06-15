import React, { useMemo } from 'react';
import Swal from 'sweetalert2';
import { Box, Typography, Button, Tooltip, IconButton, CircularProgress } from '@mui/material';
import { DataGrid } from '@mui/x-data-grid';
import { useTranslation } from 'react-i18next';
import CheckIcon from '@mui/icons-material/Check';
import CloseIcon from '@mui/icons-material/Close';
import ArchiveIcon from '@mui/icons-material/Archive';
import PlayArrowIcon from '@mui/icons-material/PlayArrow';
import { DataGridLoadingOverlay, DataGridNoRowsOverlay } from '@/Components/LoadingOverlay';
import RandomImage from '@/Images/random-data-grid.svg';
import { getYoutubeThumbnail, getProcessingStatus, formatTimestamp } from '@/Utils/videoHelpers';

// Import pipeline step icons
import DropBox from '@/Images/DropBox.svg';
import Youtube from '@/Images/Youtube.svg';
import Transcript from '@/Images/Transcript.svg';
import Speaker from '@/Images/Speaker.svg';
import ActiveTranscript from '@/Images/ActiveTranscript.svg';
import ActiveSpeaker from '@/Images/ActiveSpeaker.svg';
import SearchReady from '@/Images/SearchReady.svg';
import ActiveSearchReady from '@/Images/ActiveSearchReady.svg';

export default function Pending({ 
  videos, 
  loading, 
  rowCount, 
  paginationModel, 
  onPaginationModelChange,
  selectedRows,
  onSelectionChange,
  onSingleAction,
  onPreview,
  previewLoading,
  canManageVideos
}) {
  const { t } = useTranslation();

  const rows = useMemo(() => {
    if (!videos || !Array.isArray(videos)) return [];
    
    return videos.map(video => {
      const processingStatus = getProcessingStatus(video);
      return {
        id: video.id,
        videoTitle: video.title || video.filename || 'Untitled Video',
        thumbnail: RandomImage,
        approval_status: video.approval_status,
        processing_status: video.processing_status || 'pending',
        youtube_url: video.youtube_url,
        owner: video.user?.name || 'Unknown',
        video_created_at: video.video_created_at,
        created_at: video.created_at,
        isTracked: video.isTracked,
        completedSteps: processingStatus.completedSteps,
        totalSteps: processingStatus.totalSteps,
        hasYoutube: processingStatus.hasYoutube,
        failedStep: processingStatus.failedStep,
        onDropbox: video.onDropbox,
        onYoutube: video.onYoutube,
        isTranscript: video.isTranscript,
        isTagged: video.isTagged,
        hasEmbedding: video.hasEmbedding,
                notes: video.notes,
      };
    });
  }, [videos]);

  const columns = [
    {
      field: 'videoTitle',
      headerName: t('videoApproval.table.videoTitle'),
      flex: 1.5,
      minWidth: 250,
      renderCell: (params) => (
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <img 
            src={params.row.thumbnail} 
            alt={params.value} 
            style={{ width: 50, height: 38, borderRadius: 4, objectFit: 'cover' }} 
          />
          <Tooltip title={params.value} arrow>
            <Typography
              sx={{
                color: 'white',
                fontSize: '14px',
                overflow: 'hidden',
                textOverflow: 'ellipsis',
                whiteSpace: 'nowrap',
                maxWidth: 'calc(100% - 60px)'
              }}
            >
              {params.value}
            </Typography>
          </Tooltip>
                    {params.row.notes?.includes('Video has no audio stream') && (
                        <Button
                            size="small"
                            onClick={(e) => {
                                e.stopPropagation();
                                Swal.fire({
                                    title: 'No Audio Detected',
                                    text: 'This video has no audio stream and was processed successfully without transcription.',
                                    icon: 'info',
                                    confirmButtonColor: '#EE1D52'
                                });
                            }}
                            sx={{ 
                                minWidth: 'auto', 
                                p: '2px 6px', 
                                ml: 1, 
                                bgcolor: '#FEF3C7', 
                                color: '#D97706',
                                textTransform: 'none',
                                '&:hover': { bgcolor: '#FDE68A' }
                            }}
                        >
                            <Typography sx={{ fontSize: '11px', fontWeight: 'bold' }}>No Audio</Typography>
                        </Button>
                    )}
        </Box>
      )
    },
    {
      field: 'owner',
      headerName: t('videoApproval.table.owner'),
      flex: 0.7,
      minWidth: 130,
      renderCell: (params) => (
        <Tooltip title={params.value} arrow>
          <Typography sx={{ 
            color: '#9CA3AF', 
            fontSize: '14px',
            overflow: 'hidden',
            textOverflow: 'ellipsis',
            whiteSpace: 'nowrap',
            maxWidth: '100%'
          }}>
            {params.value}
          </Typography>
        </Tooltip>
      )
    },
    {
      field: 'status',
      headerName: t('videoApproval.table.processingStatus'),
      headerAlign: 'center',
      flex: 1.8,
      minWidth: 320,
      sortable: false,
      renderCell: (params) => {
        const steps = params.row.hasYoutube
          ? [DropBox, Youtube, Transcript, Speaker, SearchReady]
          : [DropBox, Transcript, Speaker, SearchReady];
        const stepLabels = params.row.hasYoutube
          ? [
            t('videoManagement.status.dropbox'),
            t('videoManagement.status.youtube'),
            t('videoManagement.status.transcript'),
            t('videoManagement.status.speakerTagging'),
            t('videoManagement.status.searchReady')
          ]
          : [
            t('videoManagement.status.dropbox'),
            t('videoManagement.status.transcript'),
            t('videoManagement.status.speakerTagging'),
            t('videoManagement.status.searchReady')
          ];
        // Map original indices to determine which icon to use for active state
        const activeIconMap = params.row.hasYoutube
          ? { 2: ActiveTranscript, 3: ActiveSpeaker, 4: ActiveSearchReady }
          : { 1: ActiveTranscript, 2: ActiveSpeaker, 3: ActiveSearchReady };

        return (
          <Box sx={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            width: '100%',
            p: 0,
            position: 'relative'
          }}>
            <Box sx={{
              display: 'flex',
              flexDirection: 'column',
              width: { xs: '100%', sm: '95%' },
              position: 'relative',
            }}>
              {/* Icons Row */}
              <Box sx={{ display: 'flex', alignItems: 'center' }}>
                {steps.map((stepIconSrc, index) => {
                  const isCompleted = params.row.completedSteps > index;
                  const isFailed = params.row.failedStep === index;

                  let iconSrc = stepIconSrc;
                  if (isCompleted && activeIconMap[index]) iconSrc = activeIconMap[index];

                  return (
                    <React.Fragment key={index}>
                      {index > 0 && (
                        <Box
                          sx={{
                            flex: 1,
                            height: '8px',
                            backgroundColor: isCompleted ? '#22C55E' : isFailed ? '#EF4444' : '#3C3C3C',
                            borderRadius: '4px',
                            mx: '-2px'
                          }}
                        />
                      )}
                      <Box
                        sx={{
                          width: { xs: 26, sm: 30 },
                          height: { xs: 26, sm: 30 },
                          borderRadius: '50%',
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                          backgroundColor: isCompleted ? 'rgba(34, 197, 94, 0.2)' : isFailed ? 'rgba(239, 68, 68, 0.2)' : '#222222',
                          border: `2px solid ${isCompleted ? '#22C55E' : isFailed ? '#EF4444' : '#3C3C3C'}`,
                          zIndex: 1
                        }}
                      >
                        <img src={iconSrc} alt="" style={{ width: 14, height: 14 }} />
                      </Box>
                    </React.Fragment>
                  );
                })}
              </Box>
              {/* Labels Row */}
              <Box sx={{ display: { xs: 'none', sm: 'flex' }, justifyContent: 'space-between', mt: 0.5, px: 0 }}>
                {stepLabels.map((label, index) => {
                  const isFailed = params.row.failedStep === index;
                  return (
                    <Typography
                      key={label}
                      sx={{
                        color: isFailed ? '#EF4444' : '#6B7280',
                        fontSize: '10px',
                        fontWeight: isFailed ? 600 : 400,
                        textAlign: 'center',
                        flex: 1,
                        whiteSpace: 'nowrap'
                      }}
                    >
                      {label}
                    </Typography>
                  );
                })}
              </Box>
            </Box>
          </Box>
        );
      }
    },
    {
      field: 'video_created_at',
      headerName: t('videoApproval.table.videoDate'),
      flex: 0.6,
      minWidth: 100,
      renderCell: (params) => (
        <Typography sx={{ color: '#9CA3AF', fontSize: '13px' }}>
          {params.value ? new Date(params.value).toLocaleDateString() : '-'}
        </Typography>
      )
    },
    {
      field: 'actions',
      headerName: t('videoApproval.table.actions'),
      flex: 1,
      minWidth: 180,
      sortable: false,
      renderCell: (params) => (
        <Box sx={{ display: 'flex', gap: 0.5 }}>
          <Tooltip title={t('videoApproval.buttons.preview') || 'Preview'}>
            <IconButton
              size="small"
              onClick={(e) => { e.stopPropagation(); onPreview(params.row.id, params.row.videoTitle); }}
              disabled={previewLoading}
              sx={{ 
                color: '#3B82F6',
                '&:hover': { backgroundColor: 'rgba(59, 130, 246, 0.1)' }
              }}
            >
              {previewLoading ? <CircularProgress size={18} color="inherit" /> : <PlayArrowIcon />}
            </IconButton>
          </Tooltip>
          {canManageVideos && (
            <>
              <Tooltip title={t('videoApproval.buttons.approve')}>
                <IconButton
                  size="small"
                  onClick={(e) => { e.stopPropagation(); onSingleAction(params.row.id, 'approve'); }}
                  sx={{
                    color: '#22C55E',
                    '&:hover': { backgroundColor: 'rgba(34, 197, 94, 0.1)' }
                  }}
                >
                  <CheckIcon />
                </IconButton>
              </Tooltip>
              <Tooltip title={t('videoApproval.buttons.reject')}>
                <IconButton
                  size="small"
                  onClick={(e) => { e.stopPropagation(); onSingleAction(params.row.id, 'reject'); }}
                  sx={{
                    color: '#EF4444',
                    '&:hover': { backgroundColor: 'rgba(239, 68, 68, 0.1)' }
                  }}
                >
                  <CloseIcon />
                </IconButton>
              </Tooltip>
            </>
          )}
        </Box>
      )
    }
  ];

  const customCheckbox = {
    '& .MuiCheckbox-root': {
      color: '#4B5563',
      '&.Mui-checked': { color: '#EE1D52' },
    },
  };

  return (
    <Box sx={{ width: '100%', bgcolor: '#191919', borderRadius: 2, p: 2 }}>
      <Typography sx={{ color: 'white', fontSize: '18px', fontWeight: 600, mb: 2 }}>
        {t('videoApproval.tabs.pending')} <Box component="span" sx={{ color: '#3B82F6', fontWeight: 700 }}>({rowCount})</Box>
      </Typography>
      
      <DataGrid
        rows={rows}
        columns={columns}
        checkboxSelection={canManageVideos}
        disableColumnResize
        paginationMode="server"
        rowCount={rowCount}
        paginationModel={paginationModel}
        onPaginationModelChange={onPaginationModelChange}
        pageSizeOptions={[10, 25, 50]}
        loading={loading}
        onRowSelectionModelChange={onSelectionChange}
        slots={{
          loadingOverlay: DataGridLoadingOverlay,
          noRowsOverlay: DataGridNoRowsOverlay
        }}
        slotProps={{
          loadingOverlay: { message: t('videoApproval.loading') },
          noRowsOverlay: {
            message: t('videoApproval.noPending'),
            subtitle: t('videoApproval.noPendingSubtitle')
          }
        }}
        disableColumnMenu
        rowHeight={72}
        sx={{
          ...customCheckbox,
          border: 'none',
          backgroundColor: 'transparent',
          minHeight: '500px',
          '& .MuiDataGrid-cell': {
            color: 'white',
            borderBottom: '1px solid #3C3C3C',
            padding: { xs: '8px 4px', sm: '12px 8px', md: '16px 8px' },
            display: 'flex',
            alignItems: 'center',
          },
          '& .MuiDataGrid-columnHeader': {
            backgroundColor: '#222222',
            color: '#9CA3AF',
            borderRadius: { xs: '8px', sm: '15px' },
            border: 'none !important',
            '&:hover': {
              backgroundColor: '#333333',
            },
          },
          '& .MuiDataGrid-columnHeaders': {
            backgroundColor: '#222222',
            borderBottom: 'none',
            minHeight: { xs: '44px !important', sm: '48px !important', md: '56px !important' },
            maxHeight: { xs: '44px !important', sm: '48px !important', md: '56px !important' },
            lineHeight: { xs: '44px !important', sm: '48px !important', md: '56px !important' },
            borderRadius: { xs: '20px', sm: '30px', md: '38px' },
            '& .MuiDataGrid-columnHeader': {
              padding: { xs: '0 4px', sm: '0 6px', md: '0 8px' },
              '&:focus': {
                outline: 'none',
              },
            },
          },
          '& .MuiDataGrid-row': {
            borderBottom: '1px solid #3C3C3C',
            '&:hover': {
              backgroundColor: 'rgba(255, 255, 255, 0.03) !important',
            },
          },
          '& .Mui-selected': {
            backgroundColor: 'transparent !important',
            '&:hover': {
              backgroundColor: 'rgba(255, 255, 255, 0.03) !important',
            },
          },
          '& .MuiTablePagination-root': {
            color: '#E5E7EB',
          },
          '& .MuiTablePagination-displayedRows': {
            color: '#E5E7EB',
            fontWeight: 500,
          },
          '& .MuiTablePagination-selectLabel': {
            color: '#E5E7EB',
          },
          '& .MuiDataGrid-selectedRowCount': {
            color: '#3B82F6',
            fontWeight: 600,
          },
          '& .MuiDataGrid-footerContainer': {
            color: '#E5E7EB',
            borderTop: '1px solid #3C3C3C',
          },
        }}
      />
    </Box>
  );
}
