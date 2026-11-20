<?php

declare(strict_types=1);

namespace SymSvel\Module\TwoFactor\Service;

/** RFC 6238 compatible TOTP (SHA1, 6 digits, 30 seconds), adapted from XCore. */
final class TotpService
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        return $this->matchingCounter($secret, $code, $timestamp ?? time()) !== null;
    }

    /** Return matching counter so a confirmed TOTP cannot be reused. */
    public function matchingCounter(string $secret, string $code, int $timestamp, int $window = 1): ?int
    {
        if (!preg_match('/^[0-9]{6}$/D', $code) || $window < 0 || $window > 2 || $timestamp < 0) {
            return null;
        }
        $key = $this->base32Decode($secret);
        $counter = intdiv($timestamp, 30);
        for ($i = -$window; $i <= $window; ++$i) {
            if ($counter + $i >= 0 && hash_equals($this->code($key, $counter + $i), $code)) {
                return $counter + $i;
            }
        }
        return null;
    }

    public function provisioningUri(string $secret, string $account, string $issuer = 'SymSvel'): string
    {
        return sprintf('otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer.':'.$account), rawurlencode($secret), rawurlencode($issuer));
    }

    private function code(string $key, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $encoded;
    }

    private function base32Decode(string $data): string
    {
        if (!preg_match('/^[A-Z2-7]{32}$/D', $data)) {
            throw new \InvalidArgumentException('Invalid TOTP secret.');
        }
        $bits = '';
        foreach (str_split($data) as $char) {
            $position = strpos(self::ALPHABET, $char);
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }
        return $decoded;
    }
}
