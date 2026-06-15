import React from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Menu, MenuItem, Box } from '@mui/material';
import LanguageIcon from '@mui/icons-material/Language';
import TranslateIcon from '@mui/icons-material/Translate';

export default function LanguageSwitcher() {
  const { i18n, t } = useTranslation();
  const [anchorEl, setAnchorEl] = React.useState(null);
  const open = Boolean(anchorEl);

  const handleClick = (event) => {
    setAnchorEl(event.currentTarget);
  };

  const handleClose = () => {
    setAnchorEl(null);
  };

  const changeLanguage = (lng) => {
    i18n.changeLanguage(lng);
    handleClose();
    // Update document direction for RTL/LTR
    document.dir = lng === 'ur' ? 'rtl' : 'ltr';
    document.documentElement.dir = lng === 'ur' ? 'rtl' : 'ltr';
    document.body.dir = lng === 'ur' ? 'rtl' : 'ltr';
  };

  const currentLanguage = i18n.language || 'en';

  return (
    <Box>
      {/* <Button
        id="language-button"
        aria-controls={open ? 'language-menu' : undefined}
        aria-haspopup="true"
        aria-expanded={open ? 'true' : undefined}
        onClick={handleClick}
        startIcon={<TranslateIcon />}
        variant="outlined"
        sx={{
          color: 'white',
          borderColor: 'divider',
          '&:hover': {
            borderColor: 'primary.main',
            backgroundColor: 'action.hover',
          },
        }}
      >
        {currentLanguage === 'en' ? 'English' : 'اردو'}
      </Button> */}
      <Button
  id="language-button"
  aria-controls={open ? 'language-menu' : undefined}
  aria-haspopup="true"
  aria-expanded={open ? 'true' : undefined}
  onClick={handleClick}
  startIcon={<TranslateIcon />}
  variant="outlined"
  sx={{
    color: 'white',
    borderColor: 'divider',
    '&:hover': {
      borderColor: 'primary.main',
      backgroundColor: 'action.hover',
    },
  }}
>
  {currentLanguage === 'en' ? (
    'English'
  ) : (
    <span
      style={{
        fontFamily: 'Noto Nastaliq Urdu, serif',
        fontSize: '18px', 
        direction: 'rtl',
        padding: '0 8px',
        marginTop: '2px',
      }}
    >
      اردو
    </span>
  )}
</Button>

      <Menu
        id="language-menu"
        anchorEl={anchorEl}
        open={open}
        onClose={handleClose}
        MenuListProps={{
          'aria-labelledby': 'language-button',
        }}
        PaperProps={{
          sx: {
            backgroundColor: '#2A303C',
            border: '1px solid white',
            color: 'white',
            '& .MuiMenuItem-root': {
              color: 'white',
              '&:hover': {
                backgroundColor: 'rgba(255, 255, 255, 0.1)',
              },
              '&.Mui-selected': {
                backgroundColor: 'rgba(255, 255, 255, 0.2)',
                '&:hover': {
                  backgroundColor: 'rgba(255, 255, 255, 0.25)',
                },
              },
            },
          },
        }}
      >
        <MenuItem 
          onClick={() => changeLanguage('en')}
          selected={currentLanguage === 'en'}
        >
          English
        </MenuItem>
        <MenuItem 
          onClick={() => changeLanguage('ur')}
          selected={currentLanguage === 'ur'}
        >
          اردو (Urdu)
        </MenuItem>
      </Menu>
    </Box>
  );
}
