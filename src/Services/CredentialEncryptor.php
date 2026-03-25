<?php

declare(strict_types=1);

namespace App\Services;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly final class CredentialEncryptor
{
    private string $key;

    public function __construct(
        #[Autowire(env: 'ENCRYPTION_KEY')]
        string $encryptionKey,
    ) {
        $this->key = base64_decode($encryptionKey);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $encrypted): string
    {
        $decoded = base64_decode($encrypted);
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed — invalid key or corrupted data.');
        }

        return $plaintext;
    }
}
