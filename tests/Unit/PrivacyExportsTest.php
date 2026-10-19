<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Pollora\Media\Domain\Models\MediaConfig;
use Pollora\Media\Infrastructure\WordPress\PrivacyExports;
use Pollora\Media\Tests\Fixtures\TemporaryStorage;

beforeEach(function (): void {
    $this->disk = new TemporaryStorage;
    $this->exports = new PrivacyExports($this->disk->storage, new MediaConfig('https://media.client.fr'));
});

it('hooks the three places of the personal data export, and the cleanup', function (): void {
    Filters\expectAdded('wp_privacy_exports_dir')->once();
    Filters\expectAdded('wp_privacy_exports_url')->once();
    Brain\Monkey\Actions\expectAdded('wp_privacy_personal_data_export_file_created')->once();
    Brain\Monkey\Actions\expectAdded('wp_privacy_delete_old_export_files')->once();

    $this->exports->register();
});

it('builds the archive in a local folder, because ZipArchive cannot write to a wrapper', function (): void {
    $dir = $this->exports->exportsDir('pollora://uploads/wp-personal-data-exports/');

    expect($dir)->toStartWith(sys_get_temp_dir())
        ->and($dir)->toEndWith('/')
        ->and(is_dir($dir))->toBeTrue()
        ->and($this->exports->exportsDir('/var/www/uploads/wp-personal-data-exports/'))->toBe('/var/www/uploads/wp-personal-data-exports/');
});

it('links the archive to the public URL of the library', function (): void {
    expect($this->exports->exportsUrl('https://site.test/content/uploads/wp-personal-data-exports/'))->toBe('https://media.client.fr/wp-personal-data-exports/');
});

it('copies the archive to the disk once it is made, and removes the local file', function (): void {
    $dir = $this->exports->exportsDir('pollora://uploads/wp-personal-data-exports/');
    $zip = $dir.'wp-personal-data-export-abc.zip';
    file_put_contents($zip, 'zip bytes');

    $this->exports->exportCreated($zip);

    expect(file_get_contents($this->disk->root.'/wp-personal-data-exports/wp-personal-data-export-abc.zip'))->toBe('zip bytes')
        ->and(file_exists($zip))->toBeFalse();
});

it('deletes the archives on the disk that are older than the expiry, and no other file', function (): void {
    Filters\expectApplied('wp_privacy_export_expiration')->andReturn(3600);
    $this->disk->storage->write('wp-personal-data-exports/old.zip', 'x');
    $this->disk->storage->write('wp-personal-data-exports/new.zip', 'x');
    $this->disk->storage->write('2026/photo.jpg', 'x');
    touch($this->disk->root.'/wp-personal-data-exports/old.zip', time() - 7200);

    $this->exports->deleteOld();

    expect(file_exists($this->disk->root.'/wp-personal-data-exports/old.zip'))->toBeFalse()
        ->and(file_exists($this->disk->root.'/wp-personal-data-exports/new.zip'))->toBeTrue()
        ->and(file_exists($this->disk->root.'/2026/photo.jpg'))->toBeTrue();
});
