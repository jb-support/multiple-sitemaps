<?php
namespace JBSupport\MultipleSitemapsBundle;

class MultipleSitemapsConfig
{
    public const INDEX_MODE_PRECISELY_SELECTED = 1;
    public const INDEX_MODE_ANY_SELECTED = 2;
    public const INDEX_MODE_NOTHING_SELECTED = 3;
    public const INDEX_MODE_ALL = 4;

    public const TYPE_SITEMAP = 1;
    public const TYPE_INDEX = 2;

    public static $indexModes = [
        self::INDEX_MODE_PRECISELY_SELECTED,
        self::INDEX_MODE_ANY_SELECTED,
        self::INDEX_MODE_NOTHING_SELECTED,
        self::INDEX_MODE_ALL,
    ];

    public static $types = [
        self::TYPE_SITEMAP,
        self::TYPE_INDEX,
    ];
}
