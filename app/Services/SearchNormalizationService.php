<?php

namespace App\Services;

class SearchNormalizationService
{
    /**
     * Normalizes Urdu text for better search matching.
     * - Trims whitespace
     * - Replaces Arabic characters with Urdu equivalents (e.g. ي -> ی)
     * - Removes common diacritics
     * - Replaces multiple spaces with single space
     */
    public static function normalizeUrdu(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // Standardize Arabic characters to Urdu
        $replacements = [
            'ي' => 'ی',
            'ى' => 'ی',
            'ك' => 'ک',
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ھ' => 'ہ', // Sometimes these are used interchangeably in typing
        ];
        $text = strtr($text, $replacements);

        // Remove Arabic/Urdu diacritics (Zabar, Zer, Pesh, etc.)
        // Range: \x{064B} to \x{065F}
        $text = preg_replace('/[\x{064B}-\x{065F}]/u', '', $text);

        // Replace multiple spaces with a single space and trim
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Normalizes English and Roman Urdu text for search matching.
     * - Lowercases
     * - Trims whitespace
     * - Removes punctuation
     * - Applies common Roman Urdu aliases
     */
    public static function normalizeEnglishAndRoman(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        $text = mb_strtolower($text);

        // Remove common punctuation to avoid tokenization issues on exact matches
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);

        // Common Roman Urdu normalizations (aliases)
        // These handle common spelling mistakes / variations
        $aliases = [
            'muje' => 'mujhe',
            'mjhe' => 'mujhe',
            'chaiye' => 'chahiye',
            'chahye' => 'chahiye',
            'hisaab' => 'hisab',
            'hissab' => 'hisab',
            'gaari' => 'gari',
            'kapray' => 'kapre',
            // Add more as needed based on user patterns
        ];

        $words = explode(' ', $text);
        $normalizedWords = array_map(function ($word) use ($aliases) {
            return $aliases[$word] ?? $word;
        }, $words);

        $text = implode(' ', $normalizedWords);

        // Replace multiple spaces with a single space
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}
