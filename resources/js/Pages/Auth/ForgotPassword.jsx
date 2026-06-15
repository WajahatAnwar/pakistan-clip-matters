import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import { Box, TextField, Button, Typography, Alert, CircularProgress } from '@mui/material';
import EmailIcon from '@mui/icons-material/Email';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import { useTranslation } from 'react-i18next';

export default function ForgotPassword({ status }) {

    const { t } = useTranslation();
    const statusMessage = status === 'We have emailed your password reset link!'
        ? t('ForgotPassword.resetLinkSent')
        : status;
    


    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('password.email'));
    };

    return (
        <GuestLayout>
            <Head title="Forgot Password" />

            <Box
                sx={{
                    width: '100%',
                    maxWidth: '450px',
                    padding: { xs: 1, sm: 4 },
                    
                    borderRadius: '16px',
                }}
            >
                {/* Header */}
                <Box sx={{ textAlign: 'center', mb: 4 }}>
                    <Typography
                        variant="h4"
                        sx={{
                            color: 'white',
                            fontWeight: 700,
                            mb: 2,
                            fontSize: { xs: '1.75rem', sm: '2rem' }
                        }}
                    >
                        {t('ForgotPassword.title')}
                    </Typography>
                    <Typography
                        variant="body2"
                        sx={{
                            color: 'rgba(255, 255, 255, 0.7)',
                            fontSize: { xs: '0.875rem', sm: '0.95rem' },
                            lineHeight: 1.6
                        }}
                    >
                        {t('ForgotPassword.FGcontent')}
                    </Typography>
                </Box>

                {/* Success Status */}
                {status && (
                    <Alert 
                        severity="success" 
                        sx={{ 
                            mb: 3,
                            backgroundColor: 'rgba(76, 175, 80, 0.15)',
                            color: '#81C784',
                            border: '1px solid rgba(76, 175, 80, 0.3)',
                            '& .MuiAlert-icon': {
                                color: '#81C784'
                            }
                        }}
                    >
                        {statusMessage}
                    </Alert>
                )}

                {/* Form */}
                <form onSubmit={submit}>
                    <Box sx={{ mb: 3 }}>
                        <TextField
                            fullWidth
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            placeholder={t('ForgotPassword.emailPlaceholder')}
                            autoFocus
                            error={!!errors.email}
                            helperText={errors.email}
                            InputProps={{
                                startAdornment: (
                                    <EmailIcon sx={{ color: 'rgba(255, 255, 255, 0.5)', mr: 1, fontSize: 20 }} />
                                ),
                            }}
                            sx={{
                                '& .MuiOutlinedInput-root': {
                                    backgroundColor: 'rgba(255, 255, 255, 0.05)',
                                    borderRadius: '12px',
                                    color: 'white',
                                    '& fieldset': {
                                        borderColor: 'rgba(255, 255, 255, 0.1)',
                                    },
                                    '&:hover fieldset': {
                                        borderColor: 'rgba(255, 255, 255, 0.2)',
                                    },
                                    '&.Mui-focused fieldset': {
                                        borderColor: '#F01F32',
                                    },
                                },
                                '& .MuiInputBase-input': {
                                    color: 'white',
                                    '&::placeholder': {
                                        color: 'rgba(255, 255, 255, 0.5)',
                                        opacity: 1,
                                    },
                                },
                                '& .MuiFormHelperText-root': {
                                    color: '#f44336',
                                    marginLeft: 0,
                                    marginTop: '8px'
                                }
                            }}
                        />
                    </Box>

                    <Button
                        type="submit"
                        fullWidth
                        disabled={processing}
                        variant="contained"
                        sx={{
                            backgroundColor: '#F01F32',
                            color: 'white',
                            py: 1.5,
                            borderRadius: '12px',
                            textTransform: 'none',
                            fontSize: '1rem',
                            fontWeight: 600,
                            mb: 2,
                            '&:hover': {
                                backgroundColor: '#d01828',
                            },
                            '&:disabled': {
                                backgroundColor: 'rgba(240, 31, 50, 0.5)',
                                color: 'rgba(255, 255, 255, 0.7)',
                            },
                        }}
                    >
                        {processing ? (
                            <CircularProgress size={24} sx={{ color: 'white' }} />
                        ) : (
                            t('ForgotPassword.EmailPasswordResetLink')
                        )}
                    </Button>

                    {/* Back to Login */}
                    <Box sx={{ textAlign: 'center' }}>
                        <Link href={route('login')} style={{ textDecoration: 'none' }}>
                            <Button
                                // startIcon={<ArrowBackIcon />}
                                sx={{
                                    color: 'rgba(255, 255, 255, 0.7)',
                                    textTransform: 'none',
                                    fontSize: '0.9rem',
                                    '&:hover': {
                                        color: 'white',
                                        backgroundColor: 'rgba(255, 255, 255, 0.05)',
                                    },
                                }}
                            >
                                {t('ForgotPassword.backToLogin')}
                            </Button>
                        </Link>
                    </Box>
                </form>
            </Box>
        </GuestLayout>
    );
}
