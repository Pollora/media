<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Enums;

/**
 * How an image is brought to the size of a declaration.
 */
enum Fit: string
{
    /** Crops to exactly the box, centered. */
    case Cover = 'cover';

    /** Resizes to a width and/or a height, keeping the ratio. */
    case Scale = 'scale';

    /** Fits inside the box, keeping the ratio, without cropping. */
    case Contain = 'contain';
}
