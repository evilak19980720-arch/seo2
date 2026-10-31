<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ArticleStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Published = 'published';
    case Blocked = 'blocked';
    case Noindex = 'noindex';
    case Deleted = 'deleted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => '队列中',
            self::Published => '已发布',
            self::Blocked => '被拦截',
            self::Noindex => 'noindex',
            self::Deleted => '已删除',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Published => 'success',
            self::Queued => 'info',
            self::Noindex => 'warning',
            self::Blocked, self::Deleted => 'danger',
        };
    }

    /** Statuses whose detail page exists as a static file. */
    public function isRendered(): bool
    {
        return in_array($this, [self::Published, self::Noindex], true);
    }
}
