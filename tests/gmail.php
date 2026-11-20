<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function decodeJwtPart(string $part): string
{
    return base64_decode(strtr($part, '-_', '+/'), true);
}

$dir = sys_get_temp_dir() . '/mailtask-test-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
try {
    $vault = new Vault($dir, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    check(openssl_pkey_export($key, $privateKey), 'Could not generate fixture key');
    $vault->write('google-service-account', json_encode([
        'type' => 'service_account', 'client_email' => 'fixture@example.org', 'private_key' => $privateKey,
    ], JSON_THROW_ON_ERROR));
    check(!str_contains(file_get_contents($dir . '/google-service-account.enc'), 'PRIVATE KEY'), 'Credential leaked');
    $http = new class(openssl_pkey_get_details($key)['key']) extends Http {
        public int $exchanges = 0;
        public function __construct(private string $publicKey)
        {
        }

        public function json(string $method, string $url, array $headers = [], ?string $data = null): array
        {
            if ($url === 'https://oauth2.googleapis.com/token') {
                check($method === 'POST', 'Unexpected token method');
                parse_str($data ?? '', $form);
                [$header, $claims, $signature] = explode('.', $form['assertion']);
                check(json_decode(decodeJwtPart($header), true)['alg'] === 'RS256', 'Wrong JWT algorithm');
                check(openssl_verify($header . '.' . $claims, decodeJwtPart($signature), $this->publicKey, OPENSSL_ALGO_SHA256) === 1, 'Invalid signature');
                $claims = json_decode(decodeJwtPart($claims), true);
                check($claims['sub'] === 'staff@example.com', 'Wrong delegated subject');
                check($claims['scope'] === GoogleAuth::SCOPE, 'Wrong scope');
                check($claims['aud'] === $url && $claims['exp'] - $claims['iat'] === 3600, 'Wrong token audience or lifetime');
                $this->exchanges++;
                return ['access_token' => 'fixture-token', 'expires_in' => 3600];
            }
            check($method === 'GET' && $headers === ['Authorization: Bearer fixture-token'], 'Incorrect Gmail authorization');
            $path = parse_url($url, PHP_URL_PATH);
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            return match ($path) {
                '/gmail/v1/users/me/profile' => ['emailAddress' => 'staff@example.com', 'historyId' => '12345'],
                '/gmail/v1/users/me/messages' => self::listFixture($query),
                '/gmail/v1/users/me/messages/read' => ['id' => 'read', 'labelIds' => ['INBOX'], 'payload' => []],
                '/gmail/v1/users/me/messages/unread' => ['id' => 'unread', 'labelIds' => ['INBOX', 'UNREAD'], 'payload' => []],
                '/gmail/v1/users/me/history' => ['historyId' => $query['startHistoryId']],
                default => throw new RuntimeException('Unexpected Gmail endpoint'),
            };
        }

        private static function listFixture(array $query): array
        {
            check($query === ['labelIds' => 'INBOX', 'q' => 'after:123', 'maxResults' => '5', 'pageToken' => 'next'], 'Incorrect inbox filters');
            return ['messages' => [['id' => 'read'], ['id' => 'unread']]];
        }
    };

    $auth = new GoogleAuth($vault, $http, 'example.com');
    $gmail = new GmailClient($auth, $http, 'staff@example.com');
    check($gmail->profile()['historyId'] === '12345', 'Profile failed');
    $list = $gmail->inbox(123, 5, 'next')['messages'];
    check(count($list) === 2, 'Read/unread list failed');
    check($gmail->message($list[0]['id'], false)['labelIds'] === ['INBOX'], 'Read message failed');
    check(in_array('UNREAD', $gmail->message($list[1]['id'])['labelIds'], true), 'Unread message failed');
    check($gmail->history('12345')['historyId'] === '12345', 'History query failed');
    check($http->exchanges === 1, 'Token cache failed');
    check($auth->token('staff@example.com', true) === 'fixture-token' && $http->exchanges === 2, 'Forced token refresh failed');
    try {
        $auth->token('staff@example.org');
        throw new LogicException('Unexpected external mailbox access');
    } catch (RuntimeException $expected) {
        check($http->exchanges === 2, 'Exchanged a token for an external mailbox');
    }
    echo "Phase 1 fixture tests passed.\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);
}
