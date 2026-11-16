<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . DIRECTORY_SEPARATOR . '..';
const STORAGE_DIR = APP_ROOT . DIRECTORY_SEPARATOR . 'storage';

if (!is_dir(STORAGE_DIR) && !mkdir(STORAGE_DIR, 0700, true) && !is_dir(STORAGE_DIR)) {
    throw new RuntimeException('Could not create the private storage directory.');
}

$configuredDatabasePath = getenv('FLORICULTURE_DB_PATH');
if ($configuredDatabasePath !== false && trim($configuredDatabasePath) !== '') {
    $configuredDatabasePath = trim($configuredDatabasePath);
    if (!is_file($configuredDatabasePath)) {
        throw new RuntimeException('FLORICULTURE_DB_PATH must point to an existing SQLite database file.');
    }
    define('DB_FILE', $configuredDatabasePath);
} else {
    define('DB_FILE', STORAGE_DIR . DIRECTORY_SEPARATOR . 'competition.sqlite');
}

$db = new PDO('sqlite:' . DB_FILE, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('PRAGMA busy_timeout = 5000');
$db->exec((string) file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'schema.sql'));

$categories = [
    ['anthurium-foliage', 'Anthurium Foliage', 'ANTH', 1],
    ['aglaonema', 'Aglaonema', 'AGL', 2],
    ['cactus-succulent', 'Cactus and Succulent', 'CS', 3],
    ['fern', 'Fern', 'FER', 4],
    ['flowering-plants', 'Flowering Plants', 'FLW', 5],
    ['variegated-plants', 'Variegated Plants', 'VAR', 6],
    ['mixed-genera', 'Mixed Genera', 'MG', 7],
    ['philodendron', 'Philodendron', 'PHI', 8],
];
$insertCategory = $db->prepare(
    'INSERT OR IGNORE INTO categories (slug, name, prefix, position) VALUES (?, ?, ?, ?)'
);
foreach ($categories as $category) {
    $insertCategory->execute($category);
}

$stageLabels = [
    'registration' => 'Registration',
    'verification' => 'Entry verification',
    'judge_setup' => 'Judge account preparation',
    'judging' => 'Individual judge selection',
    'judge_submissions' => 'Judge submission completion',
    'finalist_consolidation' => 'Finalist consolidation',
    'finalist_confirmation' => 'Finalist confirmation',
    'deliberation' => 'Category deliberation',
    'final_ranking' => 'Final category ranking',
    'best_in_show' => 'Best in Show selection',
    'approval' => 'Final result approval',
    'publication' => 'Public result publication',
];
$insertStage = $db->prepare('INSERT OR IGNORE INTO stages (stage_key, label) VALUES (?, ?)');
foreach ($stageLabels as $key => $label) {
    $insertStage->execute([$key, $label]);
}

$db->exec(
    "INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES
    ('consolidation_method', 'points_5_to_1'),
    ('consolidation_points', '[5,4,3,2,1]'),
    ('best_in_show_method', 'administrator_decision'),
    ('public_owner_names', '0'),
    ('published', '0')"
);

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_name('floriculture_session');
session_start();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; form-action 'self'; frame-ancestors 'none'");

require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers.php';
