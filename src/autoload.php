<?php
/**
 * 汽水音乐工具库 —— 极简自动加载
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Qsm\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
