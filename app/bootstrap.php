<?php
declare(strict_types=1);

define('MAILTASK_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    if (preg_match('/\A[A-Za-z]+\z/D', $class)) {
        foreach (['services', 'controllers', 'models'] as $directory) {
            $path = __DIR__ . "/$directory/$class.php";
            if (is_file($path)) {
                require $path;
                return;
            }
        }
    }
});

function requireCli(): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
}

function config(): array
{
    $path = MAILTASK_ROOT . '/config/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Create config/config.php from config.sample.php first.');
    }
    return require $path;
}
