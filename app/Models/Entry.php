<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Site data shown in lists and on its own detail page: a game, provider,
 * promotion, event, notice or news item. "data" holds the structured facts
 * (provider, theme, mechanic, tags, status, dates, parameters); "sections"
 * the detail page copy keyed by the collection's section keys.
 *
 * data: tags[], featured, date; game: provider, themes[], mechanics[] and the
 * PARAMS; promotion/event: starts_at, ends_at, status, reward, requirement;
 * provider: country, founded. Only facts from the sources are stored.
 */
class Entry extends Model
{
    public const COLLECTIONS = ['game', 'provider', 'promotion', 'event', 'notice', 'news'];

    /** Game parameters shown in the detail table, when known. */
    public const PARAMS = ['rtp', 'volatility', 'max_win', 'paylines', 'reels', 'min_bet', 'max_bet', 'release_date'];

    protected $fillable = ['site_id', 'collection', 'slug', 'title', 'summary', 'image', 'data', 'sections', 'sort', 'source'];

    protected function casts(): array
    {
        return ['data' => 'array', 'sections' => 'array'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return array_values(array_filter((array) $this->get('tags', []), 'is_string'));
    }

    /** upcoming / ongoing / ended for promotions and events (from status or dates). */
    public function status(): ?string
    {
        $status = $this->get('status');
        if (in_array($status, ['upcoming', 'ongoing', 'ended'], true)) {
            return $status;
        }
        $start = $this->get('starts_at') ? rescue(fn () => Carbon::parse($this->get('starts_at')), null, false) : null;
        $end = $this->get('ends_at') ? rescue(fn () => Carbon::parse($this->get('ends_at')), null, false) : null;
        if ($end && $end->isPast()) {
            return 'ended';
        }
        if ($start && $start->isFuture()) {
            return 'upcoming';
        }

        return $start || $end ? 'ongoing' : null;
    }

    /** @return array<string, string> known parameters (and lists) of this entry, for the detail table */
    public function params(): array
    {
        $keys = match ($this->collection) {
            'game' => [...self::PARAMS, 'themes', 'mechanics'],
            'promotion', 'event' => ['reward', 'requirement', 'starts_at', 'ends_at'],
            'provider' => ['country', 'founded'],
            default => [],
        };
        $out = [];
        foreach ($keys as $key) {
            $value = $this->get($key);
            $value = is_array($value) ? implode(', ', array_filter($value, 'is_scalar')) : (is_scalar($value) ? trim((string) $value) : '');
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** Publication / start date used to order news, notices and events. */
    public function date(): ?Carbon
    {
        foreach (['date', 'starts_at', 'release_date'] as $key) {
            if ($value = $this->get($key)) {
                if ($date = rescue(fn () => Carbon::parse($value), null, false)) {
                    return $date;
                }
            }
        }

        return null;
    }
}
