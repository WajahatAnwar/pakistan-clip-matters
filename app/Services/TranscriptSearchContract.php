<?php
namespace App\Services;

/** Mirrors transcript_search_contract.py using the same versioned rules. */
final class TranscriptSearchContract
{
    public static function normalize(string $value): string
    {
        $value = SearchTextNormalizer::normalize($value);
        $value = trim(preg_replace('/[^\p{L}\p{N}_]+/u', ' ', $value));
        static $config;
        $config ??= json_decode(file_get_contents(base_path('Clip matter py  embeding/search_contract_v1.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($config['acronyms'] as $key => $letters) {
            $value = preg_replace('/(?<![\p{L}\p{N}_])'.implode(' ', $letters).'(?![\p{L}\p{N}_])/u', $key, $value);
        }
        return $value;
    }

    public static function titleScore(string $title, string $query): float
    {
        $key = self::normalize($title); $wanted = self::normalize($query);
        if ($wanted === '') return 0;
        if ($key === $wanted) return 1;
        return array_diff(explode(' ', $wanted), explode(' ', $key)) === [] ? .9 : 0;
    }
}
