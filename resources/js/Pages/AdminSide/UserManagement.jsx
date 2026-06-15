import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import React, { useState, useEffect, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { usePage } from '@inertiajs/react';
import {
    Box,
    Typography,
    Button,
    Switch,
    Dialog,
    DialogTitle,
    DialogContent,
    TextField,
    MenuItem,
    IconButton,
    InputAdornment,
    Tooltip,
} from '@mui/material';
import { DataGrid } from '@mui/x-data-grid';
import EditOutlinedIcon from '@mui/icons-material/EditOutlined';
import DeleteOutlineOutlinedIcon from '@mui/icons-material/DeleteOutlineOutlined';
import CloseIcon from '@mui/icons-material/Close';
import VisibilityOutlinedIcon from '@mui/icons-material/VisibilityOutlined';
import VisibilityOffOutlinedIcon from '@mui/icons-material/VisibilityOffOutlined';
import Swal from 'sweetalert2';
export default function UserManagement({ availableRoles = [] }) {
    const { t, i18n } = useTranslation();
    const isRtl = i18n.language === 'ur';
    const { auth } = usePage().props;
    const currentUserRole = auth?.user?.role || 'user';

    // Build role options from server-provided availableRoles
    const roleLabels = {
        superAdmin: t('userManagement.form.superAdmin') || 'Super Admin',
        admin: t('userManagement.form.admin') || 'Admin',
        manager: t('userManagement.form.manager') || 'Manager',
        user: t('userManagement.form.user') || 'User',
    };

    const availableRoleOptions = useMemo(() => {
        return availableRoles.map((role) => ({
            value: role,
            label: roleLabels[role] || role,
        }));
    }, [availableRoles, t]);

    // Define available filter roles based on current user's role
    const availableFilterRoles = useMemo(() => {
        const normalizedRole = currentUserRole.toLowerCase().replace(/[_ ]/g, '');

        if (normalizedRole === 'superadmin') {
            // Super Admin can filter: admin, manager, user
            return [
                { value: 'all', label: t('userManagement.allRoles') },
                { value: 'admin', label: roleLabels.admin },
                { value: 'manager', label: roleLabels.manager },
                { value: 'user', label: roleLabels.user }
            ];
        } else if (normalizedRole === 'admin') {
            // Admin can filter: manager, user
            return [
                { value: 'all', label: t('userManagement.allRoles') },
                { value: 'manager', label: roleLabels.manager },
                { value: 'user', label: roleLabels.user }
            ];
        } else if (normalizedRole === 'manager') {
            // Manager can only filter: user
            return [
                { value: 'all', label: t('userManagement.allRoles') },
                { value: 'user', label: roleLabels.user }
            ];
        }

        // Default: no filter options
        return [{ value: 'all', label: t('userManagement.allRoles') }];
    }, [currentUserRole, roleLabels]);

    const [selectedRows, setSelectedRows] = useState([]);
    const [openModal, setOpenModal] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const [roleFilter, setRoleFilter] = useState('all'); // 'all' or specific role
    const [formData, setFormData] = useState({
        fullName: '',
        email: '',
        phone: '',
        userType: '',
        password: '',
        confirmPassword: ''
    });
    const [selectedImage, setSelectedImage] = useState(null);
    const [imagePreview, setImagePreview] = useState(null);
    const [isEditMode, setIsEditMode] = useState(false);
    const [selectedUserId, setSelectedUserId] = useState(null);
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);

    // Filter rows based on search query and role filter
    const filteredRows = rows.filter(row => {
        // Search filter
        const matchesSearch = !searchQuery.trim() ||
            (row.userName && row.userName.toLowerCase().includes(searchQuery.toLowerCase())) ||
            (row.email && row.email.toLowerCase().includes(searchQuery.toLowerCase()));

        // Role filter
        const normalizedRowRole = row.userType?.toLowerCase().replace(/[_ ]/g, '');
        const matchesRole = roleFilter === 'all' || normalizedRowRole === roleFilter;

        return matchesSearch && matchesRole;
    });
    const pageSize = 5;
    const hasMultiplePages = filteredRows.length > pageSize;

    useEffect(() => {
        fetchUsers();
    }, []);

    const fetchUsers = async () => {
        setLoading(true);
        try {
            const response = await fetch(route('admin.users.list'), {
                headers: { 'Accept': 'application/json' }
            });
            if (response.ok) {
                const data = await response.json();
                setRows(data);
            } else {
                console.error("Failed to fetch users");
            }
        } catch (err) {
            console.error("Fetch error:", err);
        } finally {
            setLoading(false);
        }
    };

    const handleModalOpen = () => {
        setIsEditMode(false);
        setSelectedUserId(null);
        setFormData({
            fullName: '',
            email: '',
            phone: '',
            userType: '',
            password: '',
            confirmPassword: ''
        });
        setSelectedImage(null);
        setImagePreview(null);
        setOpenModal(true);
    };

    const handleModalClose = () => {
        setOpenModal(false);
        setIsEditMode(false);
        setSelectedUserId(null);
        setFormData({
            fullName: '',
            email: '',
            phone: '',
            userType: '',
            password: '',
            confirmPassword: ''
        });
        setSelectedImage(null);
        setImagePreview(null);
    };

    const handleClickShowPassword = () => setShowPassword(!showPassword);

    const handleInputChange = (e) => {
        const { name, value } = e.target;
        setFormData(prev => ({
            ...prev,
            [name]: value
        }));
    };

    const handleImageChange = (e) => {
        const file = e.target.files[0];
        if (file) {
            setSelectedImage(file);
            const reader = new FileReader();
            reader.onloadend = () => {
                setImagePreview(reader.result);
            };
            reader.readAsDataURL(file);
        }
    };

    const handleStatusChange = async (id, currentStatus) => {
        // Optimistic update - update UI immediately
        setRows(prevRows =>
            prevRows.map(row =>
                row.id === id ? { ...row, status: !currentStatus } : row
            )
        );

        try {
            const response = await fetch(route('admin.users.update', id), {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ status: !currentStatus })
            });

            if (response.ok) {
                Swal.fire({
                    icon: 'success',
                    title: t('userManagement.alerts.statusUpdated'),
                    timer: 1200,
                    showConfirmButton: false,
                });
            } else {
                // Revert on error
                setRows(prevRows =>
                    prevRows.map(row =>
                        row.id === id ? { ...row, status: currentStatus } : row
                    )
                );
                Swal.fire(t('common.error'), t('userManagement.alerts.statusUpdateFailed'), 'error');
            }
        } catch (err) {
            // Revert on error
            setRows(prevRows =>
                prevRows.map(row =>
                    row.id === id ? { ...row, status: currentStatus } : row
                )
            );
            Swal.fire(t('common.error'), t('userManagement.alerts.somethingWentWrong'), 'error');
        }
    };

    const handleCanEditProfileChange = async (id, currentValue) => {
        // Optimistic update - update UI immediately
        setRows(prevRows =>
            prevRows.map(row =>
                row.id === id ? { ...row, can_edit_profile: !currentValue } : row
            )
        );

        try {
            const response = await fetch(route('admin.users.update', id), {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ can_edit_profile: !currentValue })
            });

            if (response.ok) {
                Swal.fire({
                    icon: 'success',
                    title: t('userManagement.alerts.profilePermissionUpdated'),
                    timer: 1200,
                    showConfirmButton: false,
                });
            } else {
                // Revert on error
                setRows(prevRows =>
                    prevRows.map(row =>
                        row.id === id ? { ...row, can_edit_profile: currentValue } : row
                    )
                );
                Swal.fire(t('common.error'), t('userManagement.alerts.profilePermissionFailed'), 'error');
            }
        } catch (err) {
            // Revert on error
            setRows(prevRows =>
                prevRows.map(row =>
                    row.id === id ? { ...row, can_edit_profile: currentValue } : row
                )
            );
            Swal.fire(t('common.error'), t('userManagement.alerts.somethingWentWrong'), 'error');
        }
    };

    const handleSubmit = async () => {
        if (!formData.fullName || !formData.email || !formData.userType) {
            return Swal.fire(t('common.warning'), t('userManagement.alerts.fillRequiredFields'), 'warning');
        }

        if (!isEditMode && (!formData.password || !formData.confirmPassword)) {
            return Swal.fire(t('common.warning'), t('userManagement.alerts.passwordRequired'), 'warning');
        }

        if (formData.password && formData.password !== formData.confirmPassword) {
            return Swal.fire(t('common.error'), t('userManagement.alerts.passwordsNotMatch'), 'error');
        }

        try {
            const payload = new FormData();
            payload.append('fullName', formData.fullName);
            payload.append('email', formData.email);
            payload.append('phone', formData.phone || '');
            payload.append('userType', formData.userType);

            if (formData.password) {
                payload.append('password', formData.password);
                payload.append('password_confirmation', formData.confirmPassword);
            }

            if (selectedImage) {
                payload.append('profile_picture', selectedImage);
            }

            let response;
            if (isEditMode) {
                payload.append('_method', 'PUT');
                response = await fetch(route('admin.users.update', selectedUserId), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: payload,
                });
            } else {
                response = await fetch(route('admin.users.store'), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: payload,
                });
            }

            const result = await response.json();
            console.log('Result:', result);

            if (response.ok) {
                Swal.fire({
                    icon: 'success',
                    title: isEditMode ? t('userManagement.alerts.userUpdatedSuccess') : t('userManagement.alerts.userAddedSuccess'),
                    showConfirmButton: false,
                    timer: 1200
                });
                await fetchUsers();
                handleModalClose();
            } else {
                Swal.fire(t('common.error'), result.message || t('userManagement.alerts.userSaveFailed'), 'error');
            }
        } catch (err) {
            Swal.fire(t('common.error'), t('userManagement.alerts.somethingWentWrong'), 'error');
        }
    };

    const handleDelete = async (id) => {
        const result = await Swal.fire({
            title: t('userManagement.alerts.deleteConfirmTitle'),
            text: t('userManagement.alerts.deleteConfirmText'),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e3342f',
            cancelButtonColor: '#6c757d',
            confirmButtonText: t('userManagement.alerts.deleteConfirmButton')
        });

        if (!result.isConfirmed) return;

        try {
            const response = await fetch(route('admin.users.delete', id), {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });

            if (response.ok) {
                Swal.fire({
                    icon: 'success',
                    title: t('userManagement.alerts.userDeletedSuccess'),
                    showConfirmButton: false,
                    timer: 1200
                });
                fetchUsers();
            } else {
                Swal.fire(t('common.error'), t('userManagement.alerts.userDeleteFailed'), 'error');
            }
        } catch (err) {
            Swal.fire(t('common.error'), t('userManagement.alerts.somethingWentWrong'), 'error');
        }
    };

    const handleEdit = (row) => {
        setFormData({
            fullName: row.userName,
            email: row.email,
            phone: row.phone || '',
            userType: row.userType.toLowerCase().replace(' ', '_'),
            password: '',
            confirmPassword: ''
        });

        setSelectedUserId(row.id);
        setImagePreview(row.profile_picture || null);
        setSelectedImage(null);
        setIsEditMode(true);
        setOpenModal(true);
    };

    const columns = [
        {
            field: 'userName',
            headerName: t('userManagement.table.userName'),
            flex: 1,
            renderHeader: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', height: '100%' }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: '14px', fontWeight: 500 }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => (
                <Typography sx={{ color: 'white', fontSize: '14px' }}>
                    {params.value}
                </Typography>
            )
        },
        {
            field: 'status',
            headerName: t('userManagement.table.status'),
            flex: 1,
            renderHeader: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', height: '100%' }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: '14px', fontWeight: 500 }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => {
                const role = params.row.userType?.toLowerCase();
                const normalizedCurrentRole = currentUserRole.toLowerCase().replace(/[_ ]/g, '');
                const isSuperAdmin = normalizedCurrentRole === 'superadmin';

                // Super admins always show "Always Active"
                if (role === 'superadmin') {
                    return (
                        <Typography sx={{ color: '#22C55E', fontSize: '14px', fontWeight: 500 }}>
                            Always Active
                        </Typography>
                    );
                }

                // If current user is super admin, show toggles for admin and manager
                // Otherwise, admin and manager show "Always Active"
                if (['admin', 'manager'].includes(role) && !isSuperAdmin) {
                    return (
                        <Typography sx={{ color: '#22C55E', fontSize: '14px', fontWeight: 500 }}>
                            Always Active
                        </Typography>
                    );
                }

                return (
                    <Switch
                        checked={params.value}
                        onChange={() => handleStatusChange(params.row.id, params.value)}
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
                                        backgroundColor: '#FF2B2B',
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
                );
            }
        },
        {
            field: 'can_edit_profile',
            headerName: t('userManagement.table.canEditProfile') || 'Edit Profile',
            flex: 1,
            renderHeader: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', height: '100%' }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: '14px', fontWeight: 500 }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => {
                const role = params.row.userType?.toLowerCase();
                const normalizedCurrentRole = currentUserRole.toLowerCase().replace(/[_ ]/g, '');
                const isSuperAdmin = normalizedCurrentRole === 'superadmin';

                // Super admins always show "Always Enabled"
                if (role === 'superadmin') {
                    return (
                        <Typography sx={{ color: '#22C55E', fontSize: '14px', fontWeight: 500 }}>
                            Always Enabled
                        </Typography>
                    );
                }

                // If current user is super admin, show toggles for admin and manager
                // Otherwise, admin and manager show "Always Enabled"
                if (['admin', 'manager'].includes(role) && !isSuperAdmin) {
                    return (
                        <Typography sx={{ color: '#22C55E', fontSize: '14px', fontWeight: 500 }}>
                            Always Enabled
                        </Typography>
                    );
                }

                return (
                    <Switch
                        checked={params.value}
                        onChange={() => handleCanEditProfileChange(params.row.id, params.value)}
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
                                        backgroundColor: '#22C55E',
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
                );
            }
        },
        {
            field: 'userType',
            headerName: t('userManagement.table.userType'),
            flex: 1,
            renderHeader: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', height: '100%' }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: '14px', fontWeight: 500 }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => (
                <Typography sx={{ color: 'white', fontSize: '14px', textTransform: 'capitalize' }}>
                    {params.value}
                </Typography>
            )
        },
        {
            field: 'email',
            headerName: t('userManagement.table.email'),
            flex: 1,
            minWidth: 300,
            renderHeader: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', height: '100%'}}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: '14px', fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', width: '100%' }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => (
                <Tooltip title={params.value || ''} placement="top" arrow>
                    <Typography sx={{ color: 'white', fontSize: '14px' }}>
                        {params.value}
                    </Typography>
                </Tooltip>
            )
        },
        {
            field: 'phone',
            headerName: t('userManagement.table.phoneNumber'),
            flex: 1,
            renderHeader: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', height: '100%' }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: '14px', fontWeight: 500 }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => (
                <Tooltip title={params.value || ''} placement="top" arrow>
                    <Typography sx={{ color: 'white', fontSize: '14px' }}>
                        {params.value}
                    </Typography>
                </Tooltip>
            )
        },
        {
            field: 'action',
            headerName: t('userManagement.table.action'),
            flex: 1,
            renderHeader: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', height: '100%' }}>
                    <Typography sx={{ color: '#9CA3AF', fontSize: '14px', fontWeight: 500 }}>
                        {params.colDef.headerName}
                    </Typography>
                </Box>
            ),
            renderCell: (params) => (
                <Box sx={{ display: 'flex', gap: 1 }}>
                    <Tooltip title={t('userManagement.editUser')} placement="top" arrow>
                        <EditOutlinedIcon
                            title={t('userManagement.editUser')}
                            sx={{
                                color: '#28933F',
                                cursor: 'pointer',
                                '&:hover': { opacity: 0.8 }
                            }}
                            onClick={() => handleEdit(params.row)}
                        />
                    </Tooltip>
                    <Tooltip title={t('userManagement.deleteUser')} placement="top" arrow>
                        <DeleteOutlineOutlinedIcon
                            title={t('userManagement.deleteUser')}
                            sx={{
                                color: '#EE1D52',
                                cursor: 'pointer',
                                '&:hover': { opacity: 0.8 }
                            }}
                            onClick={() => handleDelete(params.row.id)}
                        />
                    </Tooltip>
                </Box>
            )
        }
    ];

    return (
        <AuthenticatedLayout
            header={<h2 className="font-semibold text-2xl text-white leading-tight">{t('userManagement.title')}</h2>}
        >
            <Box sx={{ width: '100%', height: '100%', bgcolor: '#191919', borderRadius: 2, p: 2 }}>
                <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2, mb: 2 }}>
                    <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <Typography sx={{ color: 'white', fontSize: '18px', fontWeight: 600 }}>
                            {t('userManagement.allUsers')} ({filteredRows.length} {t('userManagement.usersCount')})
                        </Typography>
                        <Button
                            variant="contained"
                            onClick={handleModalOpen}
                            sx={{
                                backgroundColor: '#28933F',
                                color: 'white',
                                textTransform: 'none',
                                fontSize: '14px',
                                '&:hover': {
                                    backgroundColor: '#217a34'
                                }
                            }}
                        >
                            {t('userManagement.addNewUser')}
                        </Button>
                    </Box>
                    <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap' }}>
                        <input
                            type="text"
                            placeholder={t('userManagement.searchPlaceholder')}
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
                        <TextField
                            select
                            value={roleFilter}
                            onChange={(e) => setRoleFilter(e.target.value)}
                            size="small"
                            SelectProps={{
                                MenuProps: {
                                    PaperProps: {
                                        sx: {
                                            backgroundColor: '#222222',
                                            color: 'white',
                                        }
                                    }
                                }
                            }}
                            sx={{
                                minWidth: '200px',
                                maxWidth: '300px',
                                '& .MuiOutlinedInput-root': {
                                    backgroundColor: '#222222',
                                    borderRadius: '8px',
                                    border: '1px solid #3C3C3C',
                                    '& fieldset': { border: 'none' },
                                    '&:hover fieldset': { border: 'none' },
                                    '&.Mui-focused fieldset': { border: 'none' },
                                },
                                '& .MuiSelect-select': {
                                    color: 'white',
                                    fontSize: '14px',
                                    padding: '8px 12px'
                                },
                                '& .MuiSelect-icon': {
                                    color: 'white'
                                }
                            }}
                        >
                            {availableFilterRoles.map((role) => (
                                <MenuItem key={role.value} value={role.value}>
                                    {role.label}
                                </MenuItem>
                            ))}
                        </TextField>
                    </Box>
                </Box>

                <Dialog
                        open={openModal}
                        onClose={handleModalClose}
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
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                            color: 'white',
                            padding: '20px'
                        }}>
                            <Typography variant="h6" component="div" sx={{ fontSize: '18px', fontWeight: 600 }}>
                                {isEditMode ? t('userManagement.editUser') : t('userManagement.addNewUser')}
                            </Typography>
                            <IconButton onClick={handleModalClose} sx={{ color: '#6B7280' }}>
                                <CloseIcon />
                            </IconButton>
                        </DialogTitle>
                        <DialogContent sx={{ padding: '20px', paddingX: 4 }}>
                            <Box sx={{ mb: 3 }}>
                                <Typography sx={{ color: 'white', mb: 1, fontSize: '14px' }}>
                                    {t('userManagement.form.basicInformation')}
                                </Typography>
                                <TextField
                                    fullWidth
                                    name="fullName"
                                    placeholder={t('userManagement.form.fullName')}
                                    value={formData.fullName}
                                    onChange={handleInputChange}
                                    sx={{
                                        '& .MuiOutlinedInput-root': {
                                            backgroundColor: '#222222',
                                            borderRadius: '50px',
                                            '& fieldset': { border: 'none' },
                                            '&:hover fieldset': { border: 'none' },
                                            '&.Mui-focused fieldset': { border: 'none' },
                                        },
                                        '& .MuiOutlinedInput-input': {
                                            color: 'white',
                                        },
                                        mb: 2
                                    }}
                                />
                                <TextField
                                    fullWidth
                                    name="email"
                                    placeholder={t('userManagement.form.emailAddress')}
                                    value={formData.email}
                                    onChange={handleInputChange}
                                    sx={{
                                        '& .MuiOutlinedInput-root': {
                                            backgroundColor: '#222222',
                                            borderRadius: '50px',
                                            '& fieldset': { border: 'none' },
                                            '&:hover fieldset': { border: 'none' },
                                            '&.Mui-focused fieldset': { border: 'none' },
                                        },
                                        '& .MuiOutlinedInput-input': {
                                            color: 'white',
                                        },
                                        mb: 2
                                    }}
                                />
                                <TextField
                                    fullWidth
                                    name="phone"
                                    placeholder={t('userManagement.form.phoneNumber')}
                                    value={formData.phone}
                                    onChange={(e) => {
                                        // Allow only numbers
                                        const numericValue = e.target.value.replace(/\D/g, '');

                                        // Limit to 11 digits (change if needed)
                                        if (numericValue.length <= 11) {
                                            setFormData({
                                                ...formData,
                                                phone: numericValue,
                                            });
                                        }
                                    }}
                                    inputProps={{
                                        maxLength: 15,
                                        inputMode: "numeric",   // mobile numeric keyboard
                                        pattern: "[0-9]*"
                                    }}
                                    sx={{
                                        '& .MuiOutlinedInput-root': {
                                            backgroundColor: '#222222',
                                            borderRadius: '50px',
                                            '& fieldset': { border: 'none' },
                                            '&:hover fieldset': { border: 'none' },
                                            '&.Mui-focused fieldset': { border: 'none' },
                                        },
                                        '& .MuiOutlinedInput-input': {
                                            color: 'white',
                                        }
                                    }}
                                />

                            </Box>

                            <Box sx={{ mb: 3 }}>
                                <Typography sx={{ color: 'white', mb: 1, fontSize: '14px' }}>
                                    {t('userManagement.form.userType')}
                                </Typography>
                                <TextField
                                    select
                                    fullWidth
                                    name="userType"
                                    value={formData.userType || ""}
                                    onChange={handleInputChange}
                                    SelectProps={{
                                        displayEmpty: true,
                                        MenuProps: {
                                            PaperProps: {
                                                sx: {
                                                    backgroundColor: '#222222',
                                                    color: 'white',
                                                }
                                            }
                                        }
                                    }}
                                    sx={{
                                        '& .MuiOutlinedInput-root': {
                                            backgroundColor: '#222222',
                                            borderRadius: '50px',
                                            '& fieldset': { border: 'none' },
                                            '&:hover fieldset': { border: 'none' },
                                            '&.Mui-focused fieldset': { border: 'none' },
                                        },
                                        '& .MuiSelect-select': {
                                            color: formData.userType ? 'white' : '#9CA3AF', // gray when placeholder
                                        },
                                        '& .MuiSelect-icon': {
                                            color: 'white'
                                        }
                                    }}
                                >
                                    <MenuItem value="" disabled>
                                    {t('userManagement.form.selectUserType')}
                                    </MenuItem>

                                {availableRoleOptions.map((opt) => (
                                    <MenuItem key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </MenuItem>
                                ))}
                                </TextField>

                            </Box>

                            <Box sx={{ mb: 3 }}>
                                <Typography sx={{ color: 'white', mb: 1, fontSize: '14px' }}>
                                    {t('userManagement.form.profilePicture')}
                                </Typography>
                                <Box
                                    sx={{
                                        border: '2px dashed #3C3C3C',
                                        borderRadius: 1,
                                        p: 1,
                                        textAlign: 'center',
                                        backgroundColor: '#222222',
                                        position: 'relative',
                                        minHeight: '250px',
                                        display: 'flex',
                                        flexDirection: 'column',
                                        alignItems: 'center',
                                        justifyContent: 'center'
                                    }}
                                >
                                    {imagePreview ? (
                                        <Box sx={{ position: 'relative', width: '100px', height: '100px' }}>
                                            <img
                                                src={imagePreview}
                                                alt="Preview"
                                                style={{
                                                    width: '100%',
                                                    height: '100%',
                                                    borderRadius: '50%',
                                                    objectFit: 'cover'
                                                }}
                                            />
                                            <IconButton
                                                sx={{
                                                    position: 'absolute',
                                                    top: -8,
                                                    right: -8,
                                                    backgroundColor: '#EE1D52',
                                                    width: '24px',
                                                    height: '24px',
                                                    '&:hover': {
                                                        backgroundColor: '#dc1847'
                                                    }
                                                }}
                                                onClick={() => {
                                                    setSelectedImage(null);
                                                    setImagePreview(null);
                                                }}
                                            >
                                                <CloseIcon sx={{ color: 'white', fontSize: '16px' }} />
                                            </IconButton>
                                            <Box sx={{ display: 'flex', justifyContent: 'center' }}>
                                                <Button
                                                    variant="contained"
                                                    component="label"
                                                    sx={{
                                                        marginTop: 2,
                                                        backgroundColor: '#28933F',
                                                        color: 'white',
                                                        textTransform: 'none',
                                                        fontSize: '14px',
                                                        minWidth: '200px',
                                                        '&:hover': {
                                                            backgroundColor: '#217a34'
                                                        }
                                                    }}
                                                >
                                                {t('userManagement.changePicture')}
                                                    <input
                                                        type="file"
                                                        hidden
                                                        accept="image/*"
                                                        onChange={handleImageChange}
                                                    />
                                                </Button>
                                            </Box>
                                        </Box>
                                    ) : (
                                        <>
                                            <Typography sx={{ color: '#6B7280', fontSize: '14px', mb: 2 }}>
                                                {t('userManagement.uploadProfilePicture')}
                                            </Typography>
                                            <Button
                                                variant="contained"
                                                component="label"
                                                sx={{
                                                    backgroundColor: '#28933F',
                                                    color: 'white',
                                                    textTransform: 'none',
                                                    fontSize: '14px',
                                                    '&:hover': {
                                                        backgroundColor: '#217a34'
                                                    }
                                                }}
                                            >
                                                {t('userManagement.upload')}
                                                <input
                                                    type="file"
                                                    hidden
                                                    accept="image/*"
                                                    onChange={handleImageChange}
                                                />
                                            </Button>
                                        </>
                                    )}
                                </Box>
                            </Box>

                            <Box>
                                <Typography sx={{ color: 'white', mb: 1, fontSize: '14px' }}>
                                {t('userManagement.loginSecurity')} {!isEditMode && <span style={{ color: '#EE1D52' }}>*</span>}
                                </Typography>
                                {isEditMode && (
                                    <Typography sx={{ color: '#6B7280', mb: 1, fontSize: '12px' }}>
                                    {t('userManagement.leaveBlankPassword')}
                                    </Typography>
                                )}
                                <Box sx={{ display: 'flex', flexDirection: { xs: 'column', sm: 'row' }, gap: 2 }}>
                                    <TextField
                                        fullWidth
                                    name="password"
                                        placeholder={t('userManagement.form.password')}
                                        type={showPassword ? 'text' : 'password'}
                                        value={formData.password}
                                        onChange={handleInputChange}
                                        InputProps={{
                                            endAdornment: (
                                                <InputAdornment position="end">
                                                    <IconButton
                                                        onClick={handleClickShowPassword}
                                                        edge="end"
                                                        sx={{ color: '#6B7280' }}
                                                    >
                                                        {showPassword ? <VisibilityOutlinedIcon /> : <VisibilityOffOutlinedIcon />}
                                                    </IconButton>
                                                </InputAdornment>
                                            ),
                                        }}
                                        sx={{
                                            '& .MuiOutlinedInput-root': {
                                                backgroundColor: '#222222',
                                                borderRadius: '50px',
                                                '& fieldset': { border: 'none' },
                                                '&:hover fieldset': { border: 'none' },
                                                '&.Mui-focused fieldset': { border: 'none' },
                                            },
                                            '& .MuiOutlinedInput-input': {
                                                color: 'white',
                                            }
                                        }}
                                    />

                                    <TextField
                                        fullWidth
                                        name="confirmPassword"
                                        placeholder={t('userManagement.form.confirmPassword')}
                                        type={showPassword ? 'text' : 'password'}
                                        value={formData.confirmPassword}
                                        onChange={handleInputChange}
                                        InputProps={{
                                            endAdornment: (
                                                <InputAdornment position="end">
                                                    <IconButton
                                                        onClick={handleClickShowPassword}
                                                        edge="end"
                                                        sx={{ color: '#6B7280' }}
                                                    >
                                                        {showPassword ? <VisibilityOutlinedIcon /> : <VisibilityOffOutlinedIcon />}
                                                    </IconButton>
                                                </InputAdornment>
                                            ),
                                        }}
                                        sx={{
                                            '& .MuiOutlinedInput-root': {
                                                backgroundColor: '#222222',
                                                borderRadius: '50px',
                                                '& fieldset': { border: 'none' },
                                                '&:hover fieldset': { border: 'none' },
                                                '&.Mui-focused fieldset': { border: 'none' },
                                            },
                                            '& .MuiOutlinedInput-input': {
                                                color: 'white',
                                            }
                                        }}
                                    />
                                </Box>
                            </Box>

                            <Box sx={{ mt: 3, display: 'flex', justifyContent: 'flex-end' }}>
                                <Button
                                    variant="contained"
                                    sx={{
                                        backgroundColor: '#28933F',
                                        color: 'white',
                                        textTransform: 'none',
                                        fontSize: '14px',
                                        '&:hover': {
                                            backgroundColor: '#217a34'
                                        }
                                    }}
                                    onClick={handleSubmit}
                                >
                                    {isEditMode ? t('userManagement.updateUser') : t('userManagement.addNewUser')}
                                </Button>
                            </Box>
                        </DialogContent>
                </Dialog>
                <Box sx={{
                    width: '100%',
                    overflowX: 'auto',
                    '&::-webkit-scrollbar': {
                        height: '8px'
                    },
                    '&::-webkit-scrollbar-track': {
                        backgroundColor: '#222222',
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
                        rows={filteredRows}
                        columns={columns}
                        pageSize={5}
                        rowsPerPageOptions={[5]}
                        loading={loading}
                        disableColumnMenu
                        disableColumnResize
                        disableRowSelectionOnClick
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
                            minWidth: { xs: '800px', sm: '100%' },
                            minHeight: '650px',
                        '& .MuiDataGrid-root': {
                            borderColor: '#2A303C'
                        },
                        '& .MuiDataGrid-cell': {
                            padding: '16px 8px',
                            color: 'white',
                            display: 'flex',
                            alignItems: 'center',
                            minHeight: '100px !important',
                            border: 'none',
                            '&:focus': {
                                outline: 'none'
                            },
                            '&:focus-within': {
                                outline: 'none'
                            }
                        },
                        '& .MuiDataGrid-columnHeader': {
                            backgroundColor: '#222222',
                            color: '#9CA3AF',
                            borderRadius: '15px',
                            border: "none !important",
                            '&:hover': {
                                backgroundColor: '#333333'
                            }
                        },
                        '& .MuiDataGrid-columnHeaders': {
                            backgroundColor: '#222222',
                            borderBottom: 'none',
                            minHeight: '56px !important',
                            maxHeight: '56px !important',
                            lineHeight: '56px !important',
                            borderRadius: '38px',
                            '& .MuiDataGrid-columnHeader': {
                                padding: '0 8px',
                                '&:focus': {
                                    outline: 'none'
                                },
                                '&:focus-within': {
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
                            '& .MuiDataGrid-filler': {
                                '--rowBorderColor': 'transparent !important'
                            },
                            '& .MuiDataGrid-row': {
                                minHeight: '100px !important',
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
                            }
                        }}
                    />
                </Box>
            </Box>
        </AuthenticatedLayout>
    );
}
