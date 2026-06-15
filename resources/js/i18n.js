import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import LanguageDetector from 'i18next-browser-languagedetector';

// Translation files
import translationEN from './locales/en/translation.json';
import translationUR from './locales/ur/translation.json';

const resources = {
  en: {
    translation: translationEN,
  },
  ur: {
    translation: translationUR,
  },
};

i18n
  .use(LanguageDetector)
  .use(initReactI18next)
  .init({
    resources,
    fallbackLng: 'en',
    debug: false,
    interpolation: {
      escapeValue: false,
    },
    detection: {
      order: ['localStorage', 'navigator'],
      caches: ['localStorage'],
    },
  });

// Set initial direction based on language
const setDirection = (lng) => {
  const dir = lng === 'ur' ? 'rtl' : 'ltr';
  document.dir = dir;
  document.documentElement.dir = dir;
  document.body.dir = dir;
};

// Set direction on init
setDirection(i18n.language);

// Listen for language changes
i18n.on('languageChanged', (lng) => {
  setDirection(lng);
});

export default i18n;
