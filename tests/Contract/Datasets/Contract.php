<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

if (! function_exists('contractCase')) {
    /**
     * Runs the assertions of one contract case for one adapter, honouring the differences nobody has decided yet.
     *
     * `$knownDeviations` maps `'adapter|case'` to the reason the adapter does not honour the case. A listed case is not
     * weakened, it still runs: while it fails the test is reported as skipped with the reason, so the difference stays in
     * every run; the day it passes the test FAILS and asks for the line to be deleted, so a difference that was fixed
     * cannot hide in the list. An adapter that is not listed fails the contract like any test.
     *
     * @param  array<string, string>  $knownDeviations
     * @param  Closure(): void  $assertions
     */
    function contractCase(array $knownDeviations, string $adapter, string $case, Closure $assertions): void
    {
        $reason = $knownDeviations[$adapter.'|'.$case] ?? null;

        try {
            $assertions();
        } catch (Throwable $throwable) {
            if ($reason === null) {
                throw $throwable;
            }

            test()->markTestSkipped('KNOWN DEVIATION: '.$reason);
        }

        if ($reason !== null) {
            Assert::fail(sprintf('"%s" for "%s" now passes: delete its line from the known deviations.', $case, $adapter));
        }
    }
}
