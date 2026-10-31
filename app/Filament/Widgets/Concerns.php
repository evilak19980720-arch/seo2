<?php

namespace App\Filament\Widgets;

/** Shared filter helpers for dashboard widgets. */
trait Concerns
{
    protected function siteId(): ?int
    {
        return isset($this->pageFilters['site_id']) && $this->pageFilters['site_id'] !== '' ? (int) $this->pageFilters['site_id'] : null;
    }

    protected function days(): int
    {
        return (int) ($this->pageFilters['range'] ?? 7);
    }

    /** Search Console data lags ~2 days; "today" means the latest available day. */
    protected function since(): string
    {
        return now()->subDays($this->days() + 2)->toDateString();
    }
}
