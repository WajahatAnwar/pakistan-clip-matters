import React from 'react';
import { Box, CircularProgress, Typography } from '@mui/material';
import VideoLibraryOutlinedIcon from '@mui/icons-material/VideoLibraryOutlined';

export default function LoadingOverlay({ message = 'Loading...' }) {
    return (
        <Box
            sx={{
                position: 'absolute',
                top: 0,
                left: 0,
                right: 0,
                bottom: 0,
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                backgroundColor: 'rgba(31, 35, 36, 0.9)',
                zIndex: 10,
                gap: 2
            }}
        >
            {/* Animated loader with brand color */}
            <Box
                sx={{
                    position: 'relative',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center'
                }}
            >
                {/* Outer ring */}
                <CircularProgress
                    size={60}
                    thickness={3}
                    sx={{
                        color: '#EE1D52',
                        animationDuration: '1.5s'
                    }}
                />
                {/* Inner static ring */}
                <CircularProgress
                    variant="determinate"
                    value={100}
                    size={44}
                    thickness={3}
                    sx={{
                        color: 'rgba(238, 29, 82, 0.2)',
                        position: 'absolute'
                    }}
                />
            </Box>
            
            {/* Loading text */}
            <Typography
                sx={{
                    color: '#9CA3AF',
                    fontSize: '14px',
                    fontWeight: 500,
                    letterSpacing: '0.5px'
                }}
            >
                {message}
            </Typography>
        </Box>
    );
}

/**
 * Minimal spinner for inline use
 */
export function Spinner({ size = 24, color = '#EE1D52' }) {
    return (
        <CircularProgress
            size={size}
            thickness={4}
            sx={{
                color: color,
                animationDuration: '1s'
            }}
        />
    );
}

/**
 * DataGrid compatible loading overlay
 * For use with DataGrid slots.loadingOverlay
 */
export function DataGridLoadingOverlay({ message = 'Loading videos...' }) {
    return (
        <Box
            sx={{
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                height: '100%',
                width: '100%',
                backgroundColor: 'rgba(31, 35, 36, 0.95)',
                gap: 2
            }}
        >
            {/* Pulsing dots animation */}
            <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
                {[0, 1, 2].map((i) => (
                    <Box
                        key={i}
                        sx={{
                            width: 12,
                            height: 12,
                            borderRadius: '50%',
                            backgroundColor: '#EE1D52',
                            animation: 'pulse 1.4s ease-in-out infinite',
                            animationDelay: `${i * 0.2}s`,
                            '@keyframes pulse': {
                                '0%, 80%, 100%': {
                                    transform: 'scale(0.6)',
                                    opacity: 0.5
                                },
                                '40%': {
                                    transform: 'scale(1)',
                                    opacity: 1
                                }
                            }
                        }}
                    />
                ))}
            </Box>
            
            <Typography
                sx={{
                    color: '#9CA3AF',
                    fontSize: '14px',
                    fontWeight: 500,
                    mt: 1
                }}
            >
                {message}
            </Typography>
        </Box>
    );
}

/**
 * DataGrid compatible no rows overlay
 * For use with DataGrid slots.noRowsOverlay
 */
export function DataGridNoRowsOverlay({ message = 'No videos found', subtitle = 'Videos will appear here once synced from Dropbox' }) {
    return (
        <Box
            sx={{
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                height: '100%',
                width: '100%',
                backgroundColor: 'transparent',
                gap: 2,
                py: 8
            }}
        >
            <VideoLibraryOutlinedIcon 
                sx={{ 
                    fontSize: 64, 
                    color: '#4A4A4A',
                    mb: 1
                }} 
            />
            <Typography
                sx={{
                    color: '#9CA3AF',
                    fontSize: '16px',
                    fontWeight: 500
                }}
            >
                {message}
            </Typography>
            <Typography
                sx={{
                    color: '#6B7280',
                    fontSize: '14px',
                    maxWidth: 300,
                    textAlign: 'center'
                }}
            >
                {subtitle}
            </Typography>
        </Box>
    );
}
