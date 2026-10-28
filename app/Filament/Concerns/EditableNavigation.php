<?php

namespace App\Filament\Concerns;

use App\Support\AdminMenu;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/** Menu label, group and order as edited on 系统 › 菜单管理. */
trait EditableNavigation
{
    public static function getNavigationLabel(): string|Htmlable
    {
        return AdminMenu::label(static::class, parent::getNavigationLabel());
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return AdminMenu::group(static::class, parent::getNavigationGroup());
    }

    public static function getNavigationSort(): ?int
    {
        return AdminMenu::sort(static::class, parent::getNavigationSort());
    }
}
