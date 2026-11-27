<?php
declare(strict_types=1);

// Fixture-only PDO. Every SQL statement outside the two authentication reads
// throws; HTTP tests therefore fail if the testing page attempts workflow writes.
final class OcrFixturePdo extends PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new OcrFixtureStatement($query);
    }
}
final class OcrFixtureStatement extends PDOStatement
{
    private mixed $record = false;
    public function __construct(private string $sql) {}
    public function execute(?array $params = null): bool
    {
        file_put_contents(dirname(__DIR__) . '/sql.log', $this->sql . "\n", FILE_APPEND);
        $accounts = json_decode(file_get_contents(dirname(__DIR__) . '/accounts.json'), true, 512, JSON_THROW_ON_ERROR);
        if (str_contains($this->sql, 'SELECT id, username, password_hash FROM portal_users')) {
            $user = $accounts[$params['username']] ?? null;
            $this->record = $user && $user['active'] ? $user : false;
        } elseif (str_starts_with($this->sql, 'SELECT role FROM portal_users WHERE id = ? AND is_active = true')) {
            $this->record = false;
            foreach ($accounts as $user) if ($user['id'] === $params[0] && $user['active']) $this->record = $user['role'];
        } else {
            throw new RuntimeException('Unexpected database access in isolated OCR testing.');
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->record; }
    public function fetchColumn(int $column = 0): mixed { return $this->record; }
}
