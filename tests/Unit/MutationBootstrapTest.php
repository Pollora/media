<?php

declare(strict_types=1);

/**
 * Runs tests as Pest runs those of a mutant of one source file (see tests/bootstrap.php).
 *
 * @param  string  $source  The source file the mutant replaces, relative to the repository.
 * @param  string  $mutant  The code that stands for it.
 * @param  string  $filter  The tests to run.
 * @return array{int, string} The exit code and the output.
 */
function runAsMutant(string $source, string $mutant, string $filter): array
{
    $root = dirname(__DIR__, 2);
    // A temporary directory of its own: StreamWrapperTest counts the local copies left in the shared one
    $temporary = sys_get_temp_dir().'/pollora-media-mutant-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700);
    $file = $temporary.'/mutant.php';
    file_put_contents($file, $mutant);

    // Not those of the ParaTest worker this test may run in
    $environment = array_diff_key(getenv(), array_flip(['PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN', 'LARAVEL_PARALLEL_TESTING']));

    try {
        $process = proc_open(
            [PHP_BINARY, 'vendor/bin/pest', '--filter='.$filter],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $root,
            [...$environment, 'TMPDIR' => $temporary, 'PEST_MUTATION_TESTING' => $root.'/'.$source, 'PEST_MUTATION_FILE' => $file],
        );
        $output = (string) stream_get_contents($pipes[1]);

        return [proc_close($process), $output];
    } finally {
        exec('rm -rf '.escapeshellarg($temporary));
    }
}

// The tests that take exclusive locks and the one that makes a file unreadable fail under Pest's stream wrapper
it('lets the tests pass on unchanged code run as a mutant, and runs the mutant when it is changed', function (): void {
    $source = 'src/Domain/Exceptions/PrivacyExportFailedException.php';
    $code = (string) file_get_contents(dirname(__DIR__, 2).'/'.$source);

    [$status, $output] = runAsMutant($source, $code, 'ThumbnailServiceTest|PrivacyExportsTest');

    expect($status)->toBe(0, $output);

    [$status, $output] = runAsMutant($source, str_replace('cannot be read', 'cannot be opened', $code), 'PrivacyExportsTest');

    expect($status)->not->toBe(0, $output)->and($output)->toContain('cannot be opened');
});
