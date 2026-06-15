import React, { useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Grid, Card, Typography, Box, Tabs, Tab, Dialog, DialogTitle, DialogContent, DialogActions, Button, Alert, CircularProgress, IconButton } from '@mui/material';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import Highcharts from 'highcharts';
import { usePage } from '@inertiajs/react';
import HighchartsReact from 'highcharts-react-official';
import PeopleOutlinedIcon from '@mui/icons-material/PeopleOutlined';
import StorageOutlinedIcon from '@mui/icons-material/StorageOutlined';
import DescriptionOutlinedIcon from '@mui/icons-material/DescriptionOutlined';
import TimerOutlinedIcon from '@mui/icons-material/TimerOutlined';
import TrackChangesOutlinedIcon from '@mui/icons-material/TrackChangesOutlined';
import SearchOutlinedIcon from '@mui/icons-material/SearchOutlined';
import GoogleIcon from '@mui/icons-material/Google';
import CloudIcon from '@mui/icons-material/Cloud';
import { useTranslation } from 'react-i18next';

export default function Dashboard({ connectionStatus }) {
    const { t, i18n } = useTranslation();
    const user = usePage().props.auth?.user;
    console.log('User:', user);
    const [trackingTab, setTrackingTab] = useState(0);
    const [chartPeriod, setChartPeriod] = useState(0);
    const [dashboardStats, setDashboardStats] = useState(null);
    const [loading, setLoading] = useState(true);
    
    // Keywords pagination state
    const [keywordsPage, setKeywordsPage] = useState(1);
    const [keywordsPerPage] = useState(10);
    const [keywordsTotal, setKeywordsTotal] = useState(0);

    // Connection modal states
    const [showConnectionModal, setShowConnectionModal] = useState(false);
    const [connectionStep, setConnectionStep] = useState('dropbox'); // Only 'dropbox' now (Google is optional)
    const [connectingService, setConnectingService] = useState(null);

    // Check connections on mount - only check Dropbox (Google/YouTube is optional)
    useEffect(() => {
        if (connectionStatus) {
            const dropboxConnected = connectionStatus.dropbox?.connected;
            const canConnectDropbox = connectionStatus.dropbox?.can_connect;
            
            // Only show connect modal for Dropbox - Google is optional
            if (!dropboxConnected && canConnectDropbox) {
                setConnectionStep('dropbox');
                setShowConnectionModal(true);
            }
        }
    }, [connectionStatus]);

    const handleConnectService = (service) => {
        setConnectingService(service);
        const connectUrl = service === 'google' 
            ? connectionStatus.google.connect_url 
            : connectionStatus.dropbox.connect_url;
        // Open in same window to handle OAuth flow
        window.location.href = connectUrl;
    };

    const handleSkipConnection = () => {
        // Simply close the modal - Google is optional, Dropbox can be skipped too
        setShowConnectionModal(false);
    };

    const fetchData = async () => {
        try {
            setLoading(true);
            const params = new URLSearchParams({
                keywords_page: keywordsPage,
                keywords_per_page: keywordsPerPage,
            });
            const response = await fetch(`${route('admin.dashboard.stats')}?${params}`, {
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                method: 'GET',
            });
            const data = await response.json();
            console.log('Dashboard Stats:', data);
            if (data.success) {
                setDashboardStats(data);
                if (data.search_analytics?.keywords_pagination?.total !== undefined) {
                    setKeywordsTotal(data.search_analytics.keywords_pagination.total);
                }
            }
        } catch (error) {
            console.error('Error fetching dashboard stats:', error);
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        fetchData();
    }, [keywordsPage]);

    // Get chart data from API or use defaults
    const chartData = dashboardStats?.charts || {
        weekly: {
            categories: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            data: [0, 0, 0, 0, 0, 0, 0]
        },
        monthly: {
            categories: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
            data: [0, 0, 0, 0]
        }
    };

    const handleChartPeriodChange = (newValue) => {
        setChartPeriod(newValue);
    };


    const getBarChartOptions = () => ({
        chart: {
            type: 'column',
            backgroundColor: '#1F2324',
            height: '300px'
        },
        accessibility: {
            enabled: false
        },
        title: {
            text: null
        },
        xAxis: {
            categories: chartPeriod === 0 ? chartData.weekly.categories : chartData.monthly.categories,
            labels: {
                style: {
                    color: '#9CA3AF'
                }
            },
            lineColor: '#2A303C',
            tickColor: '#2A303C'
        },
        yAxis: {
            title: {
                text: null
            },
            gridLineColor: '#2A303C',
            labels: {
                style: {
                    color: '#9CA3AF'
                }
            }
        },
        legend: {
            enabled: false
        },
        plotOptions: {
            column: {
                borderRadius: 5,
                color: '#374151',
                states: {
                    hover: {
                        color: '#4B5563'
                    }
                }
            },
            series: {
                pointWidth: chartPeriod === 0 ? 30 : 50
            }
        },
        series: [{
            data: chartPeriod === 0 ? chartData.weekly.data : chartData.monthly.data,
            color: {
                linearGradient: { x1: 0, x2: 0, y1: 0, y2: 1 },
                stops: [
                    [0, '#22C55E'],
                    [1, 'rgba(34, 197, 94, 0.2)']
                ]
            }
        }],
        tooltip: {
            backgroundColor: '#1A1A1A',
            borderColor: '#2A303C',
            borderRadius: 8,
            style: {
                color: '#fff'
            },
            formatter: function () {
                return `<b>${this.y}</b> videos`;
            }
        },
        credits: {
            enabled: false
        }
    });
    const getpieChartOptions = {
        chart: {
            type: 'pie',
            backgroundColor: 'transparent',
            height: 300,
        },
        accessibility: {
            enabled: false
        },
        title: {
            text: '',
        },
        plotOptions: {
            pie: {
                innerSize: '68%',
                size: '100%',
                borderWidth: 0,
                startAngle: 90,
                dataLabels: {
                    enabled: false, // hide text around slices
                },
                showInLegend: true, // ✅ enable legend
                states: {
                    hover: { enabled: true },
                },
            },
        },
        legend: {
            layout: 'vertical',
            align: 'center',
            verticalAlign: 'bottom', // ✅ move legend below
            symbolRadius: 6,
            symbolHeight: 12,
            symbolWidth: 12,
            itemMarginTop: 0,
            itemMarginBottom: 2,
            itemStyle: {
                color: '#fff',
                fontSize: '13px',
                fontWeight: '400',
            },
            labelFormatter: function () {
                return `${this.name} <span style="float:right;">${this.percentage.toFixed(1)}%</span>`;
            },

        },
        series: [
            {
                name: 'Source',
                data: dashboardStats ? [
                    { 
                        name: 'On Dropbox',
                        y: dashboardStats.stats.dropbox_videos,
                        color: '#0061FF'
                    },
                    {
                        name: 'On YouTube', 
                        y: dashboardStats.stats.youtube_videos, 
                        color: '#EE1D52' 
                    }
                ] : [
                        { name: 'On Dropbox', y: 0, color: '#0061FF' },
                        { name: 'On YouTube', y: 0, color: '#EE1D52' },
                        { name: 'Processing', y: 0, color: '#F59E0B' },
                ],
            },
        ],
        tooltip: {
            pointFormat: '<b>{point.name}</b>: {point.percentage:.1f}%',
        },
        credits: { enabled: false },
    };
    const handleTrackingTabChange = (newValue) => {
        setTrackingTab(newValue);
    };

    // Calculate stats from API data
    const stats = dashboardStats ? [
        {
            title: t('dashboard.totalVideosUploaded'),
            value: dashboardStats.stats.total_videos.toString(),
            icon: <PeopleOutlinedIcon sx={{ fontSize: 20, color: '#22C55E' }} />,
            iconBg: 'rgba(34, 197, 94, 0.1)'
        },
        {
            title: t('dashboard.storageUsed'),
            value: `${(dashboardStats.stats.total_size_mb / 1024).toFixed(2)}`,
            unit: 'GB',
            icon: <StorageOutlinedIcon sx={{ fontSize: 20, color: '#22C55E' }} />,
            iconBg: 'rgba(34, 197, 94, 0.1)'
        },
        {
            title: t('dashboard.transcriptsGenerated'),
            value: dashboardStats.stats.transcribed_videos.toString(),
            icon: <DescriptionOutlinedIcon sx={{ fontSize: 20, color: '#22C55E' }} />,
            iconBg: 'rgba(34, 197, 94, 0.1)'
        },
        {
            title: t('dashboard.totalHoursProcessed'),
            value: dashboardStats.stats.total_hours_processed.toString(),
            unit: 'hrs',
            icon: <TimerOutlinedIcon sx={{ fontSize: 20, color: '#22C55E' }} />,
            iconBg: 'rgba(34, 197, 94, 0.1)'
        },
        {
            title: t('dashboard.videosWithEmbeddings'),
            value: dashboardStats.stats.embedded_videos.toString(),
            icon: <TrackChangesOutlinedIcon sx={{ fontSize: 20, color: '#22C55E' }} />,
            iconBg: 'rgba(34, 197, 94, 0.1)'
        },
        {
            title: trackingTab === 0 ? t('dashboard.searchesToday') : t('dashboard.searchesThisWeek'),
            value: dashboardStats.stats.searches_today?.toString() || '0',
            icon: <SearchOutlinedIcon sx={{ fontSize: 20, color: '#22C55E' }} />,
            iconBg: 'rgba(34, 197, 94, 0.1)',
            tabs: {
                daily: dashboardStats.stats.searches_today?.toString() || '0',
                weekly: dashboardStats.stats.searches_this_week?.toString() || '0'
            }
        }
    ] : [];

    // Get role badge for welcome banner
    const getRoleWelcomeBanner = () => {
        const role = user?.role?.toLowerCase().replace(/[_ ]/g, '');

        const bannerConfig = {
            superadmin: {
                title: '👑 Welcome, Super Admin!',
                subtitle: 'You have full system access and control',
                gradient: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                glow: '0 0 30px rgba(102, 126, 234, 0.3)'
            },
            admin: {
                title: '⚡ Welcome, Admin!',
                subtitle: 'Manage users, content, and system settings',
                gradient: 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
                glow: '0 0 25px rgba(240, 147, 251, 0.3)'
            },
            manager: {
                title: '🎯 Welcome, Manager!',
                subtitle: 'Oversee operations and team performance',
                gradient: 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
                glow: '0 0 25px rgba(79, 172, 254, 0.3)'
            }
        };

        return bannerConfig[role] || null;
    };

    const welcomeBanner = getRoleWelcomeBanner();

    return (
        <AuthenticatedLayout
            header={<h2 className="font-semibold text-2xl text-white leading-tight">{t('dashboard.title')}</h2>}
        >
            <Box sx={{ direction: i18n.language === 'ur' ? 'rtl' : 'ltr' }}>

                {/* Welcome Banner for Privileged Users */}
                {welcomeBanner && (
                    <Card
                        sx={{
                            mb: 3,
                            background: welcomeBanner.gradient,
                            borderRadius: '20px',
                            p: 3,
                            boxShadow: welcomeBanner.glow,
                            border: '2px solid rgba(255, 255, 255, 0.2)',
                            position: 'relative',
                            overflow: 'hidden',
                            '&::before': {
                                content: '""',
                                position: 'absolute',
                                top: 0,
                                left: 0,
                                right: 0,
                                bottom: 0,
                                background: 'radial-gradient(circle at top right, rgba(255,255,255,0.2) 0%, transparent 60%)',
                                pointerEvents: 'none'
                            }
                        }}
                    >
                        <Box sx={{ position: 'relative', zIndex: 1 }}>
                            <Typography
                                variant="h4"
                                sx={{
                                    color: 'white',
                                    fontWeight: 700,
                                    mb: 1,
                                    textShadow: '0 2px 10px rgba(0,0,0,0.2)'
                                }}
                            >
                                {welcomeBanner.title}
                            </Typography>
                            <Typography
                                variant="body1"
                                sx={{
                                    color: 'rgba(255, 255, 255, 0.95)',
                                    fontSize: '1.1rem',
                                    textShadow: '0 1px 5px rgba(0,0,0,0.1)'
                                }}
                            >
                                {welcomeBanner.subtitle}
                            </Typography>
                        </Box>
                    </Card>
                )}

            <Grid container spacing={3} columns={12}>
                {stats.map((stat, index) => (
                    <Grid item size={{ xs: 12, sm: 6, md: 4, lg: 4, xl: 4 }} key={index}>
                        <Card
                            sx={{
                                p: 3,
                                pt: stat.tabs ? 0 : 3,
                                pb: stat.tabs ? 2 : 3,
                                backgroundColor: '#1f2324',
                                borderRadius: '20px',
                            }}
                        >

                            <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
                                <Box
                                    sx={{
                                        p: 0.5,
                                        borderRadius: '50%',
                                        backgroundColor: stat.iconBg,
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        width: 40,
                                        height: 40,
                                        flexShrink: 0,
                                        mt: stat.tabs ? 2 : 0
                                    }}
                                >
                                    {stat.icon}
                                </Box>
                                <Box sx={{ flexGrow: 1 }}>
                                    <Box sx={{ display: 'flex', justifyContent: "space-between", alignItems: 'center' }}>
                                        <Typography sx={{ color: '#fff', fontSize: '1rem', mb: 0.5, mt: stat.tabs ? 3.5 : 0 }}>
                                            {stat.title}
                                        </Typography>
                                        {stat.tabs && (
                                            <Box
                                                sx={{
                                                    display: 'flex',
                                                    gap: { xs: 0.5, sm: 1 },
                                                    justifyContent: 'flex-end',
                                                    borderRadius: '999px',
                                                    width: 'fit-content',
                                                    ml: 'auto',
                                                }}
                                            >

                                                <Box
                                                    sx={{
                                                        display: 'flex',
                                                        gap: { xs: 0.5, sm: 1 },
                                                        bgcolor: '#1A1A1A',
                                                        p: { xs: 0.3, sm: 0.5 },
                                                        justifyContent: 'flex-end',
                                                        borderRadius: '999px',
                                                        width: 'fit-content'
                                                    }}>

                                                    {[t('dashboard.daily'), t('dashboard.weekly')].map((label, idx) => (

                                                        <Box
                                                            key={label}
                                                            onClick={() => handleTrackingTabChange(idx)}
                                                            sx={{
                                                                px: { xs: 0.75, sm: 1 },
                                                                py: { xs: 0.4, sm: 0.5 },
                                                                borderRadius: '999px',
                                                                cursor: 'pointer',
                                                                fontSize: { xs: '0.6rem', sm: '0.65rem' },
                                                                bgcolor: trackingTab === idx ? '#EE1D52' : '#6A6A6A',
                                                                color: trackingTab === idx ? 'white' : '#000',
                                                                transition: 'all 0.2s',
                                                                whiteSpace: 'nowrap',
                                                                fontWeight: 500
                                                            }}
                                                        >
                                                            {label}
                                                        </Box>
                                                    ))}

                                                </Box>
                                            </Box>
                                        )}
                                    </Box>
                                    <Typography variant="h5" sx={{ color: 'white', fontWeight: 600, mb: stat.tabs ? 0.55 : 0, fontSize: "1.4rem" }}>
                                        {stat.tabs
                                            ? (trackingTab === 0 ? stat.tabs.daily : stat.tabs.weekly)
                                            : stat.value}
                                        {stat.unit && (
                                            <Typography component="span" sx={{ color: '#fff', fontSize: stat.unit === '%' ? '1.4rem' : '0.875rem', ml: 0.8 }}>
                                                {stat.unit}
                                            </Typography>
                                        )}
                                    </Typography>
                                </Box>
                            </Box>

                        </Card>
                    </Grid>
                ))}
            </Grid>

            <Grid container spacing={3} sx={{ mt: 3 }}>
                <Grid item size={{ xs: 12, md: 6 }}>
                    <Card
                        sx={{
                            p: 3,
                            backgroundColor: '#1F2324',
                            borderRadius: '12px',
                            border: '1px solid #2A303C'
                        }}
                    >
                        <Box sx={{ 
                            display: 'flex', 
                            flexDirection: { xs: 'column', sm: 'row' },
                            justifyContent: 'space-between', 
                            alignItems: { xs: 'flex-start', sm: 'center' },
                            gap: { xs: 1.5, sm: 0 },
                            mb: 2 
                        }}>
                            <Typography sx={{ 
                                color: 'white', 
                                fontSize: { xs: '1rem', sm: '1.1rem' }, 
                                fontWeight: 500 
                            }}>
                                {t('dashboard.videosUploaded')}
                            </Typography>
                            <Box
                                sx={{
                                    display: 'flex',
                                    gap: { xs: 0.5, sm: 1 },
                                    bgcolor: '#3A3A3A',
                                    p: { xs: 0.3, sm: 0.5 },
                                    borderRadius: '999px',
                                    flexShrink: 0
                                }}
                            >
                                {[t('dashboard.weekly'), t('dashboard.monthly')].map((label, idx) => (
                                    <Box
                                        key={label}
                                        onClick={() => handleChartPeriodChange(idx)}
                                        sx={{
                                            px: { xs: 1, sm: 2 },
                                            py: { xs: 0.4, sm: 0.5 },
                                            borderRadius: '999px',
                                            cursor: 'pointer',
                                            fontSize: { xs: '0.65rem', sm: '0.75rem' },
                                            bgcolor: chartPeriod === idx ? '#EE1D52' : '#6A6A6A',
                                            color: chartPeriod === idx ? 'white' : 'black',
                                            transition: 'all 0.2s',
                                            whiteSpace: 'nowrap',
                                            fontWeight: 500
                                        }}
                                    >
                                        {label}
                                    </Box>
                                ))}
                            </Box>
                        </Box>
                        <HighchartsReact
                            highcharts={Highcharts}
                            options={getBarChartOptions()}
                        />
                    </Card>
                </Grid>
                <Grid item size={{ xs: 12, md: 6 }}>
                    <Card
                        sx={{
                            p: 3,
                            backgroundColor: '#1F2324',
                            borderRadius: '12px',
                            border: '1px solid #2A303C'
                        }}
                    >
                        <Typography sx={{ color: 'white', fontSize: '1.1rem', fontWeight: 500, mb: 3 }}>
                            {t('dashboard.distributionBySource')}
                        </Typography>
                        <Box sx={{ width: '100%', display: 'flex', justifyContent: 'center' }}>
                            <Box sx={{ position: 'relative', }}>

                                <div
                                    style={{
                                        backgroundColor: '',
                                        borderRadius: '12px',
                                        padding: '',
                                        width: '420px',
                                        color: '#fff',
                                        position: 'relative',
                                    }}
                                >
                                    <HighchartsReact highcharts={Highcharts} options={getpieChartOptions} />

                                    {/* Center Label */}
                                    <div
                                        style={{
                                            position: 'absolute',
                                            top: '38%',
                                            left: '50%',
                                            transform: 'translate(-50%, -50%)',
                                            textAlign: 'center',
                                        }}
                                    >
                                        <div style={{ color: '#fff', fontSize: '13px', fontWeight: '500' }}>
                                            {t('dashboard.distributionBySource').split(' ')[0]}
                                        </div>
                                        <div style={{ color: '#fff', fontSize: '13px', fontWeight: '500' }}>
                                            {t('dashboard.distributionBySource').split(' ').slice(1).join(' ')}
                                        </div>
                                    </div>
                                </div>
                            </Box>
                        </Box>
                    </Card>
                </Grid>
            </Grid>

            {/* Most searched keywords section */}
            <Box sx={{ mt: 3,}}>
                <Card sx={{ 
                    p: 3,
                    backgroundColor: '#1F2324',
                    borderRadius: '12px',
                    border: '1px solid #2A303C'
                }}>
                        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 4 }}>
                            <Typography sx={{
                                color: '#9CA3AF',
                                fontSize: '16px',
                                fontWeight: 500,
                                textTransform: 'capitalize'
                            }}>
                                {t('dashboard.mostSearchedKeywords')}
                            </Typography>

                            {/* Pagination Controls */}
                            {keywordsTotal > keywordsPerPage && (
                                <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                                    <IconButton
                                        onClick={() => setKeywordsPage(prev => Math.max(1, prev - 1))}
                                        disabled={keywordsPage === 1 || loading}
                                        size="small"
                                        sx={{
                                            color: keywordsPage === 1 ? '#4B5563' : '#9CA3AF',
                                            '&:hover': { bgcolor: 'rgba(255, 255, 255, 0.05)' },
                                            '&:disabled': { color: '#4B5563' }
                                        }}
                                    >
                                        <ChevronLeftIcon fontSize="small" />
                                    </IconButton>
                                    <Typography sx={{ color: '#9CA3AF', fontSize: '13px', mx: 1 }}>
                                        {keywordsPage} / {Math.ceil(keywordsTotal / keywordsPerPage)}
                                    </Typography>
                                    <IconButton
                                        onClick={() => setKeywordsPage(prev => Math.min(Math.ceil(keywordsTotal / keywordsPerPage), prev + 1))}
                                        disabled={keywordsPage >= Math.ceil(keywordsTotal / keywordsPerPage) || loading}
                                        size="small"
                                        sx={{
                                            color: keywordsPage >= Math.ceil(keywordsTotal / keywordsPerPage) ? '#4B5563' : '#9CA3AF',
                                            '&:hover': { bgcolor: 'rgba(255, 255, 255, 0.05)' },
                                            '&:disabled': { color: '#4B5563' }
                                        }}
                                    >
                                        <ChevronRightIcon fontSize="small" />
                                    </IconButton>
                                </Box>
                            )}
                        </Box>

                        <Box sx={{ display: 'flex', flexDirection: 'column', minHeight: '400px' }}>
                            {loading ? (
                                <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', py: 8 }}>
                                    <CircularProgress size={40} sx={{ color: '#22C55E' }} />
                                </Box>
                            ) : (dashboardStats?.search_analytics?.top_keywords || []).length > 0 ? (
                                dashboardStats.search_analytics.top_keywords.map((item, index) => (
                                    <Box key={index}>
                                        <Box sx={{
                                            display: 'flex',
                                            alignItems: 'center',
                                            py: 2
                                        }}>
                                            <Typography sx={{
                                                color: '#FFFFFF',
                                                fontSize: '14px',
                                                flexGrow: 1,
                                                fontWeight: 500
                                            }}>
                                                {item.keyword}
                                            </Typography>
                                            <Typography sx={{
                                                color: '#22C55E',
                                                fontSize: '14px',
                                                fontWeight: 600,
                                                ml: 2
                                            }}>
                                                {item.count}
                                            </Typography>
                                        </Box>
                                        {index < dashboardStats.search_analytics.top_keywords.length - 1 && (
                                            <Box
                                                sx={{
                                                    width: '100%',
                                                    height: '1px',
                                                    backgroundColor: '#2A303C'
                                                }}
                                            />
                                        )}
                                    </Box>
                                ))
                            ) : (
                                        <Box sx={{ py: 8, textAlign: 'center' }}>
                                    <Typography sx={{ color: '#6B7280', fontSize: '14px' }}>
                                        {t('dashboard.noSearchDataYet') || 'No search data yet'}
                                    </Typography>
                                </Box>
                            )}
                        </Box>
                    </Card>
                </Box>

        {/* Connection Required Modal */}
        <Dialog 
            open={showConnectionModal} 
            onClose={() => {}}
            maxWidth="sm"
            fullWidth
            PaperProps={{
                sx: {
                    backgroundColor: '#1F2324',
                    borderRadius: '16px',
                    border: '1px solid #2A303C',
                }
            }}
        >
            <DialogTitle sx={{ color: 'white', textAlign: 'center', pt: 4 }}>
                <Box sx={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 2 }}>
                    {connectionStep === 'google' ? (
                        <GoogleIcon sx={{ fontSize: 48, color: '#EA4335' }} />
                    ) : (
                        <CloudIcon sx={{ fontSize: 48, color: '#0061FF' }} />
                    )}
                    <Typography variant="h5" sx={{ fontWeight: 600 }}>
                        {connectionStep === 'google' ? t('dashboard.connectYoutubeGoogle') : t('dashboard.connectDropbox')}
                    </Typography>
                </Box>
            </DialogTitle>
            <DialogContent sx={{ textAlign: 'center', pb: 2 }}>
                <Typography sx={{ color: '#9CA3AF', mb: 3 }}>
                    {connectionStep === 'google' 
                        ? t('dashboard.googleConnectionDesc')
                        : t('dashboard.dropboxConnectionDesc')}
                </Typography>
                
                {/* Connection Status Indicators */}
                <Box sx={{ display: 'flex', justifyContent: 'center', gap: 4, mb: 3 }}>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                        <Box sx={{ 
                            width: 12, 
                            height: 12, 
                            borderRadius: '50%', 
                            backgroundColor: connectionStatus?.google?.connected ? '#22C55E' : '#EF4444' 
                        }} />
                        <Typography sx={{ color: '#9CA3AF', fontSize: '14px' }}>
                            {connectionStatus?.google?.connected ? t('dashboard.googleConnected') : t('dashboard.googleNotConnected')}
                        </Typography>
                    </Box>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                        <Box sx={{ 
                            width: 12, 
                            height: 12, 
                            borderRadius: '50%', 
                            backgroundColor: connectionStatus?.dropbox?.connected ? '#22C55E' : '#EF4444' 
                        }} />
                        <Typography sx={{ color: '#9CA3AF', fontSize: '14px' }}>
                            {connectionStatus?.dropbox?.connected ? t('dashboard.dropboxConnected') : t('dashboard.dropboxNotConnected')}
                        </Typography>
                    </Box>
                </Box>

                {!connectionStatus?.google?.connected && connectionStep === 'dropbox' && (
                    <Alert severity="warning" sx={{ mb: 2, backgroundColor: 'rgba(245, 158, 11, 0.1)', color: '#F59E0B' }}>
                        {t('dashboard.googleNotConnectedWarning')}
                    </Alert>
                )}
            </DialogContent>
            <DialogActions sx={{ justifyContent: 'center', pb: 4, gap: 2 }}>
                <Button 
                    variant="outlined" 
                    onClick={handleSkipConnection}
                    sx={{ 
                        color: '#9CA3AF', 
                        borderColor: '#2A303C',
                        '&:hover': { borderColor: '#4A4A4A', backgroundColor: 'rgba(255,255,255,0.05)' }
                    }}
                >
                    {t('dashboard.skipForNow')}
                </Button>
                <Button 
                    variant="contained" 
                    onClick={() => handleConnectService(connectionStep)}
                    disabled={connectingService !== null}
                    sx={{ 
                        backgroundColor: connectionStep === 'google' ? '#EA4335' : '#0061FF',
                        '&:hover': { 
                            backgroundColor: connectionStep === 'google' ? '#C5382D' : '#0052D9' 
                        },
                        minWidth: 150
                    }}
                >
                    {connectingService === connectionStep ? (
                        <CircularProgress size={24} sx={{ color: 'white' }} />
                    ) : (
                        `${t('dashboard.connect')} ${connectionStep === 'google' ? 'Google' : 'Dropbox'}`
                    )}
                </Button>
            </DialogActions>
        </Dialog>
        </Box>
        </AuthenticatedLayout>
    );
}