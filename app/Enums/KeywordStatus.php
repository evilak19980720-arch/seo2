<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum KeywordStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Written = 'written';
    case HasImpressions = 'has_impressions';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => '待写',
            self::Written => '已写',
            self::HasImpressions => '有展示',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Written => 'info',
            self::HasImpressions => 'success',
        };
    }
}
