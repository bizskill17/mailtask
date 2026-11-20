<?php
declare(strict_types=1);

/** Authenticated encryption for credentials and cached OAuth tokens. */
final class Vault
{
    private string $key;

    public function __construct(private string $directory, string $encodedKey)
    {
        $key = base64_decode($encodedKey, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Set a valid encryption key in config.php.');
        }
        $this->key = $key;
    }

    public function read(string $name): ?string
    {
        $path = $this->path($name);
        if (!is_file($path)) {
            return null;
        }
        $data = file_get_contents($path);
        $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if ($data === false || strlen($data) < $nonceLength + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new RuntimeException('Encrypted credential is unreadable.');
        }
        $plain = sodium_crypto_secretbox_open(substr($data, $nonceLength), substr($data, 0, $nonceLength), $this->key);
        if ($plain === false) {
            throw new RuntimeException('Encrypted credential failed authentication.');
        }
        return $plain;
    }

    public function write(string $name, string $plain): void
    {
        $path = $this->path($name);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = $nonce . sodium_crypto_secretbox($plain, $nonce, $this->key);
        $temp = tempnam($this->directory, '.secret-');
        if ($temp === false) {
            throw new RuntimeException('Cannot create encrypted credential file.');
        }
        try {
            if (!chmod($temp, 0600) || file_put_contents($temp, $ciphertext) !== strlen($ciphertext) || !rename($temp, $path)) {
                throw new RuntimeException('Cannot save encrypted credential.');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    private function path(string $name): string
    {
        if (!preg_match('/\A[a-z0-9-]+\z/D', $name) || !is_dir($this->directory)) {
            throw new RuntimeException('Invalid credential path. Check the private secrets directory.');
        }
        return $this->directory . '/' . $name . '.enc';
    }
}
