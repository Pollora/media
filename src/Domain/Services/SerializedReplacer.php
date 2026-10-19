<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Services;

use stdClass;

/**
 * Replaces a string in a value WordPress stored, without breaking it: a
 * serialized value is read, changed and written again, so the lengths it
 * records stay right. Anything else is a plain replacement.
 *
 * A serialized value that holds an object of a class other than stdClass is
 * left untouched rather than risk waking an unknown class.
 */
final class SerializedReplacer
{
    public function replace(string $value, string $search, string $replace): string
    {
        if ($search === '' || ! str_contains($value, $search)) {
            return $value;
        }

        if ($this->looksSerialized($value)) {
            $data = @unserialize($value, ['allowed_classes' => [stdClass::class]]);

            if ($data !== false || $value === 'b:0;') {
                if ($this->holdsUnknownObject($data)) {
                    return $value;
                }

                return serialize($this->walk($data, $search, $replace));
            }
        }

        return str_replace($search, $replace, $value);
    }

    private function looksSerialized(string $value): bool
    {
        return strlen($value) > 3 && $value[1] === ':' && in_array($value[0], ['a', 'O', 's', 'i', 'b', 'd'], true) && (str_ends_with($value, ';') || str_ends_with($value, '}'));
    }

    private function walk(mixed $data, string $search, string $replace): mixed
    {
        if (is_string($data)) {
            return $this->replace($data, $search, $replace);
        }

        if (is_array($data)) {
            $out = [];
            foreach ($data as $key => $item) {
                $out[is_string($key) ? str_replace($search, $replace, $key) : $key] = $this->walk($item, $search, $replace);
            }

            return $out;
        }

        if ($data instanceof stdClass) {
            foreach (get_object_vars($data) as $key => $item) {
                $data->{$key} = $this->walk($item, $search, $replace);
            }
        }

        return $data;
    }

    private function holdsUnknownObject(mixed $data): bool
    {
        if (is_object($data) && ! $data instanceof stdClass) {
            return true;
        }

        $items = is_array($data) ? $data : ($data instanceof stdClass ? get_object_vars($data) : []);

        foreach ($items as $item) {
            if ($this->holdsUnknownObject($item)) {
                return true;
            }
        }

        return false;
    }
}
