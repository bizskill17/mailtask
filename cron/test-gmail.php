<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';
umask(0077);
try {
    if ($argc !== 2 || !filter_var($argv[1], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Usage: php cron/test-gmail.php user@example.com');
    }
    $config = config();
    $http = new Http();
    $auth = new GoogleAuth(new Vault($config['secrets_dir'], $config['encryption_key']), $http, $config['allowed_domain']);
    $gmail = new GmailClient($auth, $http, $argv[1]);
    $profile = $gmail->profile();
    if (strcasecmp($profile['emailAddress'] ?? '', $argv[1]) !== 0) {
        throw new RuntimeException('Gmail returned a different primary mailbox.');
    }
    $list = $gmail->inbox(time() - 3 * 86400, 5);
    $items = $list['messages'] ?? [];
    echo "Mailbox authenticated. Recent INBOX messages returned: " . count($items) . ".\n";
    if ($items === []) {
        echo "Send a test message to this mailbox and rerun to verify message retrieval.\n";
        exit(2);
    }
    foreach ($items as $item) {
        $message = $gmail->message($item['id'], false);
        if (($message['id'] ?? null) !== $item['id'] || !isset($message['payload'])) {
            throw new RuntimeException('Gmail did not return a complete message payload.');
        }
        $labels = $message['labelIds'] ?? [];
        echo in_array('UNREAD', $labels, true) ? "Retrieved unread message.\n" : "Retrieved read message.\n";
    }
    echo "Phase 1 live Gmail check passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Gmail check failed: ' . ($error instanceof RuntimeException ? $error->getMessage() : 'Check the PHP extensions and private configuration.') . "\n");
    exit(1);
}
