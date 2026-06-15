import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import React, { useEffect, useState } from 'react';
import { Box, Tabs, Tab, TextField, InputAdornment, Button, ButtonGroup } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { usePage } from '@inertiajs/react';
import SearchIcon from '@mui/icons-material/Search';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';

import All from './All';
import Inprocess from './InProcess';
import Tracked from './Tracked';
import Failed from './Failed';

export default function Index({ canManageVideos }) {
  const { t } = useTranslation();
  const [currentTab, setCurrentTab] = useState(0);
  const [videos, setVideos] = useState([]);
  const [paginationModel, setPaginationModel] = useState({
    page: 0,
    pageSize: 5
  });
  const [rowCount, setRowCount] = useState(0);
  const [loading, setLoading] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [sortBy, setSortBy] = useState('newest'); // 'newest' or 'oldest'

  const handleTabChange = (event, newValue) => {
    setCurrentTab(newValue);
    setPaginationModel({ page: 0, pageSize: 5 }); // Reset to first page on tab change
    setSearchQuery(''); // Reset search on tab change
    setSortBy('newest'); // Reset to newest when changing tabs
  };

  const handleSearchChange = (event) => {
    setSearchQuery(event.target.value);
    setPaginationModel({ page: 0, pageSize: paginationModel.pageSize }); // Reset to first page on search
  };

  const fetchVideos = async (page = 0, pageSize = 5, search = '', sort = 'newest') => {
    setLoading(true);
    try {
      // Map tab index to status filter
      const statusMap = {
        0: 'all',          // All tab
        1: 'processing',   // In Process tab
        2: 'tracked',      // Tracked tab
        3: 'failed'        // Failed tab
      };
      
      const status = statusMap[currentTab] || 'all';
      
      // Laravel uses 1-based pagination, DataGrid uses 0-based
      const url = `/user/videos?page=${page + 1}&per_page=${pageSize}&status=${status}${search ? `&search=${encodeURIComponent(search)}` : ''}&sort=${sort}`;
      const response = await fetch(url, {
        method: 'GET',
        headers: {
          Accept: 'application/json',
        },
      });

      const data = await response.json();
      setVideos(data.data);
      setRowCount(data.total);

      console.log('Fetched videos:', data.data, 'Status:', status, 'Search:', search);
      console.log('Total:', data.total, 'Page:', page + 1);

    } catch (error) {
      console.error('Error fetching videos:', error);
    } finally {
      setLoading(false);
    }
  };

  const handlePaginationModelChange = (newPaginationModel) => {
    setPaginationModel(newPaginationModel);
  };

  useEffect(() => {
    fetchVideos(paginationModel.page, paginationModel.pageSize, searchQuery, sortBy);
  }, [currentTab, paginationModel, searchQuery, sortBy]);

  return (
    <AuthenticatedLayout
      header={<h2 className="font-semibold text-2xl text-white leading-tight">{t('videoManagement.title')}</h2>}
    >
      <Box>

        {/* Search Bar and Sort Controls */}
        <Box sx={{ mb: 3, display: 'flex', gap: 2, alignItems: 'center' }}>
          <TextField
            fullWidth
            placeholder={t('videoManagement.searchPlaceholder') || 'Search videos by title, filename, or description...'}
            value={searchQuery}
            onChange={handleSearchChange}
            InputProps={{
              startAdornment: (
                <InputAdornment position="start">
                  <SearchIcon sx={{ color: '#9CA3AF' }} />
                </InputAdornment>
              ),
              sx: {
                bgcolor: '#1F2324',
                borderRadius: 2,
                color: 'white',
                '& .MuiOutlinedInput-notchedOutline': {
                  borderColor: '#374151',
                },
                '&:hover .MuiOutlinedInput-notchedOutline': {
                  borderColor: '#4B5563',
                },
                '&.Mui-focused .MuiOutlinedInput-notchedOutline': {
                  borderColor: '#EE1D52',
                },
              },
            }}
            sx={{
              '& .MuiInputBase-input::placeholder': {
                color: '#6B7280',
                opacity: 1,
              },
            }}
          />
          <ButtonGroup variant="outlined" sx={{ flexShrink: 0 }}>
            <Button
              onClick={() => setSortBy('newest')}
              sx={{
                backgroundColor: sortBy === 'newest' ? '#EE1D52' : 'transparent',
                color: sortBy === 'newest' ? 'white' : '#9CA3AF',
                borderColor: '#374151',
                textTransform: 'none',
                minWidth: '100px',
                '&:hover': {
                  backgroundColor: sortBy === 'newest' ? '#dc1847' : 'rgba(238, 29, 82, 0.1)',
                  borderColor: '#374151',
                },
              }}
              startIcon={<ArrowDownwardIcon />}
            >
              {t('videoManagement.sort.newest') || 'Newest'}
            </Button>
            <Button
              onClick={() => setSortBy('oldest')}
              sx={{
                backgroundColor: sortBy === 'oldest' ? '#EE1D52' : 'transparent',
                color: sortBy === 'oldest' ? 'white' : '#9CA3AF',
                borderColor: '#374151',
                textTransform: 'none',
                minWidth: '100px',
                '&:hover': {
                  backgroundColor: sortBy === 'oldest' ? '#dc1847' : 'rgba(238, 29, 82, 0.1)',
                  borderColor: '#374151',
                },
              }}
              startIcon={<ArrowUpwardIcon />}
            >
              {t('videoManagement.sort.oldest') || 'Oldest'}
            </Button>
          </ButtonGroup>
        </Box>

        {/* Tabs */}
        <Box
          sx={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            mb: 3,
            overflow: { xs: 'auto', sm: 'visible' },
          }}
        >
          <Box sx={{ display: 'flex', gap: { xs: 0, sm: 10 }, width: '100%' }}>
            <Tabs
              value={currentTab}
              onChange={handleTabChange}
              variant="scrollable"
              scrollButtons="auto"
              allowScrollButtonsMobile
              TabIndicatorProps={{
                style: {
                  backgroundColor: '#EE1D52',
                  height: '2px',
                },
              }}
              sx={{
                minHeight: 'unset',
                width: '100%',
                '& .MuiTabs-flexContainer': {
                  gap: { xs: '12px', sm: '20px' },
                },
                '& .MuiTabs-scrollButtons': {
                  color: '#9CA3AF',
                  '&.Mui-disabled': {
                    opacity: 0.3,
                  },
                },
                '& .MuiTab-root': {
                  color: '#9CA3AF',
                  fontSize: { xs: '14px', sm: '16px' },
                  textTransform: 'none',
                  minHeight: 'unset',
                  minWidth: { xs: 'auto', sm: 'unset' },
                  padding: { xs: '2px 8px', sm: '2px 2px' },
                  paddingBottom: '4px',
                  whiteSpace: 'nowrap',
                  '&.Mui-selected': {
                    color: '#EE1D52',
                  },
                },
              }}
            >
              <Tab label={t('videoManagement.tabs.all')} disableRipple />
              <Tab label={t('videoManagement.tabs.inprocess')} disableRipple />
              <Tab label={t('videoManagement.tabs.tracked')} disableRipple />
              <Tab label={t('videoManagement.tabs.failed')} disableRipple />
            </Tabs>
          </Box>
        </Box>

        {/* Tab Content */}
        <Box sx={{ p: 2 }}>
          {currentTab === 0 && <All videos={videos} loading={loading} rowCount={rowCount} paginationModel={paginationModel} onPaginationModelChange={handlePaginationModelChange} canManageVideos={canManageVideos} />}
          {currentTab === 1 && <Inprocess videos={videos} loading={loading} rowCount={rowCount} paginationModel={paginationModel} onPaginationModelChange={handlePaginationModelChange} canManageVideos={canManageVideos} />}
          {currentTab === 2 && <Tracked videos={videos} loading={loading} rowCount={rowCount} paginationModel={paginationModel} onPaginationModelChange={handlePaginationModelChange} canManageVideos={canManageVideos} />}
          {currentTab === 3 && <Failed videos={videos} loading={loading} rowCount={rowCount} paginationModel={paginationModel} onPaginationModelChange={handlePaginationModelChange} canManageVideos={canManageVideos} />}
        </Box>
      </Box>
    </AuthenticatedLayout>
  );
}
