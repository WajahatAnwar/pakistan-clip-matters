import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DeleteOutlineOutlinedIcon from '@mui/icons-material/DeleteOutlineOutlined';
import EditOutlinedIcon from '@mui/icons-material/EditOutlined';
import PlayArrowIcon from '@mui/icons-material/PlayArrow';
import PauseIcon from '@mui/icons-material/Pause';
import VolumeUpIcon from '@mui/icons-material/VolumeUp';
import {
    Alert,
    Box,
    Button,
    CircularProgress,
    Dialog,
    DialogContent,
    DialogTitle,
    IconButton,
    TextField,
    Typography,
    Slider
} from '@mui/material';
import { DataGrid } from '@mui/x-data-grid';
import { useEffect, useState, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import Swal from 'sweetalert2';

export default function VoiceSample() {

    const { t, i18n } = useTranslation();
    const isRtl = (i18n.language || '').startsWith('ur');

    //Variables

    const [loading, setLoading] = useState(true);
    const [voiceSamples, setVoiceSamples] = useState([]);
    const [searchQuery, setSearchQuery] = useState('');
    const [models, setModels] = useState({
        paginationModel: { page: 0, pageSize: 5 },
    });
    const [rowCount, setRowCount] = useState(0);
    const [openModal, setOpenModal] = useState(false);
    const [formData, setFormData] = useState({
        name: '',
        file: null
    });
    const [errors, setErrors] = useState([]);
    const [uploading, setUploading] = useState(false);
    const [playingId, setPlayingId] = useState(null);
    const [currentTime, setCurrentTime] = useState(0);
    const [audioProgress, setAudioProgress] = useState({});
    const audioRefs = useRef({});

    // Filter voice samples based on search query
    const filteredVoiceSamples = voiceSamples.filter(sample => {
        if (!searchQuery.trim()) return true;
        const search = searchQuery.toLowerCase();
        return sample.name && sample.name.toLowerCase().includes(search);
    });

    // Calculate if pagination arrows should be shown
    const pageSize = models.paginationModel?.pageSize ?? 5;
    const totalRows = typeof rowCount === 'number' ? rowCount : filteredVoiceSamples.length;
    const hasMultiplePages = totalRows > pageSize;

    //Functions

    async function handleModelChange(type, model) {
        setModels((prev) => {
            return { ...prev, [type]: model };
        });
        setLoading(true)
    };

    const fetchVoiceSamples = async () => {
        var pagination = models.paginationModel;
        var filter_object = { pagination };
        setLoading(true);
        await fetch(route('admin.voice.sample.get', filter_object), {
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            }
        })
            .then((response) => {
                return response.json()
            })
            .then((response) => {
                if (response.success) {
                    setVoiceSamples(response.data.voiceSamples);
                    setRowCount(response.data.total);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: t('common.error'),
                        text: t('voiceSample.alerts.fetchError'),
                        confirmButtonColor: '#EE1D52',
                        confirmButtonText: t('voiceSample.alerts.close'),
                        backdrop: true,
                        animation: true,
                        color: 'white',
                        background: '#191919',
                    })
                }
            })
            .catch((err) => {
                Swal.fire({
                    icon: 'error',
                    title: t('common.error'),
                    text: t('voiceSample.alerts.fetchError'),
                    confirmButtonColor: '#EE1D52',
                    confirmButtonText: t('voiceSample.alerts.close'),
                    backdrop: true,
                    animation: true,
                    color: 'white',
                    background: '#191919',
                })
                console.error(err);
            }).finally(() => {
                setLoading(false)
            });
    };

    const handleOpenModal = () => {
        setOpenModal(true);
    };

    const handleCloseModal = () => {
        setOpenModal(false);
        setFormData({ name: '', file: null });
        setErrors([]);
    };

    const handleInputChange = (e) => {
        const { name, value } = e.target;
        setErrors(prev => ({
            ...prev,
            [name]: []
        }));
        setFormData(prev => ({
            ...prev,
            [name]: value
        }));
    };

    const handleFileUpload = (e) => {
        const file = e.target.files[0];
        console.log('handleFileUpload called with file:', file);

        setErrors(prev => ({
            ...prev,
            file: []
        }));

        if (file) {
            // Validate file type
            const validTypes = ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp3'];
            const fileExtension = file.name.split('.').pop().toLowerCase();
            const validExtensions = ['mp3', 'wav', 'ogg'];

            console.log('File type:', file.type, 'Extension:', fileExtension);

            if (!validTypes.includes(file.type) && !validExtensions.includes(fileExtension)) {
                setErrors(prev => ({
                    ...prev,
                    file: ['Please upload a valid audio file (MP3, WAV, or OGG).']
                }));
                e.target.value = null;
                return;
            }

            // Validate file size (max 10MB)
            if (file.size > 10 * 1024 * 1024) {
                setErrors(prev => ({
                    ...prev,
                    file: ['File size must not exceed 10MB.']
                }));
                e.target.value = null;
                return;
            }

            // Set the file - let server validate duration with ffprobe
            console.log('Setting file in formData:', file.name, file.size);
            setFormData(prev => {
                const newState = {
                    ...prev,
                    file
                };
                console.log('New formData state:', newState);
                return newState;
            });
        }
    };

    const handleSubmit = async () => {
        // Debug: Log formData state
        console.log('handleSubmit called with formData:', formData);
        console.log('formData.file:', formData.file);
        console.log('formData.name:', formData.name);

        setErrors([]);
        setUploading(true);

        const formDataObj = new FormData();
        if (formData.id) {
            formDataObj.append('id', formData.id);
        }
        formDataObj.append('name', formData.name);
        if (formData.file) {
            formDataObj.append('file', formData.file);
            console.log('File appended to FormData:', formData.file.name, formData.file.size);
        } else {
            console.log('No file in formData.file');
        }

        // Get CSRF token
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        await fetch(route('admin.voice.sample.upload'), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: formDataObj,
        })
            .then((response) => {
                return response.json();
            })
            .then((response) => {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: t('voiceSample.alerts.uploadSuccess'),
                        text: t('voiceSample.alerts.uploadSuccessText'),
                        confirmButtonColor: '#28933F',
                        confirmButtonText: t('voiceSample.alerts.close'),
                        backdrop: true,
                        animation: true,
                        color: 'white',
                        background: '#191919',
                    });
                    handleCloseModal();
                    setLoading(true);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: '🚫 “Hold Up — Something Doesn’t Look Right!”',
                        text: response.message || t('voiceSample.alerts.uploadErrorText'),
                        confirmButtonColor: '#EE1D52',
                        confirmButtonText: t('voiceSample.alerts.close'),
                        backdrop: true,
                        animation: true,
                        color: 'white',
                        background: '#191919',
                    });
                    setErrors(response.errors || []);
                }
            })
            .catch((err) => {
                Swal.fire({
                    icon: 'error',
                    title: '🤯 Well… That Didn’t Work!',
                    text: t('voiceSample.alerts.uploadErrorText'),
                    confirmButtonColor: '#EE1D52',
                    confirmButtonText: t('voiceSample.alerts.close'),
                    backdrop: true,
                    animation: true,
                    color: 'white',
                    background: '#191919',
                });
                console.error(err);
            }).finally(() => {
                setUploading(false);
            })
    };

    const handleEdit = (id) => {
        setFormData({
            id: id,
            name: voiceSamples.find(sample => sample.id === id).name,
            file: null
        })
        handleOpenModal();
    };

    const handleDelete = (id) => {
        Swal.fire({
            icon: 'warning',
            title: t('voiceSample.alerts.deleteConfirmTitle'),
            text: t('voiceSample.alerts.deleteConfirmText'),
            showCancelButton: true,
            confirmButtonColor: '#EE1D52',
            cancelButtonColor: '#6B7280',
            confirmButtonText: t('voiceSample.alerts.deleteConfirmButton'),
            backdrop: true,
            animation: true,
            color: 'white',
            background: '#191919',
        }).then(async (result) => {
            if (result.isConfirmed) {
                await fetch(route('admin.voice.sample.delete', { id: id }), {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                    },
                })
                    .then((response) => {
                        return response.json();
                    })
                    .then((response) => {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Boom 💥 It’s done!',
                                text: t('voiceSample.alerts.deleteSuccessText'),
                                confirmButtonColor: '#28933F',
                                confirmButtonText: t('voiceSample.alerts.close'),
                                backdrop: true,
                                animation: true,
                                color: 'white',
                                background: '#191919',
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: t('voiceSample.alerts.deleteError'),
                                text: response.message || t('voiceSample.alerts.deleteErrorText'),
                                confirmButtonColor: '#EE1D52',
                                confirmButtonText: t('voiceSample.alerts.close'),
                                backdrop: true,
                                animation: true,
                                color: 'white',
                                background: '#191919',
                            });
                        }
                    })
                    .catch((err) => {
                        Swal.fire({
                            icon: 'error',
                            title: '🤯 Well… That Didn’t Work!',
                            text: t('voiceSample.alerts.deleteErrorText'),
                            confirmButtonColor: '#EE1D52',
                            confirmButtonText: t('voiceSample.alerts.close'),
                            backdrop: true,
                            animation: true,
                            color: 'white',
                            background: '#191919',
                        });
                        console.error(err);
                    }).finally(() => {
                        setLoading(true);
                    });
            }
        });
    };

    const handlePlayPause = (id, filePath) => {
        const audio = audioRefs.current[id];

        if (!audio) {
            // Create audio element if it doesn't exist
            const newAudio = new Audio(route('voice.sample.view', { filename: filePath.split('/').pop() }));
            audioRefs.current[id] = newAudio;

            // Add event listeners
            newAudio.addEventListener('timeupdate', () => {
                setAudioProgress(prev => ({
                    ...prev,
                    [id]: (newAudio.currentTime / newAudio.duration) * 100
                }));
                setCurrentTime(newAudio.currentTime);
            });

            newAudio.addEventListener('ended', () => {
                setPlayingId(null);
                setAudioProgress(prev => ({ ...prev, [id]: 0 }));
            });

            newAudio.play();
            setPlayingId(id);
        } else {
            if (playingId === id) {
                // Pause current audio
                audio.pause();
                setPlayingId(null);
            } else {
                // Stop all other audios
                Object.keys(audioRefs.current).forEach(key => {
                    if (audioRefs.current[key] && key !== id.toString()) {
                        audioRefs.current[key].pause();
                        audioRefs.current[key].currentTime = 0;
                    }
                });

                // Play this audio
                audio.play();
                setPlayingId(id);
            }
        }
    };

    const formatTime = (seconds) => {
        if (!seconds || isNaN(seconds)) return '0:00';
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    };



    //Effects

    useEffect(() => {
        if (loading) {
            fetchVoiceSamples();
        }
    }, [loading]);

    // DataGrid Columns

    const columns = [
        {
            field: 'name',
            headerName: t('voiceSample.table.voiceSampleName'),
            flex: 1,
            minWidth: 150,
            renderHeader: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', height: '100%' }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '12px', sm: '14px' }, fontWeight: 500 }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => (
                <Typography sx={{ color: 'white', fontSize: { xs: '12px', sm: '14px' } }}>
                    {params.value}
                </Typography>
            )
        },
        {
            field: 'preview',
            headerName: t('voiceSample.table.preview'),
            flex: 1.5,
            minWidth: 200,
            sortable: false,
            renderHeader: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', height: '100%' }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '12px', sm: '14px' }, fontWeight: 500 }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => {
                const isPlaying = playingId === params.row.id;
                const progress = audioProgress[params.row.id] || 0;
                const audio = audioRefs.current[params.row.id];

                return (
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, width: '100%' }}>
                        <IconButton
                            size="small"
                            onClick={() => handlePlayPause(params.row.id, params.row.file_path)}
                            sx={{
                                color: isPlaying ? '#EE1D52' : '#28933F',
                                backgroundColor: 'rgba(255, 255, 255, 0.1)',
                                '&:hover': {
                                    backgroundColor: 'rgba(255, 255, 255, 0.2)'
                                },
                                padding: { xs: '4px', sm: '8px' }
                            }}
                        >
                            {isPlaying ? <PauseIcon sx={{ fontSize: { xs: '16px', sm: '20px' } }} /> : <PlayArrowIcon sx={{ fontSize: { xs: '16px', sm: '20px' } }} />}
                        </IconButton>

                        <Box sx={{ flex: 1, display: 'flex', alignItems: 'center', gap: { xs: 0.5, sm: 1 } }}>
                            <VolumeUpIcon sx={{ color: '#9CA3AF', fontSize: { xs: '14px', sm: '16px' } }} />
                            <Box sx={{ flex: 1, height: '4px', backgroundColor: '#3C3C3C', borderRadius: '2px', position: 'relative' }}>
                                <Box
                                    sx={{
                                        position: 'absolute',
                                        left: 0,
                                        top: 0,
                                        height: '100%',
                                        width: `${progress}%`,
                                        backgroundColor: isPlaying ? '#EE1D52' : '#28933F',
                                        borderRadius: '2px',
                                        transition: 'width 0.1s linear'
                                    }}
                                />
                            </Box>
                            {isPlaying && audio && (
                                <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '10px', sm: '11px' }, minWidth: { xs: '30px', sm: '35px' } }}>
                                    {formatTime(audio.currentTime)}
                                </Typography>
                            )}
                        </Box>
                    </Box>
                );
            }
        },
        {
            field: 'duration',
            headerName: t('voiceSample.table.duration'),
            flex: 1,
            minWidth: 120,
            renderHeader: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', height: '100%' }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '12px', sm: '14px' }, fontWeight: 500 }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => {
                const totalSeconds = Math.round(params.value);
                const minutes = Math.floor(totalSeconds / 60);
                const seconds = totalSeconds % 60;
                let display = '';
                if (minutes > 0) {
                    display = `${minutes} min${minutes > 1 ? 's' : ''}`;
                    if (seconds > 0) {
                        display += ` ${seconds} second${seconds > 1 ? 's' : ''}`;
                    }
                } else {
                    display = `${seconds} second${seconds !== 1 ? 's' : ''}`;
                }
                return (
                    <Typography sx={{ color: 'white', fontSize: { xs: '11px', sm: '14px' } }}>
                        {display}
                    </Typography>
                );
            }
        },
        {
            field: 'created_at',
            headerName: t('voiceSample.table.dateUploaded'),
            flex: 1,
            minWidth: 140,
            renderHeader: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', height: '100%' }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '12px', sm: '14px' }, fontWeight: 500 }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => {
                let display = '';
                if (params.value) {
                    const date = new Date(params.value);
                    display = date.toLocaleString('en-US', {
                        year: 'numeric',
                        month: 'short',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit',
                        hour12: true
                    });
                }
                return (
                    <Typography sx={{ color: 'white', fontSize: { xs: '11px', sm: '14px' } }}>
                        {display}
                    </Typography>
                );
            }
        },
        {
            field: 'actions',
            headerName: t('voiceSample.table.action'),
            flex: 0.8,
            minWidth: 100,
            renderHeader: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', height: '100%' }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: { xs: '12px', sm: '14px' }, fontWeight: 500 }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => (
                <Box sx={{ display: 'flex', gap: { xs: 0.5, sm: 1 } }}>
                    <IconButton
                        onClick={() => handleEdit(params.row.id)}
                        size="small"
                        sx={{
                            color: '#28933F',
                            '&:hover': {
                                opacity: 0.8
                            }
                        }}
                    >
                        <EditOutlinedIcon sx={{ fontSize: { xs: '18px', sm: '24px' } }} />
                    </IconButton>
                    <IconButton
                        onClick={() => handleDelete(params.row.id)}
                        size="small"
                        sx={{
                            color: '#EE1D52',
                            '&:hover': {
                                opacity: 0.8
                            }
                        }}
                    >
                        <DeleteOutlineOutlinedIcon sx={{ fontSize: { xs: '18px', sm: '24px' } }} />
                    </IconButton>
                </Box>
            )
        }
    ]


    return (
        <AuthenticatedLayout
            header={<h2 className="font-semibold text-2xl text-white leading-tight">{t('voiceSample.title')}</h2>}
        >
            <Box sx={{ display: 'flex', flexDirection: { xs: 'column', sm: 'row' }, justifyContent: 'space-between', alignItems: { xs: 'flex-start', sm: 'center' }, gap: 2, mb: 3 }}>
                <input
                    type="text"
                    placeholder={t('voiceSample.form.searchPlaceholder')}
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    style={{
                        padding: '8px 12px',
                        borderRadius: '8px',
                        border: '1px solid #3C3C3C',
                        backgroundColor: '#222222',
                        color: 'white',
                        fontSize: '14px',
                        width: '100%',
                        maxWidth: '300px',
                        outline: 'none'
                    }}
                />
                <Button
                    variant="contained"
                    onClick={handleOpenModal}
                    sx={{
                        bgcolor: '#28933F',
                        color: 'white',
                        textTransform: 'none',
                        fontSize: { xs: '13px', sm: '14px' },
                        width: { xs: '100%', sm: 'auto' },
                        padding: { xs: '10px 16px', sm: '6px 16px' },
                        '&:hover': {
                            bgcolor: '#217a34'
                        }
                    }}
                >
                    {t('voiceSample.createNew')}
                </Button>
            </Box>

            <Box sx={{
                bgcolor: '#222222',
                borderRadius: 2,
                p: { xs: 1.5, sm: 2 }
            }}>
                {/* <Typography sx={{
                    color: 'white',
                    fontSize: '18px',
                    fontWeight: 600,
                    mb: 2
                }}>
                    {t('voiceSample.voiceSamples')}
                </Typography> */}

                <Box sx={{ 
                    width: '100%', 
                    overflowX: 'auto',
                    '&::-webkit-scrollbar': {
                        height: '8px'
                    },
                    '&::-webkit-scrollbar-track': {
                        backgroundColor: '#191919',
                        borderRadius: '4px'
                    },
                    '&::-webkit-scrollbar-thumb': {
                        backgroundColor: '#3C3C3C',
                        borderRadius: '4px',
                        '&:hover': {
                            backgroundColor: '#4C4C4C'
                        }
                    }
                }}>
                    <DataGrid
                        rows={filteredVoiceSamples}
                    columns={columns}
                    columnBufferPx={5}
                    disableColumnMenu
                    disableColumnSelector
                    disableRowSelectionOnClick
                    localeText={{
                        noRowsLabel: t('videoManagement.table.noRows'),
                        paginationRowsPerPage: t('common.rowsPerPage'),
                        paginationDisplayedRows: ({ from, to, count }) =>
                            `${from}-${to} ${t('common.of')} ${count !== -1 ? count : `${t('common.moreThan')} ${to}`}`
                    }}
                    initialState={{
                        pagination: {
                            paginationModel: models.paginationModel,
                        },
                    }}
                    pagination={models.paginationModel}
                    onPaginationModelChange={(model) => handleModelChange('paginationModel', model)}
                    paginationMode='server'
                    rowCount={rowCount}
                    loading={loading}
                    pageSizeOptions={[5, 10, 15, 20]}
                    sx={{
                        border: 'none',
                        backgroundColor: 'transparent',
                        height: 'auto',
                        minWidth: { xs: '800px', sm: '100%' },
                        minHeight: { xs: 400, sm: 500, md: 600 },
                        overflowY: 'auto',
                        '& .MuiDataGrid-root': {
                            borderColor: '#2A303C',
                        },
                        '& .MuiDataGrid-virtualScroller': {
                            overflowY: 'auto',
                            maxHeight: { xs: 400, sm: 500, md: 600 },
                        },
                        '& .MuiDataGrid-cell': {
                            padding: { xs: '8px 4px', sm: '12px 8px', md: '16px 8px' },
                            color: 'white',
                            display: 'flex',
                            alignItems: 'center',
                            minHeight: { xs: '70px !important', sm: '85px !important', md: '100px !important' },
                            border: 'none'
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
                            minHeight: { xs: '44px !important', sm: '50px !important', md: '56px !important' },
                            maxHeight: { xs: '44px !important', sm: '50px !important', md: '56px !important' },
                            lineHeight: { xs: '44px !important', sm: '50px !important', md: '56px !important' },
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
                        "& .MuiDataGrid-filler": {
                            "--rowBorderColor": 'transparent !important'
                        },
                        '& .MuiDataGrid-row': {
                            minHeight: { xs: '70px !important', sm: '85px !important', md: '100px !important' },
                            borderBottom: '1px solid #3C3C3C',
                            '&:hover': {
                                backgroundColor: 'rgba(255, 255, 255, 0.03) !important'
                            }
                        },
                        '& .MuiDataGrid-footerContainer': {
                            borderTop: '1px solid #3C3C3C'
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
                        },
                        '& .MuiDataGrid-selectedRowCount': {
                            color: '#9CA3AF'
                        }
                    }}
                />
                </Box>
            </Box>

            <Dialog
                open={openModal}
                onClose={handleCloseModal}
                maxWidth="sm"
                fullWidth
                PaperProps={{
                    sx: {
                        backgroundColor: '#191919',
                        borderRadius: '8px',
                    }
                }}
            >
                <DialogTitle sx={{
                    color: 'white',
                    fontSize: '18px',
                    fontWeight: 500,
                    p: 3,
                    pb: 2
                }}>
                    {t('voiceSample.uploadTitle')}
                </DialogTitle>
                <DialogContent sx={{ p: 3, pt: 2 }}>
                    <Box sx={{ mb: 3 }}>
                        <Typography sx={{
                            color: '#9CA3AF',
                            fontSize: '14px',
                            mb: 1
                        }}>
                            {t('voiceSample.form.voiceSampleName')}
                        </Typography>
                        <TextField
                            fullWidth
                            name="name"
                            value={formData.name}
                            onChange={handleInputChange}
                            variant="outlined"
                            error={errors.name ? true : false}
                            helperText={errors.name ? errors.name[0] : ''}
                            sx={{
                                '& .MuiOutlinedInput-root': {
                                    backgroundColor: '#222222',
                                    borderRadius: '8px',
                                    '& fieldset': { border: 'none' },
                                    '&:hover fieldset': { border: 'none' },
                                    '&.Mui-focused fieldset': { border: 'none' },
                                },
                                '& .MuiOutlinedInput-input': {
                                    color: 'white',
                                    fontSize: '14px',
                                }
                            }}
                        />
                    </Box>

                    {/* Current Audio Preview (only show when editing) */}
                    {formData.id && (
                        <Box sx={{ mb: 3 }}>
                            <Typography sx={{
                                color: '#9CA3AF',
                                fontSize: '14px',
                                mb: 1
                            }}>
                                {t('voiceSample.form.currentAudioPreview')}
                            </Typography>
                            <Box sx={{
                                backgroundColor: '#222222',
                                borderRadius: '8px',
                                p: 2,
                                display: 'flex',
                                alignItems: 'center',
                                gap: 2
                            }}>
                                <IconButton
                                    size="small"
                                    onClick={() => {
                                        const sample = voiceSamples.find(s => s.id === formData.id);
                                        if (sample) {
                                            handlePlayPause(formData.id, sample.file_path);
                                        }
                                    }}
                                    sx={{
                                        color: playingId === formData.id ? '#EE1D52' : '#28933F',
                                        backgroundColor: 'rgba(255, 255, 255, 0.1)',
                                        '&:hover': {
                                            backgroundColor: 'rgba(255, 255, 255, 0.2)'
                                        }
                                    }}
                                >
                                    {playingId === formData.id ? <PauseIcon /> : <PlayArrowIcon />}
                                </IconButton>

                                <Box sx={{ flex: 1, display: 'flex', alignItems: 'center', gap: 1 }}>
                                    <VolumeUpIcon sx={{ color: '#9CA3AF', fontSize: '18px' }} />
                                    <Box sx={{ flex: 1, height: '6px', backgroundColor: '#3C3C3C', borderRadius: '3px', position: 'relative' }}>
                                        <Box
                                            sx={{
                                                position: 'absolute',
                                                left: 0,
                                                top: 0,
                                                height: '100%',
                                                width: `${audioProgress[formData.id] || 0}%`,
                                                backgroundColor: playingId === formData.id ? '#EE1D52' : '#28933F',
                                                borderRadius: '3px',
                                                transition: 'width 0.1s linear'
                                            }}
                                        />
                                    </Box>
                                    {playingId === formData.id && audioRefs.current[formData.id] && (
                                        <Typography sx={{ color: '#9CA3AF', fontSize: '12px', minWidth: '45px' }}>
                                            {formatTime(audioRefs.current[formData.id].currentTime)}
                                        </Typography>
                                    )}
                                </Box>

                                <Typography sx={{ color: '#9CA3AF', fontSize: '12px' }}>
                                    {(() => {
                                        const sample = voiceSamples.find(s => s.id === formData.id);
                                        if (sample && sample.duration) {
                                            return formatTime(sample.duration);
                                        }
                                        return '0:00';
                                    })()}
                                </Typography>
                            </Box>
                        </Box>
                    )}

                    <Box>
                        <Typography sx={{
                            color: '#9CA3AF',
                            fontSize: '14px',
                            mb: 1
                        }}>
                            {formData.id ? t('voiceSample.form.replaceVoiceSample') : t('voiceSample.form.uploadVoiceSample')}
                        </Typography>
                        <Box
                            sx={{
                                border: '2px dashed #2A303C',
                                borderRadius: '8px',
                                backgroundColor: '#222222',
                                p: 3,
                                textAlign: 'center',
                                cursor: 'pointer'
                            }}
                            onClick={() => document.getElementById('voice-file').click()}
                        >
                            <Typography sx={{
                                color: '#9CA3AF',
                                fontSize: '14px',
                                mb: 1
                            }}>
                                {t('voiceSample.form.chooseFile')}
                            </Typography>
                            <Typography sx={{
                                color: '#28933F',
                                fontSize: '12px'
                            }}>
                                {t('voiceSample.form.acceptedFormats')}
                            </Typography>
                            <Typography sx={{
                                color: '#6B7280',
                                fontSize: '11px',
                                mt: 1
                            }}>
                                {t('voiceSample.form.durationLimits')}
                            </Typography>
                            <input
                                id="voice-file"
                                type="file"
                                accept=".mp3,.wav,.ogg"
                                hidden
                                onChange={handleFileUpload}
                            />

                        </Box>
                        {formData.file && (
                            <Typography sx={{
                                color: 'white',
                                fontSize: '14px',
                                mt: 1
                            }}>
                                {t('voiceSample.form.selectedFile')} {formData.file.name}
                            </Typography>
                        )}
                        {errors.file?.length > 0 && (
                            <Alert severity="error" sx={{ mt: 2, bgcolor: 'transparent', color: '#FF6B6B' }}>
                                {errors.file[0]}
                            </Alert>
                        )}
                    </Box>

                    <Box sx={{
                        display: 'flex',
                        justifyContent: 'flex-end',
                        gap: 2,
                        mt: 4
                    }}>
                        <Button
                            onClick={handleCloseModal}
                            disabled={uploading}
                            sx={{
                                color: 'white',
                                backgroundColor: 'transparent',
                                textTransform: 'none',
                                '&:hover': {
                                    backgroundColor: 'rgba(255, 255, 255, 0.1)'
                                },
                                '&:disabled': {
                                    color: '#6B7280'
                                }
                            }}
                        >
                            {t('voiceSample.form.cancel')}
                        </Button>
                        <Button
                            disabled={uploading}
                            variant="contained"
                            onClick={handleSubmit}
                            startIcon={uploading ? <CircularProgress size={20} sx={{ color: 'white' }} /> : null}
                            sx={{
                                bgcolor: '#28933F',
                                color: 'white',
                                textTransform: 'none',
                                minWidth: '100px',
                                '&:hover': {
                                    bgcolor: '#217a34'
                                },
                                '&:disabled': {
                                    bgcolor: '#217a34',
                                    color: 'white',
                                    opacity: 0.7
                                }
                            }}
                        >
                            {uploading ? t('voiceSample.form.saving') || 'Saving...' : t('voiceSample.form.save')}
                        </Button>
                    </Box>
                </DialogContent>
            </Dialog>

        </AuthenticatedLayout>
    );
}
