<?php

declare(strict_types=1);

namespace SymSvel\Module\TwoFactor\Service;

/** Single-use recovery codes, adapted from the proven XCore implementation. */
final class RecoveryCodeService
{
    /** @return array{plain: list<string>, hashed: list<string>} */
    public function generate(int $count = 8): array
    {
        if ($count < 1 || $count > 20) {
            throw new \InvalidArgumentException('Invalid recovery code count.');
        }
        $plain = [];
        $hashed = [];
        for ($i = 0; $i < $count; ++$i) {
            $hex = strtoupper(bin2hex(random_bytes(5)));
            $code = substr($hex, 0, 5).'-'.substr($hex, 5);
            $plain[] = $code;
            $hashed[] = password_hash($code, PASSWORD_DEFAULT);
        }
        return ['plain' => $plain, 'hashed' => $hashed];
    }

    /** @param list<string> $hashes
     * @return list<string>|null
     */
    public function consume(string $code, array $hashes): ?array
    {
        if (!preg_match('/^[A-Fa-f0-9]{5}-[A-Fa-f0-9]{5}$/D', $code)) {
            return null;
        }
        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && password_verify(strtoupper($code), $hash)) {
                unset($hashes[$index]);
                return array_values($hashes);
            }
        }
        return null;
    }
}
