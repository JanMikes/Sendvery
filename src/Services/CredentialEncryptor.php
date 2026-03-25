<?php

declare(strict_types=1);

namespace App\Services;

use ParagonIE\Halite\Symmetric\Crypto;
use ParagonIE\Halite\Symmetric\EncryptionKey;
use ParagonIE\HiddenString\HiddenString;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CredentialEncryptor
{
    private const string HALITE_PREFIX = 'halite:';

    private EncryptionKey $haliteKey;
    private string $legacySodiumKey;

    public function __construct(
        #[Autowire(env: 'ENCRYPTION_KEY')]
        string $encryptionKey,
    ) {
        $rawKey = base64_decode($encryptionKey);
        $this->legacySodiumKey = $rawKey;
        $this->haliteKey = new EncryptionKey(new HiddenString($rawKey));
    }

    public function encrypt(string $plaintext): string
    {
        $ciphertext = Crypto::encrypt(
            new HiddenString($plaintext),
            $this->haliteKey,
        );

        return self::HALITE_PREFIX.$ciphertext;
    }

    public function decrypt(string $encrypted): string
    {
        // Halite-encrypted values have our prefix
        if (str_starts_with($encrypted, self::HALITE_PREFIX)) {
            $ciphertext = substr($encrypted, strlen(self::HALITE_PREFIX));

            return Crypto::decrypt($ciphertext, $this->haliteKey)->getString();
        }

        // Legacy sodium_crypto_secretbox format (backward compatible)
        return $this->decryptLegacy($encrypted);
    }

    /**
     * Re-encrypts a value from legacy sodium format to Halite format.
     * Returns null if the value is already in Halite format.
     */
    public function reEncrypt(string $encrypted): ?string
    {
        if (str_starts_with($encrypted, self::HALITE_PREFIX)) {
            return null;
        }

        $plaintext = $this->decryptLegacy($encrypted);

        return $this->encrypt($plaintext);
    }

    private function decryptLegacy(string $encrypted): string
    {
        $decoded = base64_decode($encrypted);
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->legacySodiumKey);

        if (false === $plaintext) {
            throw new \RuntimeException('Decryption failed — invalid key or corrupted data.');
        }

        return $plaintext;
    }
}
