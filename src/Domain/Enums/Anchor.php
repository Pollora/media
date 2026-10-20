<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Enums;

/**
 * Where a cover keeps the image when it crops it: the nine positions of `add_image_size()`.
 */
enum Anchor: string
{
    case Center = 'center';
    case Top = 'top';
    case Bottom = 'bottom';
    case Left = 'left';
    case Right = 'right';
    case TopLeft = 'top-left';
    case TopRight = 'top-right';
    case BottomLeft = 'bottom-left';
    case BottomRight = 'bottom-right';

    /**
     * @return int -1 for the left edge, 0 for the middle, 1 for the right edge
     */
    public function horizontal(): int
    {
        return match ($this) {
            self::Left, self::TopLeft, self::BottomLeft => -1,
            self::Right, self::TopRight, self::BottomRight => 1,
            default => 0,
        };
    }

    /**
     * @return int -1 for the top edge, 0 for the middle, 1 for the bottom edge
     */
    public function vertical(): int
    {
        return match ($this) {
            self::Top, self::TopLeft, self::TopRight => -1,
            self::Bottom, self::BottomLeft, self::BottomRight => 1,
            default => 0,
        };
    }

    /**
     * The gravity of an imgproxy resize.
     *
     * @see https://docs.imgproxy.net/latest/usage/processing#gravity
     */
    public function imgproxyGravity(): string
    {
        return match ($this) {
            self::Center => 'ce',
            self::Top => 'no',
            self::Bottom => 'so',
            self::Left => 'we',
            self::Right => 'ea',
            self::TopLeft => 'nowe',
            self::TopRight => 'noea',
            self::BottomLeft => 'sowe',
            self::BottomRight => 'soea',
        };
    }
}
