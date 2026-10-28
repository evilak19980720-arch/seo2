<?php

namespace App\Support;

use UnitEnum;

/**
 * Admin menu names edited on 系统 › 菜单管理: item labels, groups and order,
 * and group names. Stored in settings as
 * ['items' => [class => [label, group, sort]], 'groups' => [default name => name]].
 */
class AdminMenu
{
    /** The default menu groups, in their order. */
    public const GROUPS = ['站点', '内容', '变现', '系统'];

    protected static bool $raw = false;

    /** Run without the edited names (to read each item's defaults). */
    public static function defaults(callable $callback): mixed
    {
        $raw = self::$raw;
        self::$raw = true;
        try {
            return $callback();
        } finally {
            self::$raw = $raw;
        }
    }

    protected static function config(): array
    {
        return self::$raw ? [] : (array) Settings::get('admin_menu', []);
    }

    public static function label(string $class, mixed $default): mixed
    {
        return filled($v = self::config()['items'][$class]['label'] ?? null) ? $v : $default;
    }

    public static function group(string $class, string|UnitEnum|null $default): string|UnitEnum|null
    {
        $group = self::config()['items'][$class]['group'] ?? null;
        $group = filled($group) ? $group : $default;

        return is_string($group) ? self::groupLabel($group) : $group;
    }

    public static function sort(string $class, ?int $default): ?int
    {
        $sort = self::config()['items'][$class]['sort'] ?? null;

        return is_numeric($sort) ? (int) $sort : $default;
    }

    /** A group's shown name ("系统" may be renamed "系统设置"). */
    public static function groupLabel(string $group): string
    {
        return filled($v = self::config()['groups'][$group] ?? null) ? $v : $group;
    }
}
