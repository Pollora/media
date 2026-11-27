<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Pollora\Media\Domain\Exceptions\PrivacyExportFailedException;
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

it('puts nothing on the disk for an archive it cannot read', function (): void {
    $zip = $this->exports->exportsDir('pollora://uploads/wp-personal-data-exports/').'wp-personal-data-export-locked.zip';
    file_put_contents($zip, 'zip bytes');
    chmod($zip, 0000);

    try {
        expect(fn (): mixed => $this->exports->exportCreated($zip))
            ->toThrow(PrivacyExportFailedException::class, 'wp-personal-data-export-locked.zip cannot be read');
        expect(file_exists($this->disk->root.'/wp-personal-data-exports/wp-personal-data-export-locked.zip'))->toBeFalse();
    } finally {
        chmod($zip, 0600);
        unlink($zip);
    }
});

it('keeps an export for three days unless a filter says otherwise', function (): void {
    foreach (['recent.zip' => 3 * 86400 - 30, 'expired.zip' => 3 * 86400 + 3600] as $file => $age) {
        $this->disk->storage->write('wp-personal-data-exports/'.$file, 'x');
        touch($this->disk->root.'/wp-personal-data-exports/'.$file, time() - $age);
    }

    $this->exports->deleteOld();

    expect($this->disk->storage->exists('wp-personal-data-exports/recent.zip'))->toBeTrue()
        ->and($this->disk->storage->exists('wp-personal-data-exports/expired.zip'))->toBeFalse();
});

it('deletes old archives only: not the index WordPress puts in the folder, however old', function (): void {
    $this->disk->storage->write('wp-personal-data-exports/index.php', '<?php');
    touch($this->disk->root.'/wp-personal-data-exports/index.php', time() - 30 * 86400);

    $this->exports->deleteOld();

    expect($this->disk->storage->exists('wp-personal-data-exports/index.php'))->toBeTrue();
});

it('keeps the folder the archives are built in closed to the other users of the server', function (): void {
    // Another process, with a temporary directory of its own: the folder of this one may exist already
    $tmp = $this->disk->root.'/tmp';
    mkdir($tmp);
    $script = sprintf(
        'require %s; $e = new Pollora\\Media\\Infrastructure\\WordPress\\PrivacyExports((new Pollora\\Media\\Tests\\Fixtures\\TemporaryStorage)->storage, new Pollora\\Media\\Domain\\Models\\MediaConfig("https://media.test")); $d = $e->exportsDir("pollora://uploads/wp-personal-data-exports/"); clearstatcache(); printf("%%o", fileperms($d) & 0777);',
        var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true),
    );

    exec(sprintf('%s -d error_reporting=%d -d sys_temp_dir=%s -r %s 2>&1', escapeshellarg(PHP_BINARY), E_ERROR, escapeshellarg($tmp), escapeshellarg($script)), $output, $status);

    expect($status)->toBe(0, implode("\n", $output))->and(implode('', $output))->toBe('700');
});
