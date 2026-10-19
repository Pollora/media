<?php

declare(strict_types=1);

namespace Pollora\Media\Application\Services;

use Closure;
use InvalidArgumentException;
use Pollora\Media\Domain\Enums\Fit;
use Pollora\Media\Domain\Models\ImageSizeSpec;
use Pollora\Media\Domain\Services\SizedFileName;

/**
 * The image sizes the project declares, whichever way: the fluent API or,
 * later, an attribute. A size declared twice takes the last word. What cannot
 * make a size is left out and explained by refusals().
 */
final class ImageSizes
{
    /** @var array<string, ImageSizeDeclaration> */
    private array $declarations = [];

    /** @var array<string, ImageSizeSpec>|null */
    private ?array $specs = null;

    /** @var list<string> */
    private array $refusals = [];

    private readonly SizedFileName $names;

    /**
     * @param  Closure(): string  $key  The secret file name signatures are made with
     */
    public function __construct(Closure $key)
    {
        $this->names = new SizedFileName($key);
    }

    public function add(string $name): ImageSizeDeclaration
    {
        $this->specs = null;

        return $this->declarations[$name] = new ImageSizeDeclaration($name);
    }

    public function get(string $name): ?ImageSizeSpec
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * @return array<string, ImageSizeSpec>
     */
    public function all(): array
    {
        if ($this->specs !== null) {
            return $this->specs;
        }

        $this->specs = [];
        $this->refusals = [];

        foreach ($this->declarations as $name => $declaration) {
            try {
                $this->specs[$name] = $declaration->toSpec();
            } catch (InvalidArgumentException $exception) {
                $this->refusals[] = $exception->getMessage();
            }
        }

        return $this->specs;
    }

    /**
     * Why declarations were left out.
     *
     * @return list<string>
     */
    public function refusals(): array
    {
        $this->all();

        return $this->refusals;
    }

    /**
     * The bounded sizes an extra width of a size stands for, by name (`hero-640w`).
     *
     * @return array<string, ImageSizeSpec>
     */
    public function variantsOf(ImageSizeSpec $spec): array
    {
        $variants = [];

        foreach ($spec->widths as $width) {
            $name = sprintf('%s-%dw', $spec->name, $width);
            $variants[$name] = new ImageSizeSpec($name, Fit::Scale, $width, null, $spec->format, $spec->quality, false, $spec->eager);
        }

        return $variants;
    }

    /**
     * Every size to make or to serve: the declared ones, and the bounded size of each extra width.
     *
     * @return array<string, ImageSizeSpec>
     */
    public function withVariants(): array
    {
        $known = [];

        foreach ($this->all() as $name => $spec) {
            $known[$name] = $spec;
            $known += $this->variantsOf($spec);
        }

        return $known;
    }

    public function names(): SizedFileName
    {
        return $this->names;
    }

    public function isEmpty(): bool
    {
        return $this->declarations === [];
    }
}
