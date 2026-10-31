<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SiteStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Generating = 'generating';
    case Preview = 'preview';
    case Live = 'live';
    case Paused = 'paused';
    case Failed = 'failed';
    case Deleted = 'deleted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => '草稿',
            self::Generating => '生成中',
            self::Preview => '待上线',
            self::Live => '已上线',
            self::Paused => '已暂停',
            self::Failed => '生成失败',
            self::Deleted => '已删除',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Live => 'success',
            self::Generating, self::Preview => 'info',
            self::Paused, self::Draft => 'gray',
            self::Failed, self::Deleted => 'danger',
        };
    }
}
