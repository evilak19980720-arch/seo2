<?php

namespace App\Services\Content;

/**
 * Text fingerprints for duplicate detection. Prefers provider embeddings
 * (semantic cosine); falls back to a hashed word-bigram TF vector so dedup
 * still works without an embedding model. Only same-kind fingerprints are compared.
 */
class Similarity
{
    public const BUCKETS = 1024;

    public static function fingerprint(string $text, ?array $embedding = null): array
    {
        if ($embedding) {
            return ['kind' => 'embedding', 'v' => array_map(fn ($x) => round((float) $x, 5), $embedding)];
        }

        return ['kind' => 'tf', 'v' => self::hashedTf($text)];
    }

    /** Sparse map bucket => weight of word bigrams. */
    public static function hashedTf(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $vec = [];
        for ($i = 0; $i < count($words) - 1; $i++) {
            $bucket = crc32($words[$i].' '.$words[$i + 1]) % self::BUCKETS;
            $vec[$bucket] = ($vec[$bucket] ?? 0) + 1;
        }

        return $vec;
    }

    public static function compare(?array $a, ?array $b): ?float
    {
        if (! $a || ! $b || ($a['kind'] ?? null) !== ($b['kind'] ?? null)) {
            return null;
        }

        return self::cosine($a['v'], $b['v']);
    }

    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        foreach ($a as $k => $v) {
            if (isset($b[$k])) {
                $dot += $v * $b[$k];
            }
        }
        $na = sqrt(array_sum(array_map(fn ($v) => $v * $v, $a)));
        $nb = sqrt(array_sum(array_map(fn ($v) => $v * $v, $b)));

        return $na > 0 && $nb > 0 ? $dot / ($na * $nb) : 0.0;
    }
}
