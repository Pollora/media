<?php

declare(strict_types=1);

namespace {
    // The module registers its commands only under WP-CLI; the fake stands for it in every test, in any order.
    if (! defined('WP_CLI')) {
        define('WP_CLI', true);
    }

    if (! class_exists('WP_CLI', false)) {
        /**
         * Stands for WP-CLI, which the unit tests do not run under: it records what a command says.
         * Like the real one, `error()` and `halt()` end the command: here, by throwing.
         */
        final class WP_CLI
        {
            /** @var list<array{string, string}> */
            public static array $output = [];

            public static function reset(): void
            {
                self::$output = [];
                self::$commands = [];
            }

            /** @return list<string> */
            public static function messages(?string $kind = null): array
            {
                $lines = array_filter(self::$output, static fn (array $entry): bool => $kind === null || $entry[0] === $kind);

                return array_values(array_map(static fn (array $entry): string => $entry[1], $lines));
            }

            /** @var array<string, object> */
            public static array $commands = [];

            public static function add_command(string $name, object $command): void
            {
                self::$commands[$name] = $command;
            }

            public static function log(string $message): void
            {
                self::$output[] = ['log', $message];
            }

            public static function line(string $message = ''): void
            {
                self::$output[] = ['line', $message];
            }

            public static function success(string $message): void
            {
                self::$output[] = ['success', $message];
            }

            public static function warning(string $message): void
            {
                self::$output[] = ['warning', $message];
            }

            public static function error(string $message): never
            {
                self::$output[] = ['error', $message];

                throw new RuntimeException('WP_CLI::error: '.$message);
            }

            public static function halt(int $code): never
            {
                self::$output[] = ['halt', (string) $code];

                throw new RuntimeException('WP_CLI::halt: '.$code);
            }
        }
    }
}
