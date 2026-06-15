/**
 * Video Management Component
 * 
 * Features:
 * - Displays all videos with real-time status tracking
 * - Automatically extracts YouTube thumbnails from video URLs
 * - Shows 4-step processing pipeline: Dropbox → YouTube → Transcript → Speaker Tagging
 * - Supports individual and bulk video tracking
 * - Visual indicators for processing status (processing, completed, failed)
 * 
 * Video Data Structure:
 * - id: Unique video identifier
 * - title: Video title
 * - filename: Original file name
 * - youtube_url: YouTube video URL (used to extract thumbnail)
 * - youtube_video_id: YouTube video ID
 * - onDropbox: Boolean - uploaded to Dropbox
 * - onYoutube: Boolean - uploaded to YouTube
 * - isTranscript: Boolean - transcription completed
 * - isTagged: Boolean - speaker tagging completed
 * - processing_status: 'pending'|'processing'|'completed'|'failed'
 * - language_detected: Detected language code
 * - transcript_id: AssemblyAI transcript ID
 * - pyannote_job_id: Pyannote speaker identification job ID
 */

import React, { useEffect, useMemo } from 'react';
import { Box, Checkbox, Typography, Button, Step, StepLabel, Stepper, Tooltip } from '@mui/material';
import { DataGrid } from '@mui/x-data-grid';
import CircleIcon from '@mui/icons-material/Circle';
import { useTranslation } from 'react-i18next';
import Swal from 'sweetalert2';
import { DataGridLoadingOverlay, DataGridNoRowsOverlay } from '@/Components/LoadingOverlay';

import RandomImage from '@/Images/random-data-grid.svg';
import Transcript from '@/Images/Transcript.svg';
import ActiveTranscript from '@/Images/ActiveTranscript.svg';
import ActiveSpeaker from '@/Images/ActiveSpeaker.svg';
import DropBox from '@/Images/DropBox.svg';
import Speaker from '@/Images/Speaker.svg';
import Youtube from '@/Images/Youtube.svg';
import TrackedInprocess from '@/Images/TrackInprocess.svg'
import TrackedIcon from '@/Images/TrackedIcon.svg'
import SearchReady from '@/Images/SearchReady.svg'
import ActiveSearchReady from '@/Images/ActiveSearchReady.svg'

// Import video helper utilities
import {
    getYoutubeThumbnail,
    getProcessingStatus,
    formatTimestamp
} from '@/Utils/videoHelpers';

export default function All({ videos, loading, rowCount, paginationModel, onPaginationModelChange, canManageVideos }) {
    const { t, i18n } = useTranslation();
    const isRtl = i18n.language === 'ur';
    const hasMultiplePages = rowCount > (paginationModel?.pageSize ?? 5);

    // Transform videos data for DataGrid
    const rows = useMemo(() => {
        if (!videos || !Array.isArray(videos)) return [];

        const transformedVideos = videos.map(video => {
            const processingStatus = getProcessingStatus(video);
            const thumbnail = RandomImage;

            return {
                id: video.id,
                videoTitle: video.title || video.filename || 'Untitled Video',
                thumbnail: thumbnail,
                status: video.processing_status || 'pending',
                completedSteps: processingStatus.completedSteps,
                totalSteps: processingStatus.totalSteps,
                hasYoutube: processingStatus.hasYoutube,
                failedStep: processingStatus.failedStep,
                isTracked: video.isTracked,
                onDropbox: video.onDropbox,
                onYoutube: video.onYoutube,
                isTranscript: video.isTranscript,
                isTagged: video.isTagged,
                hasEmbedding: video.hasEmbedding,
                notes: video.notes,
                youtube_url: video.youtube_url,
                youtube_video_id: video.youtube_video_id,
                language_detected: video.language_detected,
                transcript_id: video.transcript_id,
                pyannote_job_id: video.pyannote_job_id,
                created_at: video.created_at,
                video_created_at: video.video_created_at,
                description: video.description,
                processing_error: video.processing_error,
                processingPercentage: processingStatus.percentage,
                currentProcessingStep: processingStatus.currentStep
                
            };
        });

        return transformedVideos;
    }, [videos]);

    // Log videos data for debugging
    useEffect(() => {
        console.log('Videos received:', videos);
        console.log('Transformed rows:', rows);
    }, [videos, rows]);

    const columns = [

        {
            field: 'videoTitle',
            headerName: t('videoManagement.table.videoTitle'),
            flex: 1,
            minWidth: 200,
            renderHeader: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', height: '100%' }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '12px', sm: '14px' }, fontWeight: 500 }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', gap: { xs: 0.75, sm: 1 }, width: '100%' }}>
                    <img 
                        src={params.row.thumbnail} 
                        alt={params.value} 
                        style={{ 
                            width: window.innerWidth < 600 ? 32 : 40, 
                            height: window.innerWidth < 600 ? 32 : 40, 
                            borderRadius: 4, 
                            flexShrink: 0 
                        }} 
                    />
                    <Tooltip title={params.value} arrow placement="top">
                        <Typography
                            sx={{
                                color: 'white',
                                fontSize: { xs: '12px', sm: '14px' },
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                whiteSpace: 'nowrap',
                                maxWidth: 'calc(100% - 50px)'
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
            field: 'status',
            headerName: t('videoManagement.table.status'),
            flex: 1.5,
            minWidth: 280,
            headerAlign: 'center',
            sortable: false,
            renderHeader: (params) => (
                <Box sx={{ pl: { xs: 1, sm: 2 }, display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%' }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '12px', sm: '14px' }, fontWeight: 500, textAlign: 'center' }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => {
                const pipelineSteps = params.row.hasYoutube
                    ? [
                        { icon: <img src={DropBox} alt="" className='w-14' />, activeIcon: null },
                        { icon: <img src={Youtube} alt="" className='w-14' />, activeIcon: null },
                        { icon: <img src={Transcript} alt="" className='w-14' />, activeIcon: <img src={ActiveTranscript} alt="" className='w-14' /> },
                        { icon: <img src={Speaker} alt="" className='w-14' />, activeIcon: <img src={ActiveSpeaker} alt="" className='w-14' /> },
                        { icon: <img src={SearchReady} alt="" className='w-14' />, activeIcon: <img src={ActiveSearchReady} alt="" className='w-14' /> }
                    ]
                    : [
                        { icon: <img src={DropBox} alt="" className='w-14' />, activeIcon: null },
                        { icon: <img src={Transcript} alt="" className='w-14' />, activeIcon: <img src={ActiveTranscript} alt="" className='w-14' /> },
                        { icon: <img src={Speaker} alt="" className='w-14' />, activeIcon: <img src={ActiveSpeaker} alt="" className='w-14' /> },
                        { icon: <img src={SearchReady} alt="" className='w-14' />, activeIcon: <img src={ActiveSearchReady} alt="" className='w-14' /> }
                    ];
                const pipelineLabels = params.row.hasYoutube
                    ? [t('videoManagement.status.dropbox'), t('videoManagement.status.youtube'), t('videoManagement.status.transcript'), t('videoManagement.status.speakerTagging'), t('videoManagement.status.searchReady')]
                    : [t('videoManagement.status.dropbox'), t('videoManagement.status.transcript'), t('videoManagement.status.speakerTagging'), t('videoManagement.status.searchReady')];
                const totalSteps = pipelineSteps.length;
                const isAllComplete = params.row.completedSteps === totalSteps;

                return (
                    <Box sx={{ display: 'flex', alignItems: 'center', width: '100%', px: { xs: 1, sm: 2 }, position: 'relative' }}>
                    <Box sx={{
                        display: 'flex',
                        alignItems: 'center',
                        width: '100%'
                    }}>
                            {pipelineSteps.map((step, index) => {
                            const isCompleted = params.row.completedSteps > index;
                            const isFailed = params.row.failedStep === index;
                            const stepColor = isFailed ? '#EF4444' : (isCompleted ? '#22C55E' : '#484848');
                            const stepBgColor = isFailed ? '#FEE2E2' : (isCompleted ? '#fff' : '#484848');
                            
                            return (
                            <React.Fragment key={index}>
                                <Box>
                                    <Box sx={{
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        color: stepColor,
                                        bgcolor: stepBgColor,
                                        position: 'relative',
                                        width: { xs: 24, sm: 30 },
                                        height: { xs: 24, sm: 30 },
                                        borderRadius: "9999px",
                                        padding: { xs: '5px', sm: '7px' },
                                        border: isFailed ? '2px solid #EF4444' : 'none',
                                    }}>
                                        {isFailed ? (
                                            <Typography sx={{ color: '#EF4444', fontSize: { xs: '14px', sm: '18px' }, fontWeight: 'bold' }}>✕</Typography>
                                        ) : (
                                                    isAllComplete && step.activeIcon ? step.activeIcon : step.icon
                                        )}
                                        </Box>
                                </Box>
                                    {index < totalSteps - 1 && (
                                    <Box sx={{
                                        flex: 1,
                                        height: { xs: '6px', sm: '10px' },
                                        bgcolor: "#fff",
                                        borderRadius: "99999px",
                                        borderTopLeftRadius: "0px",
                                        borderBottomLeftRadius: "0px",
                                        borderBottomRightRadius: "0px",
                                        borderTopRightRadius: "0px",
                                        mt: 3,
                                        backgroundColor: stepColor,
                                        margin: '-3px',
                                    }} />
                                    )}
                            </React.Fragment>
                        );
                            })}
                    </Box>
                    <Box sx={{
                        position: 'absolute',
                        top: { xs: '35px', sm: '39px' },
                        left: 0,
                        right: 0,
                        display: { xs: 'none', sm: 'flex' },
                        justifyContent: 'space-around',
                        px: 0
                    }}>
                            {pipelineLabels.map((label, index) => {
                            const isFailed = params.row.failedStep === index;
                            return (
                            <Typography
                                key={label}
                                sx={{
                                    color: isFailed ? '#EF4444' : '#6B7280',
                                    fontSize: { xs: '10px', sm: '12px' },
                                    fontWeight: isFailed ? 600 : 400,
                                    flex: index === 0 ? '0' : '1 1 0',
                                    textAlign: index === 0 ? 'left' : index === totalSteps - 1 ? 'right' : 'right'
                                }}
                            >
                                {label}{isFailed ? ' ✕' : ''}
                            </Typography>
                        )})}
                    </Box>
                </Box>
                );
            }
        },

        {
            field: 'video_created_at',
            headerName: t('videoManagement.table.eventDate'),
            flex: 0.6,
            minWidth: 120,
            renderHeader: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', height: '100%' }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '12px', sm: '14px' }, fontWeight: 500 }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => (
                <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '12px', sm: '14px' } }}>
                    {params.value ? new Date(params.value).toLocaleDateString() : '-'}
                </Typography>
            )
        },

        {
            field: 'created_at',
            headerName: t('videoManagement.table.uploadDate'),
            flex: 0.6,
            minWidth: 120,
            renderHeader: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', height: '100%' }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '12px', sm: '14px' }, fontWeight: 500 }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => (
                <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '12px', sm: '14px' } }}>
                    {params.value ? new Date(params.value).toLocaleDateString() : '-'}
                </Typography>
            )
        },

        {
            field: 'action',
            headerName: t('videoManagement.table.action'),
            headerAlign: 'center',
            flex: 0.8,
            minWidth: 180,
            sortable: false,
            renderHeader: (params) => (
                <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '12px', sm: '14px' }, fontWeight: 500 }}>
                    {params.colDef.headerName}
                </Typography>
            ),
            renderCell: (params) => {
                const steps = params.row.completedSteps;
                const percentage = (steps / 4) * 100;
                const isProcessing = params.row.status === 'processing';
                const isCompleted = params.row.status === 'completed';
                const isFailed = params.row.status === 'failed';
                const isPending = params.row.status === 'pending';

                // Managers cannot interact - show status only
                if (!canManageVideos) {
                    // Show processing status with percentage
                    if (isProcessing || (steps > 0 && steps < 4 && !isCompleted)) {
                        return (
                            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, justifyContent: 'left', width: '100%', p: 2 }}>
                                <img src={TrackedInprocess} alt="" className='animate-spin duration-[5000ms]' />
                                <Typography sx={{ color: '#fff', fontSize: '14px' }}>
                                    {Math.round(percentage)}%<br />
                                    {t('videoManagement.status.tracking')}
                                </Typography>
                            </Box>
                        );
                    }

                    // Show completed/tracked status
                    if (isCompleted || (steps === 4)) {
                        return (
                            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, justifyContent: 'left', width: '100%', p: 2 }}>
                                <img src={TrackedIcon} alt="" />
                                <Typography sx={{ color: '#22C55E', fontSize: '14px' }}>
                                    {t('videoManagement.status.tracked')}
                                </Typography>
                            </Box>
                        );
                    }

                    // Show status text for other states
                    return (
                        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'center', width: '100%', p: 2 }}>
                            <Typography sx={{ color: '#9CA3AF', fontSize: '14px' }}>
                                {isFailed ? t('videoManagement.status.failed') :
                                    isPending ? t('videoManagement.status.pending') : '-'}
                            </Typography>
                        </Box>
                    );
                }

                // Show Retry button ONLY for failed videos
                if (isFailed) {
                    return (
                        <Box sx={{ 
                            display: 'flex', 
                            justifyContent: 'center', 
                            width: '100%', 
                            p: { xs: 1, sm: 2 } 
                        }}>
                            <Button
                                variant="contained"
                                size="small"
                                onClick={() => handleRetryProcessing(params.row.id)}
                                sx={{
                                    backgroundColor: '#EE1D52',
                                    fontSize: { xs: '11px', sm: '12px' },
                                    textTransform: 'none',
                                    minWidth: { xs: '70px', sm: '100px' },
                                    padding: { xs: '4px 8px', sm: '6px 16px' },
                                    '&:hover': {
                                        backgroundColor: '#dc1847'
                                    }
                                }}
                            >
                                {t('videoManagement.buttons.retry') || 'Retry'}
                            </Button>
                        </Box>
                    );
                }

                // Show Track button ONLY for pending (unstarted) videos
                if (isPending && steps === 0) {
                    return (
                        <Box sx={{
                            display: 'flex',
                            justifyContent: 'center',
                            width: '100%',
                            p: { xs: 1, sm: 2 }
                        }}>
                            <Button
                                variant="contained"
                                size="small"
                                onClick={() => handleTrackVideo(params.row.id)}
                                sx={{
                                    backgroundColor: '#EE1D52',
                                    fontSize: { xs: '11px', sm: '12px' },
                                    textTransform: 'none',
                                    minWidth: { xs: '70px', sm: '100px' },
                                    padding: { xs: '4px 8px', sm: '6px 16px' },
                                    '&:hover': {
                                        backgroundColor: '#dc1847'
                                    }
                                }}
                            >
                                {t('videoManagement.buttons.track')}
                            </Button>
                        </Box>
                    );
                }

                // Show processing status with percentage
                if (isProcessing || (steps > 0 && steps < 4 && !isCompleted)) {
                    return (
                        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, justifyContent: 'left', width: '100%', p: 2 }}>
                            <img src={TrackedInprocess} alt="" className='animate-spin duration-[5000ms]' />
                            <Typography sx={{ color: '#fff', fontSize: '14px' }}>
                                {Math.round(percentage)}%<br />
                                {t('videoManagement.status.tracking')}
                            </Typography>
                        </Box>
                    );
                }

                // Show completed/tracked status
                return (
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, justifyContent: 'left', width: '100%', p: 2 }}>
                        <img src={TrackedIcon} alt="" />
                        <Typography sx={{ color: '#22C55E', fontSize: '14px' }}>
                            {t('videoManagement.status.tracked')}
                        </Typography>
                    </Box>
                );
            }
        }
    ];

    // Handle track video action
    // Handle retry processing for failed or stuck videos
    const handleRetryProcessing = async (videoId) => {
        console.log('Retrying video processing:', videoId);

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        try {
            // Show confirmation dialog
            const result = await Swal.fire({
                title: 'Retry Processing?',
                text: 'This will restart the video processing from where it stopped.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#EE1D52',
                cancelButtonColor: '#6B7280',
                confirmButtonText: 'Yes, retry',
                cancelButtonText: 'Cancel'
            });

            if (!result.isConfirmed) return;

            const response = await fetch(`/user/videos/${videoId}/retry-processing`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ force_restart: false })
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to retry processing');
            }

            console.log('Retry processing started:', data);

            Swal.fire({
                icon: 'success',
                title: 'Processing Restarted!',
                text: data.message,
                timer: 2000,
                showConfirmButton: false
            });

            setTimeout(() => window.location.reload(), 2000);

        } catch (error) {
            console.error('Error retrying processing:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: error.message || 'Failed to retry processing'
            });
        }
    };

    return (
        <Box sx={{ width: '100%', height: '100%', bgcolor: '#191919', borderRadius: 2, p: { xs: 1.5, sm: 2 } }}>
            <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1, flex: 1, mb: 2 }}>
                <Typography sx={{
                    color: 'white',
                    fontSize: { xs: '16px', sm: '18px' },
                    fontWeight: 600
                }}>
                    {t('videoManagement.title')} ({rows.length} {t('videoManagement.videosCount')})
                </Typography>
            </Box>
            <DataGrid
                rows={rows}
                columns={columns}
                disableColumnResize
                paginationMode="server"
                rowCount={rowCount}
            paginationModel={paginationModel}
                onPaginationModelChange={onPaginationModelChange}
                pageSizeOptions={[5, 10, 20]}
                loading={loading}
                slots={{
                    loadingOverlay: DataGridLoadingOverlay,
                    noRowsOverlay: DataGridNoRowsOverlay
                }}
                slotProps={{
                    loadingOverlay: { message: t('videoManagement.loading') },
                    noRowsOverlay: {
                        message: t('videoManagement.noRowsTitle'),
                        subtitle: t('videoManagement.noRowsSubtitle')
                    }
                }}
                disableColumnMenu
                disableColumnSelector
                localeText={{
                    noRowsLabel: t('videoManagement.table.noRows'),
                    paginationRowsPerPage: t('common.rowsPerPage'),
                    paginationDisplayedRows: ({ from, to, count }) =>
                        `${from}-${to} ${t('common.of')} ${count !== -1 ? count : `${t('common.moreThan')} ${to}`}`
                }}
                sx={{
                    border: 'none',
                    backgroundColor: 'transparent',
                    minHeight: { xs: '400px', sm: '500px', md: '650px' },
                    '& .MuiDataGrid-root': {
                        borderColor: '#2A303C'
                    },
                    '& .MuiDataGrid-cell': {
                        padding: { xs: '8px 4px', sm: '12px 8px', md: '16px 8px' },
                        color: 'white',
                        display: 'flex',
                        alignItems: 'center',
                        minHeight: { xs: '80px !important', sm: '90px !important', md: '100px !important' },
                        border: 'none'
                    },
                    '& .MuiDataGrid-cell:focus, & .MuiDataGrid-cell:focus-within': {
                        outline: 'none'
                    },
                    '& .MuiDataGrid-columnHeader': {
                        backgroundColor: '#222222',
                        color: '#9CA3AF',
                        borderRadius: { xs: '8px', sm: '15px' },
                        border: "none !important",
                        '&:hover': {
                            backgroundColor: '#333333'
                        }
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
                                outline: 'none'
                            }
                        }
                    },
                    '& .MuiButtonBase-root.Mui-disabled': {
                        color: '#9CA3AF'
                    },
                    '& .Mui-selected': {
                        backgroundColor: 'transparent !important',
                        '&:hover': {
                            backgroundColor: 'rgba(255, 255, 255, 0.2) !important'
                        }
                    },
                    '& .MuiDataGrid-row': {
                        minHeight: { xs: '80px !important', sm: '90px !important', md: '100px !important' },
                        borderBottom: '1px solid #3C3C3C',
                        '&:hover': {
                            backgroundColor: 'rgba(255, 255, 255, 0.03) !important'
                        }
                    },
                    '& .MuiDataGrid-row:focus, & .MuiDataGrid-row:focus-within': {
                        outline: 'none'
                    },
                    "& .MuiDataGrid-filler": {
                        " --rowBorderColor": 'transparent !important '
                    },
                    '& .MuiDataGrid-footerContainer': {
                        borderTop: '1px solid '
                    },
                    '& .MuiTablePagination-root': {
                        color: '#9CA3AF',
                        direction: isRtl ? 'rtl' : 'ltr'
                    },
                    '& .MuiTablePagination-toolbar': {
                        flexDirection: isRtl ? 'row-reverse' : 'row'
                    },

                    '& .MuiTablePagination-actions': {
                        color: '#9CA3AF',
                        direction: isRtl ? 'rtl' : 'ltr',
                        flexDirection: isRtl ? 'row-reverse' : 'row',
                        visibility: hasMultiplePages ? 'visible' : 'hidden'
                    },
                    '& .MuiTablePagination-actions button': {
                        transform: isRtl ? 'scaleX(-1)' : 'none'
                    },
                    '& .MuiTablePagination-select': {
                        visibility: hasMultiplePages ? 'visible' : 'hidden'
                    },
                    '& .MuiTablePagination-selectLabel': {
                        visibility: hasMultiplePages ? 'visible' : 'hidden'
                    }
                }}
            />
        </Box>
    );
}
