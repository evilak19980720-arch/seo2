<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ArticleType: string implements HasLabel
{
    case Guide = 'guide';
    case Update = 'update';
    case Faq = 'faq';
    case UseCase = 'use_case';

    public function getLabel(): string
    {
        return match ($this) {
            self::Guide => '教程攻略',
            self::Update => '更新公告',
            self::Faq => 'FAQ 扩展',
            self::UseCase => '使用场景',
        };
    }

    /** Default word range; overridable in global settings. */
    public function defaultWordRange(): array
    {
        return match ($this) {
            self::Guide => [800, 1500],
            self::Update => [300, 700],
            self::Faq => [300, 600],
            self::UseCase => [600, 1200],
        };
    }
}
