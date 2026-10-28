<?php

namespace App\Services\Ai;

class AiResponse
{
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly ?int $promptId = null,
        public readonly bool $truncated = false,
    ) {}

    /** Decode a JSON answer, tolerating markdown fences or prose around it. */
    public function json(): array
    {
        $text = trim($this->text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            }
        }

        // Raw line breaks inside strings (CSS, HTML) or an answer cut off at the token limit.
        if (! is_array($decoded) && ($start = strpos($text, '{')) !== false) {
            $decoded = json_decode(self::repair(substr($text, $start)), true);
        }

        if (! is_array($decoded)) {
            throw new AiException(($this->truncated ? 'AI response was cut off at the output limit and is not valid JSON: ' : 'AI response is not valid JSON: ').mb_substr($this->text, 0, 200));
        }

        return $decoded;
    }

    /**
     * Best-effort JSON repair: escapes control characters inside strings,
     * drops trailing commas and, for a cut-off answer, the unfinished last
     * value, then closes the open strings, arrays and objects.
     */
    public static function repair(string $json): string
    {
        $out = '';
        $stack = [];
        $inString = false;
        $escaped = false;
        $len = strlen($json);
        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === '"') {
                    $inString = false;
                } elseif (ord($ch) < 0x20) {
                    $out .= ["\n" => '\\n', "\r" => '\\r', "\t" => '\\t'][$ch] ?? ' ';

                    continue;
                }
                $out .= $ch;

                continue;
            }
            if ($ch === '"') {
                $inString = true;
            } elseif ($ch === '{' || $ch === '[') {
                $stack[] = $ch === '{' ? '}' : ']';
            } elseif ($ch === '}' || $ch === ']') {
                if ($stack === []) {
                    break;
                }
                array_pop($stack);
                $out = preg_replace('/,\s*$/', '', $out);
                $out .= $ch;
                if ($stack === []) {
                    return $out;
                }

                continue;
            }
            $out .= $ch;
        }

        // Cut off: finish the open string, then drop a dangling key or unfinished value.
        if ($inString) {
            $out = $escaped ? substr($out, 0, -1).'"' : $out.'"';
        }
        $out = preg_replace('/,\s*"[^"]*"\s*:?\s*$|,\s*$|:\s*$/', '', rtrim($out));
        $out = preg_replace('/([{,]\s*)"[^"]*"\s*$/', '$1', $out);
        $out = preg_replace('/,\s*$/', '', $out);

        return $out.implode('', array_reverse($stack));
    }
}
