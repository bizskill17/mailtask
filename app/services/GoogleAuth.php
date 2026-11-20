<?php
declare(strict_types=1);

/** Signs a one-hour RS256 assertion and caches the exchanged delegated token per mailbox. */
final class GoogleAuth
{
    public const SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';
    private const ENDPOINT = 'https://oauth2.googleapis.com/token';

    public function __construct(private Vault $vault, private Http $http, private string $domain)
    {
    }

    public function token(string $mailbox, bool $refresh = false): string
    {
        $mailbox = strtolower(trim($mailbox));
        if (!filter_var($mailbox, FILTER_VALIDATE_EMAIL) || strcasecmp(substr(strrchr($mailbox, '@'), 1), $this->domain) !== 0) {
            throw new RuntimeException('Use a primary Google Workspace mailbox in the configured domain.');
        }
        $raw = $this->vault->read('google-service-account');
        $account = json_decode($raw ?? 'null', true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($account)) {
            throw new RuntimeException('Import the service-account JSON first.');
        }
        $cacheKey = 'token-' . hash('sha256', $mailbox . "\n" . $raw);
        $now = time();
        $cached = json_decode($this->vault->read($cacheKey) ?? 'null', true);
        if (!$refresh && is_string($cached['access_token'] ?? null) && ($cached['expires_at'] ?? 0) > $now + 90) {
            return $cached['access_token'];
        }
        $jwt = self::assertion($account, $mailbox, $now);
        $reply = $this->http->json('POST', self::ENDPOINT, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]));
        if (!is_string($reply['access_token'] ?? null) || $reply['access_token'] === '' || (int) ($reply['expires_in'] ?? 0) < 1) {
            throw new RuntimeException('Google did not return a usable access token.');
        }
        $this->vault->write($cacheKey, json_encode([
            'access_token' => $reply['access_token'],
            'expires_at' => $now + min(3600, (int) $reply['expires_in']),
        ], JSON_THROW_ON_ERROR));
        return $reply['access_token'];
    }

    public static function assertion(array $account, string $mailbox, int $now): string
    {
        $privateKey = openssl_pkey_get_private($account['private_key'] ?? '');
        if (($account['type'] ?? null) !== 'service_account' || !filter_var($account['client_email'] ?? '', FILTER_VALIDATE_EMAIL)
            || $privateKey === false || openssl_pkey_get_details($privateKey)['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException('Invalid Google service-account RSA key.');
        }
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        if (isset($account['private_key_id'])) {
            $header['kid'] = $account['private_key_id'];
        }
        $claims = [
            'iss' => $account['client_email'],
            'sub' => $mailbox,
            'scope' => self::SCOPE,
            'aud' => self::ENDPOINT,
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $unsigned = self::encode(json_encode($header, JSON_THROW_ON_ERROR)) . '.' . self::encode(json_encode($claims, JSON_THROW_ON_ERROR));
        if (!openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign service-account assertion.');
        }
        return $unsigned . '.' . self::encode($signature);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
