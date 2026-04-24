<?php

declare(strict_types=1);

function appLoadEnv(?string $rootDir = null): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $loaded = true;
    $root = $rootDir ?? dirname(__DIR__);
    $envPath = rtrim($root, '/\\') . '/.env';
    if (!is_file($envPath)) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));
        if ($key === '') {
            continue;
        }

        $first = $value[0] ?? '';
        $last = $value[strlen($value) - 1] ?? '';
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) !== false && getenv($key) !== '') {
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function appEnv(string $key, string $default = ''): string
{
    appLoadEnv();
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return (string)$value;
}

