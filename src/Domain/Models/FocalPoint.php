<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Models;

use InvalidArgumentException;

/**
 * The part of an image that must survive a crop, as fractions of the original: `x` from the left edge
 * (0) to the right edge (1), `y` from the top (0) to the bottom (1).
 *
 * Fractions, not pixels, so the point survives a resize of the original. They are kept to a thousandth:
 * the name of a file and its signature are written from that figure, so a value that went through a
 * database, a JSON payload or a float round-trip (0.3 read back as 0.30000000000000004) is the same point,
 * with the same file names.
 */
final readonly class FocalPoint
{
    /** The finest step of a coordinate: a thousandth of the image. */
    public const int PRECISION = 3;

    public float $x;

    public float $y;

    /**
     * @throws InvalidArgumentException When a coordinate is not a number from 0 to 1
     */
    public function __construct(float $x, float $y)
    {
        foreach (['x' => $x, 'y' => $y] as $axis => $value) {
            if (! is_finite($value) || $value < 0.0 || $value > 1.0) {
                throw new InvalidArgumentException(sprintf('A focal point needs %s between 0 and 1.', $axis));
            }
        }

        $this->x = round($x, self::PRECISION);
        $this->y = round($y, self::PRECISION);
    }

    /**
     * The point a stored value stands for, or null when it is not one: anything that comes from a database,
     * a REST request or a filter is checked here, and a malformed value never reaches a crop.
     */
    public static function tryFrom(mixed $value): ?self
    {
        if (! is_array($value) || ! isset($value['x'], $value['y']) || ! is_numeric($value['x']) || ! is_numeric($value['y'])) {
            return null;
        }

        try {
            return new self((float) $value['x'], (float) $value['y']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The point a file name says, from its thousandths.
     */
    public static function fromThousandths(int $x, int $y): ?self
    {
        return $x < 0 || $y < 0 || $x > 1000 || $y > 1000 ? null : new self($x / 1000, $y / 1000);
    }

    /**
     * @return array{x: float, y: float}
     */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y];
    }

    /**
     * What identifies the point, for the signature of the files it makes: stable for the same point.
     */
    public function fingerprint(): string
    {
        return sprintf('%.3F,%.3F', $this->x, $this->y);
    }

    /**
     * The segment of a file name: `f0250_0750` for 25 % from the left and 75 % from the top.
     */
    public function token(): string
    {
        return sprintf('f%04d_%04d', (int) round($this->x * 1000), (int) round($this->y * 1000));
    }

    public function equals(?self $other): bool
    {
        return $other instanceof self && $this->fingerprint() === $other->fingerprint();
    }
}
