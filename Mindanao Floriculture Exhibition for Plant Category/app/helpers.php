<?php
declare(strict_types=1);

function db(): PDO
{
    global $db;
    return $db;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $page = 'dashboard', array $params = []): never
{
    $params = ['page' => $page] + $params;
    header('Location: index.php?' . http_build_query($params));
    exit;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $provided = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals(csrf_token(), $provided)) {
        http_response_code(400);
        exit('Invalid form token. Reload the page and try again.');
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function flash(string $message, string $kind = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
}

function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, username, display_name, role, active FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user && (int) $user['active'] === 1 ? $user : null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'super_admin') {
        http_response_code(403);
        exit('This action is restricted to the Super Administrator.');
    }
    return $user;
}

function is_admin(): bool
{
    return current_user()['role'] === 'super_admin';
}

function can_view_reports(array $user): bool
{
    if ($user['role'] === 'super_admin') {
        return true;
    }
    if ($user['role'] !== 'tabulator') {
        return false;
    }
    $stmt = db()->prepare('SELECT tabulator_permissions FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $permissions = json_decode((string) $stmt->fetchColumn(), true) ?: [];
    return in_array('reports', $permissions, true);
}

function audit(string $action, string $type, string|int $id = '', mixed $before = null, mixed $after = null, string $reason = ''): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, previous_value, new_value, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        current_user()['id'] ?? null,
        $action,
        $type,
        (string) $id,
        $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $reason,
    ]);
}

function stage_status(string $key): string
{
    $stmt = db()->prepare('SELECT status FROM stages WHERE stage_key = ?');
    $stmt->execute([$key]);
    return (string) ($stmt->fetchColumn() ?: 'not_started');
}

function stage_open(string $key): bool
{
    return stage_status($key) === 'in_progress';
}

function setting(string $key): string
{
    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    return (string) ($stmt->fetchColumn() ?: '');
}

function categories(bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM categories';
    if ($activeOnly) {
        $sql .= ' WHERE active = 1';
    }
    return db()->query($sql . ' ORDER BY position, id')->fetchAll();
}

function category_for_entry(int $entryId): ?array
{
    $stmt = db()->prepare(
        'SELECT c.* FROM entries e JOIN categories c ON c.id = e.category_id WHERE e.id = ?'
    );
    $stmt->execute([$entryId]);
    return $stmt->fetch() ?: null;
}

function can_judge_category(array $user, int $categoryId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM judge_assignments WHERE judge_id = ? AND category_id = ?');
    $stmt->execute([$user['id'], $categoryId]);
    return (bool) $stmt->fetchColumn();
}

function format_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d H:i');
    } catch (Throwable) {
        return $value;
    }
}

function safe_return_page(string $fallback = 'dashboard'): string
{
    $page = (string) ($_POST['return_page'] ?? $fallback);
    return preg_match('/^[a-z_]+$/', $page) ? $page : $fallback;
}

function rank_points(): array
{
    $points = json_decode(setting('consolidation_points'), true);
    if (!is_array($points) || count($points) !== 5) {
        return [5, 4, 3, 2, 1];
    }
    return array_map('intval', array_values($points));
}

function consolidated_scores(int $categoryId): array
{
    $method = setting('consolidation_method');
    $points = rank_points();
    $stmt = db()->prepare(
        'SELECT e.id, e.entry_number, COUNT(DISTINCT jr.evaluation_id) AS judge_count,
                GROUP_CONCAT(jr.rank) AS ranks
         FROM entries e
         JOIN judge_rankings jr ON jr.entry_id = e.id
         JOIN judge_evaluations je ON je.id = jr.evaluation_id AND je.status = \'submitted\'
         WHERE e.category_id = ? AND e.status = \'eligible\'
         GROUP BY e.id, e.entry_number'
    );
    $stmt->execute([$categoryId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $ranks = array_map('intval', explode(',', (string) $row['ranks']));
        $pointTotal = 0;
        foreach ($ranks as $rank) {
            $pointTotal += $points[$rank - 1] ?? 0;
        }
        $averageRank = array_sum($ranks) / count($ranks);
        $rows[] = [
            'id' => (int) $row['id'],
            'entry_number' => $row['entry_number'],
            'judge_count' => (int) $row['judge_count'],
            'score' => $method === 'average_rank' ? $averageRank : $pointTotal,
            'score_label' => $method === 'average_rank'
                ? number_format($averageRank, 2) . ' average rank'
                : $pointTotal . ' points',
            'average_rank' => $averageRank,
        ];
    }
    usort($rows, static function (array $a, array $b) use ($method): int {
        return $method === 'average_rank'
            ? $a['score'] <=> $b['score']
            : $b['score'] <=> $a['score'];
    });
    return $rows;
}
