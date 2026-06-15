import UserAuthenticateLayout from '@/Layouts/UserAuthenticateLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Box, Typography, TextField, Button, Paper, Divider, Alert, CircularProgress } from '@mui/material';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function Edit({ mustVerifyEmail, status }) {
    const { t } = useTranslation();
    const user = usePage().props.auth.user;
    const [showSuccess, setShowSuccess] = useState(false);
    const [showPasswordSuccess, setShowPasswordSuccess] = useState(false);

    // Profile Information Form
    const { 
        data: profileData, 
        setData: setProfileData, 
        patch: patchProfile, 
        errors: profileErrors, 
        processing: profileProcessing 
    } = useForm({
        name: user?.name || '',
        email: user?.email || '',
    });

    // Password Form
    const { 
        data: passwordData, 
        setData: setPasswordData, 
        put: putPassword, 
        errors: passwordErrors, 
        processing: passwordProcessing,
        reset: resetPassword
    } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submitProfile = (e) => {
        e.preventDefault();
        patchProfile(route('user.profile.update'), {
            onSuccess: () => {
                setShowSuccess(true);
                setTimeout(() => setShowSuccess(false), 3000);
            }
        });
    };

    const submitPassword = (e) => {
        e.preventDefault();
        putPassword(route('password.update'), {
            onSuccess: () => {
                setShowPasswordSuccess(true);
                resetPassword();
                setTimeout(() => setShowPasswordSuccess(false), 3000);
            }
        });
    };

    return (
        <UserAuthenticateLayout>
            <Head title="Profile" />

            <Box sx={{ 
                maxWidth: 800, 
                mx: 'auto', 
                p: 3,
                minHeight: 'calc(100vh - 80px)'
            }}>
                <Typography variant="h4" sx={{ color: 'white', fontWeight: 600, mb: 4 }}>
                    {t('profile.title')}
                </Typography>

                {/* Profile Information */}
                <Paper sx={{ 
                    bgcolor: '#1A1A1A', 
                    borderRadius: 2, 
                    p: 3, 
                    mb: 3,
                    border: '1px solid #2A2A2A'
                }}>
                    <Typography variant="h6" sx={{ color: 'white', fontWeight: 600, mb: 1 }}>
                        {t('profile.profileInfo.title')}
                    </Typography>
                    <Typography sx={{ color: '#9CA3AF', fontSize: '0.875rem', mb: 3 }}>
                        {t('profile.profileInfo.description')}
                    </Typography>

                    {showSuccess && (
                        <Alert severity="success" sx={{ mb: 2 }}>
                            {t('profile.profileInfo.successMessage')}
                        </Alert>
                    )}

                    <form onSubmit={submitProfile}>
                        <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2.5 }}>
                            <TextField
                                label={t('profile.profileInfo.nameLabel')}
                                value={profileData.name}
                                onChange={(e) => setProfileData('name', e.target.value)}
                                error={!!profileErrors.name}
                                helperText={profileErrors.name}
                                fullWidth
                                sx={{
                                    '& .MuiInputLabel-root': { color: '#9CA3AF' },
                                    '& .MuiInputBase-input': { color: 'white' },
                                    '& .MuiOutlinedInput-root': {
                                        '& fieldset': { borderColor: '#4A4A4A' },
                                        '&:hover fieldset': { borderColor: '#6A6A6A' },
                                        '&.Mui-focused fieldset': { borderColor: '#E11D48' },
                                    },
                                }}
                            />
                            <TextField
                                label={t('profile.profileInfo.emailLabel')}
                                type="email"
                                value={profileData.email}
                                onChange={(e) => setProfileData('email', e.target.value)}
                                error={!!profileErrors.email}
                                helperText={profileErrors.email}
                                fullWidth
                                sx={{
                                    '& .MuiInputLabel-root': { color: '#9CA3AF' },
                                    '& .MuiInputBase-input': { color: 'white' },
                                    '& .MuiOutlinedInput-root': {
                                        '& fieldset': { borderColor: '#4A4A4A' },
                                        '&:hover fieldset': { borderColor: '#6A6A6A' },
                                        '&.Mui-focused fieldset': { borderColor: '#E11D48' },
                                    },
                                }}
                            />
                            <Box sx={{ display: 'flex', justifyContent: 'flex-end' }}>
                                <Button
                                    type="submit"
                                    variant="contained"
                                    disabled={profileProcessing}
                                    sx={{
                                        bgcolor: '#E11D48',
                                        '&:hover': { bgcolor: '#BE123C' },
                                        textTransform: 'none',
                                        px: 4,
                                    }}
                                >
                                    {profileProcessing ? <CircularProgress size={20} color="inherit" /> : t('profile.profileInfo.saveButton')}
                                </Button>
                            </Box>
                        </Box>
                    </form>
                </Paper>

                {/* Update Password */}
                <Paper sx={{ 
                    bgcolor: '#1A1A1A', 
                    borderRadius: 2, 
                    p: 3,
                    border: '1px solid #2A2A2A'
                }}>
                    <Typography variant="h6" sx={{ color: 'white', fontWeight: 600, mb: 1 }}>
                        {t('profile.password.title')}
                    </Typography>
                    <Typography sx={{ color: '#9CA3AF', fontSize: '0.875rem', mb: 3 }}>
                        {t('profile.password.description')}
                    </Typography>

                    {showPasswordSuccess && (
                        <Alert severity="success" sx={{ mb: 2 }}>
                            {t('profile.password.successMessage')}
                        </Alert>
                    )}

                    <form onSubmit={submitPassword}>
                        <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2.5 }}>
                            <TextField
                                label={t('profile.password.currentPasswordLabel')}
                                type="password"
                                value={passwordData.current_password}
                                onChange={(e) => setPasswordData('current_password', e.target.value)}
                                error={!!passwordErrors.current_password}
                                helperText={passwordErrors.current_password}
                                fullWidth
                                sx={{
                                    '& .MuiInputLabel-root': { color: '#9CA3AF' },
                                    '& .MuiInputBase-input': { color: 'white' },
                                    '& .MuiOutlinedInput-root': {
                                        '& fieldset': { borderColor: '#4A4A4A' },
                                        '&:hover fieldset': { borderColor: '#6A6A6A' },
                                        '&.Mui-focused fieldset': { borderColor: '#E11D48' },
                                    },
                                }}
                            />
                            <TextField
                                label={t('profile.password.newPasswordLabel')}
                                type="password"
                                value={passwordData.password}
                                onChange={(e) => setPasswordData('password', e.target.value)}
                                error={!!passwordErrors.password}
                                helperText={passwordErrors.password}
                                fullWidth
                                sx={{
                                    '& .MuiInputLabel-root': { color: '#9CA3AF' },
                                    '& .MuiInputBase-input': { color: 'white' },
                                    '& .MuiOutlinedInput-root': {
                                        '& fieldset': { borderColor: '#4A4A4A' },
                                        '&:hover fieldset': { borderColor: '#6A6A6A' },
                                        '&.Mui-focused fieldset': { borderColor: '#E11D48' },
                                    },
                                }}
                            />
                            <TextField
                                label={t('profile.password.confirmPasswordLabel')}
                                type="password"
                                value={passwordData.password_confirmation}
                                onChange={(e) => setPasswordData('password_confirmation', e.target.value)}
                                error={!!passwordErrors.password_confirmation}
                                helperText={passwordErrors.password_confirmation}
                                fullWidth
                                sx={{
                                    '& .MuiInputLabel-root': { color: '#9CA3AF' },
                                    '& .MuiInputBase-input': { color: 'white' },
                                    '& .MuiOutlinedInput-root': {
                                        '& fieldset': { borderColor: '#4A4A4A' },
                                        '&:hover fieldset': { borderColor: '#6A6A6A' },
                                        '&.Mui-focused fieldset': { borderColor: '#E11D48' },
                                    },
                                }}
                            />
                            <Box sx={{ display: 'flex', justifyContent: 'flex-end' }}>
                                <Button
                                    type="submit"
                                    variant="contained"
                                    disabled={passwordProcessing}
                                    sx={{
                                        bgcolor: '#E11D48',
                                        '&:hover': { bgcolor: '#BE123C' },
                                        textTransform: 'none',
                                        px: 4,
                                    }}
                                >
                                    {passwordProcessing ? <CircularProgress size={20} color="inherit" /> : t('profile.password.updateButton')}
                                </Button>
                            </Box>
                        </Box>
                    </form>
                </Paper>
            </Box>
        </UserAuthenticateLayout>
    );
}
