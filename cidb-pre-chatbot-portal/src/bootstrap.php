<?php
declare(strict_types=1);

function portal_env(): array
{
    static $env;
    if (is_array($env)) return $env;
    $env = [];
    $path = dirname(__DIR__) . '/.env';
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim(trim($value), "\"'");
        }
    }
    return $env;
}
function env_value(string $key, string $default = ''): string { return (string)(portal_env()[$key] ?? getenv($key) ?: $default); }
function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d', env_value('DB_HOST','127.0.0.1'), (int)env_value('DB_PORT','5432'), env_value('DB_DATABASE'), max(1,(int)env_value('DB_TIMEOUT','5')));
    return $pdo = new PDO($dsn, env_value('DB_USERNAME'), env_value('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
}
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_valid(): bool { return isset($_POST['_csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['_csrf']); }
function redirect(string $path): never
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Location: ' . $path, true, 303);
    exit;
}
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function new_uuid(): string { $b=random_bytes(16); $b[6]=chr((ord($b[6])&0x0f)|0x40); $b[8]=chr((ord($b[8])&0x3f)|0x80); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
function require_login(): void { if (!current_user()) redirect('/login'); }
function flash(string $kind, string $message): void { $_SESSION['flash'] = compact('kind','message'); }
function take_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }

function render(string $view, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require dirname(__DIR__) . '/views/' . $view . '.php';
}
