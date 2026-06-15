import { Grid, IconButton, Menu, MenuItem, Avatar, Box, Chip } from '@mui/material';
import MenuIcon from '@mui/icons-material/Menu';
import Sidebar from '@/Components/Sidebar';
import { usePage, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import '../../css/style.css';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import { useTranslation } from 'react-i18next';

export default function AuthenticatedLayout({ children, header }) {
    const user = usePage().props.auth.user;
    const { i18n, t } = useTranslation();
    const isRTL = i18n.language === 'ur';
    const [anchorEl, setAnchorEl] = useState(null);
    const [isSidebarOpen, setIsSidebarOpen] = useState(false);
    const open = Boolean(anchorEl);

    // Get user role badge configuration
    const getRoleBadge = () => {
        const role = user?.role?.toLowerCase().replace(/[_ ]/g, '');

        const badgeConfig = {
            superadmin: {
                label: 'SUPER ADMIN',
                bgColor: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                textColor: '#FFFFFF',
                glow: '0 0 20px rgba(102, 126, 234, 0.6)',
                icon: '👑'
            },
            admin: {
                label: 'ADMIN',
                bgColor: 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
                textColor: '#FFFFFF',
                glow: '0 0 15px rgba(240, 147, 251, 0.5)',
                icon: '⚡'
            },
            manager: {
                label: 'MANAGER',
                bgColor: 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
                textColor: '#FFFFFF',
                glow: '0 0 15px rgba(79, 172, 254, 0.5)',
                icon: '🎯'
            }
        };

        return badgeConfig[role] || null;
    };

    const roleBadge = getRoleBadge();

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

    return (
        <Grid container sx={{ height: '100vh', overflow: 'hidden' }}>
            <Grid 
                item 
                sx={{ 
                    width: { xs: '100%', md: '20%' },
                    backgroundColor: '#1F2324',
                    position: 'fixed',
                    height: '100vh',
                    overflow: 'auto',
                    ...(isRTL ? {
                        right: 0,
                        transform: { xs: isSidebarOpen ? 'translateX(0)' : 'translateX(100%)', md: 'translateX(0)' },
                    } : {
                        left: 0,
                        transform: { xs: isSidebarOpen ? 'translateX(0)' : 'translateX(-100%)', md: 'translateX(0)' },
                    }),
                    transition: 'transform 0.3s ease-in-out',
                    zIndex: 1200,
                    '&::-webkit-scrollbar': {
                        width: '6px',
                    },
                    '&::-webkit-scrollbar-thumb': {
                        backgroundColor: 'rgba(255, 255, 255, 0.1)',
                        borderRadius: '3px',
                    },
                    '&::-webkit-scrollbar-track': {
                        backgroundColor: 'transparent',
                    }
                }}
            >
                <Sidebar onClose={() => setIsSidebarOpen(false)} />
            </Grid>
            <Grid 
                item 
                sx={{ 
                    width: { xs: '100%', md: '80%' },
                    backgroundColor: '#0C0E10',
                    minHeight: '100vh',
                    ...(isRTL ? {
                        marginRight: { xs: 0, md: '20%' },
                    } : {
                        marginLeft: { xs: 0, md: '20%' },
                    }),
                    overflow: 'auto',
                    '&::-webkit-scrollbar': {
                        width: '6px',
                    },
                    '&::-webkit-scrollbar-thumb': {
                        backgroundColor: 'rgba(255, 255, 255, 0.1)',
                        borderRadius: '3px',
                    },
                    '&::-webkit-scrollbar-track': {
                        backgroundColor: 'transparent',
                    }
                }}
            >
                <div className='h-screen'>

                <div className="px-6 py-4 sticky top-0 bg-[#0C0E10] z-10">
                    <div className="flex flex-col">
                        <div className="flex items-center w-full">
                            <div className="flex-grow flex">
                                <IconButton
                                    color="inherit"
                                    aria-label="open drawer"
                                    onClick={() => setIsSidebarOpen(true)}
                                    sx={{ 
                                        display: { xs: 'flex', md: 'none' },
                                        color: 'white'
                                    }}
                                >
                                    <MenuIcon />
                                </IconButton>
                            </div>
                            <Box sx={{ mr: 2 }}>
                                <LanguageSwitcher />
                            </Box>
                            <IconButton 
                                // onClick={handleClick}
                                size="small"
                                disabled={true}
                                aria-controls={open ? 'account-menu' : undefined}
                                aria-haspopup="true"
                                aria-expanded={open ? 'true' : undefined}
                                sx={{
                                    padding: 0
                                }}
                            >
                                <Avatar 
                                sx={{ 
                                        width: 32,
                                        height: 32,
                                        backgroundColor: 'transparent',
                                        border: '2px solid #374151',
                                        color: 'white',
                                        fontSize: '0.875rem',
                                        '&:hover': {
                                            borderColor: '#4B5563'
                                        }
                                    }}
                                >
                                    {user?.name?.charAt(0) || 'U'}
                                </Avatar>
                            </IconButton>
                        </div>
                            <Box sx={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: 2,
                                flexWrap: 'wrap'
                            }}>
                                <div className="text-white text-2xl font-semibold">
                                    {header}
                                </div>
                                {roleBadge && (
                                    <Box
                                        sx={{
                                            background: roleBadge.bgColor,
                                            color: roleBadge.textColor,
                                            padding: '6px 16px',
                                            borderRadius: '20px',
                                            fontSize: '0.75rem',
                                            fontWeight: 700,
                                            letterSpacing: '0.5px',
                                            display: 'flex',
                                            alignItems: 'center',
                                            gap: 0.5,
                                            boxShadow: roleBadge.glow,
                                            border: '2px solid rgba(255, 255, 255, 0.2)',
                                        }}
                                    >
                                        <span style={{ fontSize: '1rem' }}>{roleBadge.icon}</span>
                                        <span>{roleBadge.label}</span>
                                    </Box>
                                )}
                            </Box>
                      
                    </div>
                </div>
                <Menu
                    id="account-menu"
                    anchorEl={anchorEl}
                    open={open}
                        onClose={handleMenuClose}
                    transformOrigin={{
                        horizontal: 'right',
                        vertical: 'top'
                    }}
                    anchorOrigin={{
                        horizontal: 'right',
                        vertical: 'bottom'
                    }}
                    PaperProps={{
                        sx: {
                            backgroundColor: '#2A303C',
                            color: 'white',
                            marginTop: 1,
                            '& .MuiMenuItem-root': {
                                fontSize: '0.875rem',
                                paddingY: 1,
                                paddingX: 2,
                                '&:hover': {
                                    backgroundColor: '#374151'
                                }
                            }
                        }
                    }}
                >
                        <MenuItem onClick={handleMenuClose}>
                            <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1, width: '100%' }}>
                                <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                                    <span style={{ fontWeight: 600 }}>{user?.name || 'User'}</span>
                                    {roleBadge && (
                                        <Box
                                            sx={{
                                                background: roleBadge.bgColor,
                                                color: roleBadge.textColor,
                                                padding: '2px 8px',
                                                borderRadius: '12px',
                                                fontSize: '0.65rem',
                                                fontWeight: 700,
                                                letterSpacing: '0.3px',
                                                display: 'inline-flex',
                                                alignItems: 'center',
                                                gap: 0.3,
                                                border: '1px solid rgba(255, 255, 255, 0.2)',
                                            }}
                                        >
                                            <span style={{ fontSize: '0.7rem' }}>{roleBadge.icon}</span>
                                            <span>{roleBadge.label}</span>
                                        </Box>
                                    )}
                                </Box>
                                <span style={{ fontSize: '0.75rem', color: '#9CA3AF' }}>{user?.email}</span>
                            </Box>
                        </MenuItem>
                        <MenuItem
                            onClick={handleMenuClose}
                            component={Link}
                            href={route('profile.edit')}
                        >
                            {t('profile.profile')}
                        </MenuItem>
                        <MenuItem onClick={handleLogout} sx={{ color: '#EF4444 !important' }}>
                        {t('profile.logout')}
                    </MenuItem>
                </Menu>
                <div className="p-6">
                    {children}
                </div>

                </div>
            </Grid>
        </Grid>
    );
}