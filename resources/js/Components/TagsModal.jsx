import React, { useState, useEffect } from 'react';
import {
    Dialog,
    DialogTitle,
    DialogContent,
    DialogActions,
    Button,
    Box,
    Typography,
    Chip,
    TextField,
    CircularProgress,
    IconButton
} from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';
import axios from 'axios';
import Swal from 'sweetalert2';

export default function TagsModal({ open, onClose, video, onTagsSaved }) {
    const [tags, setTags] = useState([]);
    const [inputValue, setInputValue] = useState('');
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (open && video) {
            fetchTags();
        } else {
            setTags([]);
            setInputValue('');
            setError(null);
        }
    }, [open, video]);

    const fetchTags = async () => {
        setLoading(true);
        setError(null);
        try {
            const response = await axios.get(route('admin.video.approval.tags.get', video.id));
            setTags(response.data.tags || []);
        } catch (err) {
            console.error('Error fetching tags:', err);
            setError('Failed to load existing tags.');
        } finally {
            setLoading(false);
        }
    };

    const handleAddTag = () => {
        const newTag = inputValue.trim().toLowerCase();
        
        if (!newTag) return;
        
        if (newTag.length > 50) {
            setError('Tag must be 50 characters or less.');
            return;
        }

        if (tags.some(t => t.toLowerCase() === newTag)) {
            setError('This tag already exists.');
            return;
        }

        setTags([...tags, inputValue.trim()]);
        setInputValue('');
        setError(null);
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleAddTag();
        }
    };

    const handleDeleteTag = (tagToDelete) => {
        setTags(tags.filter((tag) => tag !== tagToDelete));
    };

    const handleSave = async () => {
        setSaving(true);
        setError(null);
        try {
            const response = await axios.post(route('admin.video.approval.tags.save', video.id), {
                tags: tags
            });
            
            Swal.fire({
                title: 'Success',
                text: response.data.message,
                icon: 'success',
                timer: 2000,
                showConfirmButton: false,
                background: '#191919',
                color: '#fff'
            });
            
            if (onTagsSaved) {
                onTagsSaved(video.id, response.data.tags);
            }
            onClose();
        } catch (err) {
            console.error('Error saving tags:', err);
            setError(err.response?.data?.message || 'Failed to save tags.');
            Swal.fire({
                title: 'Error',
                text: 'Failed to save tags.',
                icon: 'error',
                background: '#191919',
                color: '#fff'
            });
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog 
            open={open} 
            onClose={!saving ? onClose : null}
            maxWidth="sm"
            fullWidth
            PaperProps={{
                sx: {
                    bgcolor: '#191919',
                    color: 'white',
                    borderRadius: 2
                }
            }}
        >
            <DialogTitle sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                Manage Search Tags
                <IconButton onClick={onClose} disabled={saving} sx={{ color: '#9CA3AF' }}>
                    <CloseIcon />
                </IconButton>
            </DialogTitle>

            <DialogContent dividers sx={{ borderColor: '#3C3C3C' }}>
                {video && (
                    <Box sx={{ 
                        mb: 3, 
                        p: 2, 
                        bgcolor: 'rgba(255, 255, 255, 0.03)', 
                        borderRadius: 1,
                        borderLeft: '4px solid #EE1D52'
                    }}>
                        <Typography variant="caption" sx={{ color: '#9CA3AF', textTransform: 'uppercase', letterSpacing: 1 }}>
                            Video Title
                        </Typography>
                        <Typography sx={{ fontWeight: 600, fontSize: '1.1rem', mt: 0.5, wordBreak: 'break-word' }}>
                            {video.title || video.filename || 'Untitled Video'}
                        </Typography>
                    </Box>
                )}

                {loading ? (
                    <Box sx={{ display: 'flex', justifyContent: 'center', p: 3 }}>
                        <CircularProgress />
                    </Box>
                ) : (
                    <>
                        <Box sx={{ mb: 2 }}>
                            <TextField
                                fullWidth
                                variant="outlined"
                                placeholder="Type a tag and press Enter"
                                value={inputValue}
                                onChange={(e) => setInputValue(e.target.value)}
                                onKeyDown={handleKeyDown}
                                error={!!error}
                                helperText={error}
                                disabled={saving}
                                InputProps={{
                                    sx: {
                                        color: 'white',
                                        '& fieldset': { borderColor: '#3C3C3C' },
                                        '&:hover fieldset': { borderColor: '#5C5C5C' },
                                        '&.Mui-focused fieldset': { borderColor: '#EE1D52' },
                                    }
                                }}
                                FormHelperTextProps={{
                                    sx: { color: '#EF4444' }
                                }}
                            />
                            <Button 
                                variant="outlined" 
                                size="small" 
                                onClick={handleAddTag}
                                sx={{ mt: 1, color: '#EE1D52', borderColor: '#EE1D52', '&:hover': { borderColor: '#EE1D52', bgcolor: 'rgba(238, 29, 82, 0.1)' } }}
                                disabled={saving || !inputValue.trim()}
                            >
                                Add Tag
                            </Button>
                        </Box>

                        <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1, minHeight: '60px', p: 1, border: '1px dashed #3C3C3C', borderRadius: 1 }}>
                            {tags.length === 0 ? (
                                <Typography sx={{ color: '#9CA3AF', fontSize: '14px', width: '100%', textAlign: 'center', mt: 1 }}>
                                    No tags added yet.
                                </Typography>
                            ) : (
                                tags.map((tag, index) => (
                                    <Chip
                                        key={index}
                                        label={tag}
                                        onDelete={!saving ? () => handleDeleteTag(tag) : undefined}
                                        sx={{
                                            bgcolor: '#222222',
                                            color: 'white',
                                            border: '1px solid #3C3C3C',
                                            '& .MuiChip-deleteIcon': {
                                                color: '#9CA3AF',
                                                '&:hover': { color: '#EF4444' }
                                            }
                                        }}
                                    />
                                ))
                            )}
                        </Box>
                    </>
                )}
            </DialogContent>

            <DialogActions sx={{ p: 2 }}>
                <Button 
                    onClick={onClose} 
                    disabled={saving}
                    sx={{ color: '#9CA3AF' }}
                >
                    Cancel
                </Button>
                <Button 
                    onClick={handleSave} 
                    disabled={saving || loading}
                    variant="contained"
                    sx={{ 
                        bgcolor: '#EE1D52', 
                        '&:hover': { bgcolor: '#D01946' }
                    }}
                >
                    {saving ? <CircularProgress size={24} color="inherit" /> : 'Save Tags'}
                </Button>
            </DialogActions>
        </Dialog>
    );
}
