import { Link, router, usePage } from '@inertiajs/react';
import MainLogo from '@/Images/main-logo.svg';
import { IconButton, Box } from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';
import { useTranslation } from 'react-i18next';

export default function Sidebar({ onClose }) {
    const { t } = useTranslation();
    const user = usePage().props.auth?.user;
    const userRole = user?.role?.toLowerCase().replace(/[_ ]/g, '');
    const navItems = [
        
        {
            name: t('sidebar.dashboard'), href: 'admin.dashboard', icon: (
                <svg className="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M4 13h6a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1zm0 8h6a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1zm10-8h6a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1h-6a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1zm0 8h6a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1h-6a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1z" />
                </svg>
            )
        },
        {
            name: t('sidebar.search'), href: 'user.search.page', icon: (
                <svg className="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
                </svg>
            ), openInNewTab: true
        },
        {
            name: t('sidebar.videoManagement'), href: 'admin.video.management', icon: (
                <svg className="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M4 8h16v12H4zm0-2V4h16v2zm3 14v2h10v-2z" />
                </svg>
            )
        },
        {
            name: t('sidebar.videoApproval'), href: 'admin.video.approval', icon: (
                <svg className="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
                </svg>
            )
        },
        {
            name: t('sidebar.settings'), href: 'admin.settings', roles: ['superadmin'], icon: (
                <svg className="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 15.5A3.5 3.5 0 0 1 8.5 12 3.5 3.5 0 0 1 12 8.5a3.5 3.5 0 0 1 3.5 3.5 3.5 3.5 0 0 1-3.5 3.5zm7.43-2.53c.04-.32.07-.64.07-.97 0-.33-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65A.488.488 0 0 0 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98 0 .33.03.65.07.97l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.09.49 0 .61-.22l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.65z" />
                </svg>
            )
        },
        {
            name: t('sidebar.userManagement'), href: 'admin.user.management', icon: (
                <svg className="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                </svg>
            )
        },
        {
            name: t('sidebar.voiceSample'), href: 'admin.voice.sample', icon: (
                <svg className="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.91-3c-.49 0-.9.36-.98.85C16.52 14.2 14.47 16 12 16s-4.52-1.8-4.93-4.15c-.08-.49-.49-.85-.98-.85-.61 0-1.09.54-1 1.14.49 3 2.89 5.35 5.91 5.78V20c0 .55.45 1 1 1s1-.45 1-1v-2.08c3.02-.43 5.42-2.78 5.91-5.78.1-.6-.39-1.14-1-1.14z" />
                </svg>
            )
        },
    ];

    return (
        <div className="h-screen w-full bg-[#1F2324] text-white p-6 flex flex-col">
            <Box sx={{
                mb: 8,
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                px: 2
            }}>
                <img src={MainLogo} alt="Pakistan Matters" className="w-36" />
                <IconButton
                    onClick={onClose}
                    sx={{
                        color: 'white',
                        display: { xs: 'flex', md: 'none' }
                    }}
                >
                    <CloseIcon />
                </IconButton>
            </Box>

            <nav className="flex-1">
                <ul className="space-y-2">
                    {navItems.filter(item => !item.roles || item.roles.includes(userRole)).map((item) => (
                        <li key={item.name}>
                            {item.openInNewTab ? (
                                <a
                                    href={route(item.href)}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={`flex items-center px-4 py-3 text-sm rounded-lg transition-colors hover:bg-[#EE1D52] hover:text-white text-gray-400`}
                                >
                                    {item.icon}
                                    <span className="ml-3">{item.name}</span>
                                </a>
                            ) : (
                                    <Link
                                        href={route(item.href)}
                                        className={`flex items-center px-4 py-3 text-sm rounded-lg transition-colors hover:bg-[#EE1D52] hover:text-white ${route().current(item.href)
                                            ? 'bg-[#EE1D52] text-white'
                                            : 'text-gray-400'
                                            }`}
                                    >
                                        {item.icon}
                                        <span className="ml-3">{item.name}</span>
                                    </Link>
                            )}
                        </li>
                    ))}
                </ul>
            </nav>

            <div className="mt-auto mobile-logout-button">
                <button
                    className="flex items-center px-4 py-3 text-sm text-red-500 hover:bg-[#2A303C] rounded-lg w-full"
                    onClick={() => router.post(route('logout'))}
                >
                    <svg className="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z" />
                    </svg>
                    <span className="ml-3">{t('sidebar.logout')}</span>
                </button>
            </div>
        </div>
    );
}