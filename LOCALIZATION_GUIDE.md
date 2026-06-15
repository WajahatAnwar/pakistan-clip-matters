# Localization Setup (i18n)

This project now supports multiple languages (English and Urdu) using **react-i18next**.

## 🌍 Features

- ✅ Switch between English and Urdu with a single button click
- ✅ Automatic language detection and storage in localStorage
- ✅ RTL (Right-to-Left) support for Urdu
- ✅ Easy to extend with more languages

## 📁 Project Structure

```
resources/js/
├── i18n.js                           # i18n configuration
├── locales/
│   ├── en/
│   │   └── translation.json          # English translations
│   └── ur/
│       └── translation.json          # Urdu translations
├── Components/
│   └── LanguageSwitcher.jsx          # Language switcher button component
└── Pages/
    └── UserSide/
        └── MainVideoPage/
            └── index.jsx              # Example page with translations
```

## 🚀 How to Use

### Using the Language Switcher

1. Look for the **language button** in the top navigation bar
2. Click on it to see available languages (English / اردو)
3. Select your preferred language
4. The entire app will switch to that language immediately
5. Your preference is saved automatically

### Adding Translations to Components

1. **Import the translation hook:**
```jsx
import { useTranslation } from 'react-i18next';
```

2. **Initialize in your component:**
```jsx
export default function MyComponent() {
  const { t } = useTranslation();
  
  return (
    <div>
      <h1>{t('mainVideoPage.title')}</h1>
      <button>{t('common.save')}</button>
    </div>
  );
}
```

3. **Using translation keys:**
```jsx
// Simple translation
{t('common.loading')}

// Nested translation
{t('mainVideoPage.tabs.transcript')}

// With variables (add to translation.json)
{t('welcome', { name: userName })}
```

## 📝 Adding New Translations

### 1. Add to English translations (`resources/js/locales/en/translation.json`):

```json
{
  "myNewSection": {
    "title": "My New Section",
    "description": "This is a description"
  }
}
```

### 2. Add to Urdu translations (`resources/js/locales/ur/translation.json`):

```json
{
  "myNewSection": {
    "title": "میرا نیا سیکشن",
    "description": "یہ ایک تفصیل ہے"
  }
}
```

### 3. Use in your component:

```jsx
<Typography>{t('myNewSection.title')}</Typography>
<Typography>{t('myNewSection.description')}</Typography>
```

## 🌐 Adding More Languages

To add a new language (e.g., Arabic):

1. Create a new translation file:
   ```
   resources/js/locales/ar/translation.json
   ```

2. Add the translations following the same structure as English

3. Update `resources/js/i18n.js`:
   ```javascript
   import translationAR from './locales/ar/translation.json';
   
   const resources = {
     en: { translation: translationEN },
     ur: { translation: translationUR },
     ar: { translation: translationAR },  // Add this
   };
   ```

4. Update the `LanguageSwitcher.jsx` component to include the new language option

## 📋 Available Translation Keys

### Common Keys
- `common.loading` - Loading...
- `common.save` - Save
- `common.cancel` - Cancel
- `common.delete` - Delete
- `common.edit` - Edit
- And more...

### Main Video Page Keys
- `mainVideoPage.title` - Video Analysis
- `mainVideoPage.tabs.transcript` - Transcript
- `mainVideoPage.tabs.customClips` - Custom Clips
- `mainVideoPage.clipActions.download` - Download
- `mainVideoPage.clipActions.downloadAll` - Download All
- And more...

### Authentication Keys
- `auth.login` - Login
- `auth.register` - Register
- `auth.email` - Email
- `auth.password` - Password
- And more...

## 🔧 Configuration

The i18n configuration is located in `resources/js/i18n.js`:

```javascript
i18n.init({
  resources,
  fallbackLng: 'en',      // Fallback language
  lng: 'en',              // Default language
  debug: false,           // Set to true for debugging
  interpolation: {
    escapeValue: false,   // React already escapes values
  },
});
```

## 🎯 Best Practices

1. **Use descriptive keys**: `mainVideoPage.clipActions.download` instead of `download1`
2. **Group related translations**: Keep related strings under the same parent key
3. **Keep translations synced**: When adding a key to one language, add it to all languages
4. **Use nested objects**: Organize translations hierarchically for better maintainability
5. **Test with both languages**: Always check that your UI works with both English and Urdu

## 🐛 Troubleshooting

### Translations not showing?
- Check that you've run `npm run build`
- Verify the translation key exists in both `en/translation.json` and `ur/translation.json`
- Check the browser console for errors

### Language not switching?
- Clear browser localStorage: `localStorage.clear()`
- Refresh the page
- Check that `i18n.js` is imported in `app.jsx`

### RTL not working for Urdu?
- The direction is automatically set in the `LanguageSwitcher` component
- Verify that `document.dir` is being set correctly

## 📦 Dependencies

- `i18next` - Internationalization framework
- `react-i18next` - React bindings for i18next
- `i18next-browser-languagedetector` - Language detection plugin

## 🔄 After Making Changes

Always run after updating translations:
```bash
npm run build
```

## 🎨 Styling for RTL

For Urdu (RTL), certain styles automatically adjust. If you need custom RTL styles:

```jsx
import { useTranslation } from 'react-i18next';

const { i18n } = useTranslation();
const isRTL = i18n.language === 'ur';

<Box sx={{
  textAlign: isRTL ? 'right' : 'left',
  direction: isRTL ? 'rtl' : 'ltr'
}}>
```

---

Happy translating! 🌍✨
