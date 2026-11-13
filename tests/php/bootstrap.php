<?php

declare(strict_types=1);

// Composer's autoloader when installed; otherwise a PSR-4 loader for the core and the tests (used with the PHPUnit PHAR).
$root = dirname(__DIR__, 2);
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
    return;
}
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['MonteFiskal\\Tests\\' => '/tests/php/', 'MonteFiskal\\' => '/src/php/montefiskal-core/src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
