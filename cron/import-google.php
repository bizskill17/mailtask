<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';
umask(0077);
try {
    $config = config();
    $vault = new Vault($config['secrets_dir'], $config['encryption_key']);
    $raw = stream_get_contents(STDIN, 65537);
    if ($raw === false || strlen($raw) > 65536) {
        throw new RuntimeException('Invalid service-account JSON size.');
    }
    $account = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    GoogleAuth::assertion($account, 'validation@' . $config['allowed_domain'], time());
    $vault->write('google-service-account', $raw);
    echo "Encrypted Google service-account key installed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Import failed. Verify the private config, directory permissions and downloaded Google JSON.\n");
    exit(1);
}
