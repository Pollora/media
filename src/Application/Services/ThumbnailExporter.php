<?php

declare(strict_types=1);

namespace Pollora\Media\Application\Services;

use InvalidArgumentException;
use Pollora\Media\Domain\Contracts\BunnyPullZone;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Domain\Models\ThumbnailDriverSettings;
use Pollora\Media\Domain\Services\BunnyClasses;
use Pollora\Media\Domain\Services\ImgproxyPresets;
use RuntimeException;

/**
 * Keeps a service that makes the sizes in line with the declared sizes: one image class per size at
 * Bunny, one preset per size at imgproxy, so the declaration stays the only source of truth.
 *
 * Bunny: the classes are read, then merged by name (the ones the client made are kept, nothing is
 * deleted), and written only with `$execute`. The setting that forces classes is never touched.
 * imgproxy has no API for presets: the configuration to give it is returned, without the key or the salt.
 */
final readonly class ThumbnailExporter
{
    public function __construct(
        private ImageSizes $sizes,
        private ThumbnailDriverSettings $settings,
        private ThumbnailLimits $limits,
        private MediaConfig $config,
        private ?BunnyPullZone $bunny = null,
    ) {}

    /**
     * The driver the sizes use: what `--driver` defaults to.
     */
    public function configuredDriver(): string
    {
        return $this->settings->driver;
    }

    /**
     * @throws InvalidArgumentException When the driver has nothing to export, or lacks a setting
     * @throws RuntimeException When the service cannot be reached or refuses
     */
    public function export(string $driver, bool $execute): ExportReport
    {
        return match ($driver) {
            ThumbnailDriverSettings::BUNNY => $this->bunny($execute),
            ThumbnailDriverSettings::IMGPROXY => $this->imgproxy(),
            ThumbnailDriverSettings::POLLORA => throw new InvalidArgumentException('The pollora driver makes the sizes itself: there is nothing to export.'),
            default => throw new InvalidArgumentException(sprintf('There is nothing to export for the driver "%s"; use "bunny" or "imgproxy".', $driver)),
        };
    }

    private function bunny(bool $execute): ExportReport
    {
        $pullZone = (string) $this->settings->bunnyPullZone;

        if ($pullZone === '' || ! $this->bunny instanceof BunnyPullZone) {
            throw new InvalidArgumentException('Exporting to Bunny needs POLLORA_MEDIA_BUNNY_PULL_ZONE and POLLORA_MEDIA_BUNNY_API_KEY (media.thumbnails.bunny.pull_zone and api_key).');
        }

        $mapping = new BunnyClasses;
        $classes = $this->bunny->classes($pullZone);
        $created = [];
        $updated = [];
        $unchanged = [];
        $warnings = [];
        $lines = [];

        $sizeNames = array_keys($this->sizes->withVariants());

        foreach ($this->sizes->withVariants() as $sizeName => $spec) {
            $refusal = $mapping->refusal($sizeName, $sizeNames, $spec);

            if ($refusal !== null) {
                $warnings[] = $refusal;

                continue;
            }

            $name = $mapping->className($sizeName);
            $properties = $mapping->properties($spec);
            $index = $this->indexOf($classes, $name);

            foreach ($mapping->caveats($spec) as $caveat) {
                $warnings[] = $caveat;
            }

            if ($index === null) {
                $classes[] = ['Name' => $name, 'Properties' => $properties];
                $created[] = $name;
                $lines[] = sprintf('+ %s %s', $name, $this->describe($properties));
            } elseif ($this->normalized($classes[$index]['Properties']) !== $properties) {
                $classes[$index]['Properties'] = $properties;
                $updated[] = $name;
                $lines[] = sprintf('~ %s %s', $name, $this->describe($properties));
            } else {
                $unchanged[] = $name;
                $lines[] = sprintf('= %s', $name);
            }
        }

        $applied = $execute && ($created !== [] || $updated !== []);

        if ($applied) {
            $this->bunny->saveClasses($pullZone, $classes);
        }

        return new ExportReport(ThumbnailDriverSettings::BUNNY, $lines, $warnings, $created, $updated, $unchanged, $applied);
    }

    private function imgproxy(): ExportReport
    {
        $presets = new ImgproxyPresets;
        $definitions = [];
        $warnings = [];

        foreach ($this->sizes->withVariants() as $spec) {
            $refusal = $presets->refusal($spec);

            if ($refusal !== null) {
                $warnings[] = $refusal;

                continue;
            }

            $definitions[] = $presets->definition($spec);
        }

        $s3 = $this->settings->imgproxySource === 's3' && $this->settings->imgproxyBucket !== null;
        $lines = [
            'IMGPROXY_PRESETS='.implode(',', $definitions),
            'IMGPROXY_ONLY_PRESETS=true',
            'IMGPROXY_MAX_SRC_RESOLUTION='.(int) ceil($this->limits->maxPixels / 1_000_000),
            'IMGPROXY_ALLOWED_SOURCES='.($s3 ? 's3://'.$this->settings->imgproxyBucket.'/' : $this->config->url.'/'),
        ];

        if ($this->settings->imgproxySignatureSize !== 32) {
            $lines[] = 'IMGPROXY_SIGNATURE_SIZE='.$this->settings->imgproxySignatureSize;
        }

        if (! $s3 && $this->isLoopback($this->config->url)) {
            $warnings[] = 'media.url points at the loopback address of this machine, which imgproxy refuses as a source by default: for local development only, start imgproxy with IMGPROXY_ALLOW_LOOPBACK_SOURCE_ADDRESSES=true.';
        }

        return new ExportReport(ThumbnailDriverSettings::IMGPROXY, $lines, $warnings);
    }

    private function isLoopback(string $url): bool
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        return $host === 'localhost' || $host === '::1' || preg_match('/^127\.\d+\.\d+\.\d+$/', $host) === 1;
    }

    /**
     * @param  list<array{Name: string, Properties: array<string, string>}>  $classes
     */
    private function indexOf(array $classes, string $name): ?int
    {
        foreach ($classes as $index => $class) {
            if ($class['Name'] === $name) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $properties
     * @return array<string, string>
     */
    private function normalized(array $properties): array
    {
        $properties = array_map(strval(...), $properties);
        ksort($properties);

        return $properties;
    }

    /**
     * @param  array<string, string>  $properties
     */
    private function describe(array $properties): string
    {
        return (string) json_encode($properties, JSON_UNESCAPED_SLASHES);
    }
}
