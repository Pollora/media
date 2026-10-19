<?php

declare(strict_types=1);

namespace Pollora\Media\Domain\Services;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Signs the path of an imgproxy URL: an HMAC-SHA256 of the salt followed by the path, with the key,
 * truncated to the signature size and encoded in URL-safe base64 without padding. The key and the
 * salt are the hexadecimal strings of IMGPROXY_KEY and IMGPROXY_SALT.
 *
 * @see https://docs.imgproxy.net/latest/usage/signing_url
 */
final readonly class ImgproxySigner
{
    private string $key;

    private string $salt;

    /**
     * @param  int  $size  Bytes of the HMAC kept, as IMGPROXY_SIGNATURE_SIZE (1 to 32)
     *
     * @throws InvalidArgumentException When the key or the salt is not hexadecimal, or the size is out of range
     */
    public function __construct(#[SensitiveParameter] string $keyHex, #[SensitiveParameter] string $saltHex, private int $size = 32)
    {
        $this->key = self::binary($keyHex, 'key');
        $this->salt = self::binary($saltHex, 'salt');

        if ($size < 1 || $size > 32) {
            throw new InvalidArgumentException('The imgproxy signature size must be between 1 and 32 bytes.');
        }
    }

    /**
     * @param  string  $path  What follows the signature in the URL, with its leading slash
     */
    public function sign(string $path): string
    {
        $digest = substr(hash_hmac('sha256', $this->salt.$path, $this->key, true), 0, $this->size);

        return rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }

    private static function binary(string $hex, string $what): string
    {
        if ($hex === '' || strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException(sprintf('The imgproxy %s must be a non-empty hexadecimal string, as IMGPROXY_%s.', $what, strtoupper($what)));
        }

        return (string) hex2bin($hex);
    }
}
