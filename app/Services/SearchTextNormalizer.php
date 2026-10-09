<?php
namespace App\Services;

final class SearchTextNormalizer
{
    public static function normalize(string $value): string
    {
        if (!class_exists(\Normalizer::class)) {
            throw new \RuntimeException('Semantic search requires the PHP intl extension for Unicode normalization.');
        }
        $value = \Normalizer::normalize($value, \Normalizer::FORM_KC);
        $value = strtr($value, ['ي'=>'ی', 'ى'=>'ی', 'ك'=>'ک', '’'=>"'", '‘'=>"'", '“'=>'"', '”'=>'"',
            '،'=>',', '؟'=>'?', '۔'=>'.', '–'=>'-', '—'=>'-', "\u{200C}"=>' ', "\u{200D}"=>' ']);
        return trim(preg_replace('/\s+/u', ' ', mb_convert_case($value, MB_CASE_FOLD, 'UTF-8')));
    }
}
