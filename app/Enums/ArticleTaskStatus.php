<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ArticleTaskStatus: string implements HasColor, HasLabel
{
    case Waiting = 'waiting';
    case Generating = 'generating';
    case Polishing = 'polishing';
    case Reviewing = 'reviewing';
    case Checking = 'checking';
    case Done = 'done';
    case Blocked = 'blocked';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Waiting => '排队中',
            self::Generating => '生成中',
            self::Polishing => '润色中',
            self::Reviewing => 'AI 审核中',
            self::Checking => '重复度检测中',
            self::Done => '已完成',
            self::Blocked => '未通过',
            self::Skipped => '已跳过',
            self::Failed => '失败',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Waiting, self::Skipped => 'gray',
            self::Generating, self::Polishing, self::Reviewing, self::Checking => 'info',
            self::Done => 'success',
            self::Blocked => 'warning',
            self::Failed => 'danger',
        };
    }

    public function isRunning(): bool
    {
        return in_array($this, [self::Generating, self::Polishing, self::Reviewing, self::Checking], true);
    }
}
