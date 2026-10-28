<?php
declare(strict_types=1);

require_once __DIR__.'/Contracts.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'Cidb\\Email\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__.'/'.substr($class, strlen($prefix)).'.php';
        if (is_file($file)) require_once $file;
    }
});
