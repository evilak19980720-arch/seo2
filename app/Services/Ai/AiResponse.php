<?php

namespace App\Services\Ai;

class AiResponse
{
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly ?int $promptId = null,
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

        if (! is_array($decoded)) {
            throw new AiException('AI response is not valid JSON: '.mb_substr($this->text, 0, 200));
        }

        return $decoded;
    }
}
