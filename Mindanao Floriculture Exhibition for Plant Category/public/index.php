<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$page = (string) ($_GET['page'] ?? 'dashboard');
$user = current_user();

if ($page === 'photo') {
    $user = require_login();
    if ($user['role'] !== 'super_admin') {
        http_response_code(403);
        exit('Not authorized.');
    }
    $stmt = db()->prepare('SELECT photo_path FROM entries WHERE id = ?');
    $stmt->execute([(int) ($_GET['id'] ?? 0)]);
    $path = (string) $stmt->fetchColumn();
    $fullPath = STORAGE_DIR . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($path);
    if (!$path || !is_file($fullPath)) {
        http_response_code(404);
        exit('Photo not found.');
    }
    $mime = mime_content_type($fullPath);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        http_response_code(415);
        exit('Unsupported image type.');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($fullPath));
    readfile($fullPath);
    exit;
}

if ($page === 'export') {
    $user = require_login();
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'reports.php';
    send_export($user, (string) ($_GET['report'] ?? ''));
}

if ($page === 'print_report') {
    $user = require_login();
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'reports.php';
    render_print_report($user, (string) ($_GET['report'] ?? ''));
}

if ($page === 'public') {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'views.php';
    render_public();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'actions.php';
    handle_action((string) ($_POST['action'] ?? ''), $user);
}

if ($page === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    redirect('login');
}

if (!$user && !in_array($page, ['login'], true)) {
    redirect('login');
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'views.php';
render_page($page, $user);
