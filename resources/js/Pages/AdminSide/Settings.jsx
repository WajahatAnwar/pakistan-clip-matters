import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import React from 'react';
import { useTranslation } from 'react-i18next';
import {
    Box,
    Typography,
    Switch,
    Stack,
    Paper
} from '@mui/material';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import CloudWays from '@/Images/Cloudways.png';
import QdrantCloud from '@/Images/Qdrant Cloud.png';
import Mailgun from '@/Images/Mailgun.png';
import Youtube from '@/Images/Youtube.png';
import Dropbox from '@/Images/Dropbox.png';
import Transcipt from '@/Images/Transcipt.png';
import Tick from '@/Images/Tick.png';
import Cross from '@/Images/Cross.png';
import SpeakerIdentification from '@/Images/SpeakerIdentification.png';
import { useEffect , useState} from 'react';
import { usePage } from '@inertiajs/react';
import { Google } from '@mui/icons-material';
export default function Settings() {
    const { t } = useTranslation();
    //const shopify = useAppBridge();
    const page = usePage().props;
    //const { query } = page.ziggy;
    const [emailNotification, setEmailNotification] = useState(false);
    const [serviceStatus, setServiceStatus] = useState({});
    const settings = [
        {
            id: 'dropbox',
            title: t('settings.dropbox'),
            icon: Dropbox
        },
        {
            id: 'youtube',
            title: t('settings.youtube'),
            icon: Youtube
        },
        {
            id: 'google-cloud',
            title: t('settings.googleCloud'),
            icon: Google,
            isMuiIcon: true
        },
        // {
        //     id: 'cloud-ways',
        //     title: 'Cloud ways',
        //     icon: CloudWays
        // },
        // {
        //     id: 'transcript-mode',
        //     title: 'Transcript Mode',
        //     icon: Transcipt
        // },
        // {
        //     id: 'mail-gun',
        //     title: 'Mail Gun',
        //     icon: Mailgun
        // }
    ];
  useEffect(() => {
    fetchSettings();
  }, []);

    const fetchSettings = async () => {
    try {
        const response = await fetch(route('admin.settings.get'), {
        method: 'GET',
        headers: { 'Content-Type': 'application/json' },
        });

        if (response.ok) {
        const data = await response.json();
        console.log("Settings Data:", data);

        setServiceStatus(data.services);
            setEmailNotification(data.email_notifications || false);
        } else {
        console.error('Failed to fetch settings');
        }
    } catch (error) {
        console.error('Error fetching settings:', error);
    }
    };

    const handleEmailNotificationChange = async (e) => {
        const enabled = e.target.checked;
        setEmailNotification(enabled);

        try {
            const response = await fetch(route('admin.settings.email.notifications'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                },
                body: JSON.stringify({ enabled })
            });

            if (response.ok) {
                const data = await response.json();
                console.log('Email notification preference updated:', data);
            } else {
                console.error('Failed to update email notification preference');
                // Revert the toggle if save failed
                setEmailNotification(!enabled);
            }
        } catch (error) {
            console.error('Error updating email notification:', error);
            // Revert the toggle if save failed
            setEmailNotification(!enabled);
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="font-semibold text-2xl text-white leading-tight">{t('settings.title')}</h2>}
        >
            <Box sx={{ p: 3 }}>
                <Stack spacing={2}>
                    {/* Email Notification Box */}
                    <Paper sx={{ 
                        bgcolor: '#191919', 
                        borderRadius: 2,
                        p: 2,
                        display: 'flex',
                        alignItems: 'center',
                        border: '1px solid #3C3C3C',
                        justifyContent: 'space-between',
                        minHeight: "78px"

                    }}>
                        <Typography sx={{ 
                            color: 'white',
                            fontSize: '14px',
                            fontWeight: 500
                        }}>
                            {t('settings.emailNotification')}
                        </Typography>
                        <Switch
                            checked={emailNotification}
                            onChange={handleEmailNotificationChange}
                            sx={{
                                width: 46,
                                height: 24,
                                padding: 0,
                                '& .MuiSwitch-switchBase': {
                                    padding: 0,
                                    margin: '3px',
                                    color: '#fff',
                                    '&:hover': {
                                        backgroundColor: 'transparent',
                                    },
                                    '&.Mui-checked': {
                                        transform: 'translateX(22px)',
                                        color: '#fff',
                                        '& + .MuiSwitch-track': {
                                            backgroundColor: '#28933F',
                                            opacity: 1,
                                            border: 0,
                                        },
                                    },
                                },
                                '& .MuiSwitch-thumb': {
                                    width: 18,
                                    height: 18,
                                    borderRadius: '50%',
                                },
                                '& .MuiSwitch-track': {
                                    borderRadius: 26 / 2,
                                    backgroundColor: 'transparent',
                                    border: '1px solid #fff',
                                    opacity: 1,
                                    transition: 'background-color 0.2s ease',
                                }
                            }}
                        />
                    </Paper>

                    {/* Other Settings Boxes */}
                    {settings.map((setting) => (
                        <Paper 
                            key={setting.id}
                            sx={{ 
                                bgcolor: '#191919', 
                                borderRadius: 2,
                                p: 2,
                                pr: 3,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                border: '1px solid #3C3C3C',
                                minHeight: "78px"

                            }}
                        >
                            <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
                                {setting.icon && (
                                    setting.isMuiIcon ? (
                                        <Box
                                            sx={{
                                                width: 40,
                                                height: 40,
                                                display: 'flex',
                                                alignItems: 'center',
                                                justifyContent: 'center',
                                                bgcolor: '#fff',
                                                borderRadius: 1
                                            }}
                                        >
                                            <setting.icon sx={{ fontSize: 28, color: '#4285F4' }} />
                                        </Box>
                                    ) : (
                                            <Box
                                                component="img"
                                                src={setting.icon}
                                                alt={setting.title}
                                                sx={{
                                                    width: 40,
                                                    height: 40,
                                                    objectFit: 'contain'
                                                }}
                                            />
                                        )
                                )}
                                <Typography sx={{ 
                                    color: 'white',
                                    fontSize: '14px',
                                    fontWeight: 500
                                }}>
                                    {setting.title}
                                </Typography>
                            </Box>
                           
                            <Box
                                component="img"
                                src={serviceStatus[setting.id] ? Tick : Cross}
                                alt={serviceStatus[setting.id] ? "Connected" : "Not Connected"}
                                sx={{ width: 20, height: 20, objectFit: 'contain' }}
                                />
                        </Paper>
                    ))}
                </Stack>
            </Box>
        </AuthenticatedLayout>
    );

}