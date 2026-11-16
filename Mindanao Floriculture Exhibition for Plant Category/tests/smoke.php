<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'actions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'views.php';

$pdo = db();
if ((int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() !== 8) {
    throw new RuntimeException('The initial database must contain exactly eight categories.');
}
$seedCategories = $pdo->query('SELECT * FROM categories ORDER BY position')->fetchAll();
$seedStages = $pdo->query('SELECT * FROM stages ORDER BY rowid')->fetchAll();
$seedSettings = $pdo->query('SELECT * FROM settings')->fetchAll();

$GLOBALS['db'] = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$GLOBALS['db']->exec('PRAGMA foreign_keys = ON');
$GLOBALS['db']->exec((string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'schema.sql'));
$categoryInsert = db()->prepare('INSERT INTO categories (id, slug, name, prefix, active, position) VALUES (?, ?, ?, ?, ?, ?)');
foreach ($seedCategories as $category) {
    $categoryInsert->execute([
        $category['id'], $category['slug'], $category['name'],
        $category['prefix'], $category['active'], $category['position'],
    ]);
}
$stageInsert = db()->prepare('INSERT INTO stages (stage_key, label, status) VALUES (?, ?, ?)');
foreach ($seedStages as $stage) {
    $stageInsert->execute([$stage['stage_key'], $stage['label'], $stage['status']]);
}
$settingInsert = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
foreach ($seedSettings as $setting) {
    $settingInsert->execute([$setting['setting_key'], $setting['setting_value']]);
}

$pdo = db();
$categoryId = (int) $pdo->query('SELECT id FROM categories ORDER BY position LIMIT 1')->fetchColumn();
$oldJudgingStage = stage_status('judging');
$judgeId = 0;
try {
    $pdo->prepare('UPDATE stages SET status = ? WHERE stage_key = ?')->execute(['in_progress', 'registration']);
    $pdo->prepare('INSERT INTO users (username, display_name, password_hash, role) VALUES (?, ?, ?, ?)')
        ->execute(['admin-smoke-test', 'Admin Smoke Test', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'super_admin']);
    $adminId = (int) $pdo->lastInsertId();
    $_SESSION['user_id'] = $adminId;

    $quickCategories = categories(true);
    $quickCounts = [];
    foreach ($quickCategories as $quickCategory) {
        $quickCounts[(string) $quickCategory['id']] = '0';
    }
    $quickCategoryId = (int) $quickCategories[0]['id'];
    $secondQuickCategoryId = (int) $quickCategories[1]['id'];
    $quickCounts[(string) $quickCategoryId] = '2';
    $quickCounts[(string) $secondQuickCategoryId] = '1';
    $_POST = ['counts' => $quickCounts, 'confirm_received' => '1'];
    quick_register_entries();

    $quickPrefixStmt = $pdo->prepare('SELECT prefix FROM categories WHERE id = ?');
    $quickPrefixStmt->execute([$quickCategoryId]);
    $quickPrefix = (string) $quickPrefixStmt->fetchColumn();
    $quickEntriesStmt = $pdo->prepare(
        'SELECT entry_number, description, owner_name FROM entries WHERE category_id = ? ORDER BY entry_number'
    );
    $quickEntriesStmt->execute([$quickCategoryId]);
    $quickEntries = $quickEntriesStmt->fetchAll();
    if (
        count($quickEntries) !== 2 ||
        $quickEntries[0]['entry_number'] !== $quickPrefix . '-001' ||
        $quickEntries[1]['entry_number'] !== $quickPrefix . '-002' ||
        $quickEntries[0]['description'] !== 'Plant details pending' ||
        $quickEntries[0]['owner_name'] !== 'Owner details pending'
    ) {
        throw new RuntimeException('Quick intake did not create sequential pending entries.');
    }
    $secondCategoryCountStmt = $pdo->prepare('SELECT COUNT(*) FROM entries WHERE category_id = ?');
    $secondCategoryCountStmt->execute([$secondQuickCategoryId]);
    if ((int) $secondCategoryCountStmt->fetchColumn() !== 1) {
        throw new RuntimeException('Quick intake did not add the requested count to the second category.');
    }

    $quickCounts[(string) $quickCategoryId] = '501';
    $_POST = ['counts' => $quickCounts, 'confirm_received' => '1'];
    $invalidQuickCountRejected = false;
    try {
        quick_register_entries();
    } catch (RuntimeException) {
        $invalidQuickCountRejected = true;
    }
    $quickCategoryCountStmt = $pdo->prepare('SELECT COUNT(*) FROM entries WHERE category_id = ?');
    $quickCategoryCountStmt->execute([$quickCategoryId]);
    if (!$invalidQuickCountRejected || (int) $quickCategoryCountStmt->fetchColumn() !== 2) {
        throw new RuntimeException('Quick intake accepted an out-of-range category count.');
    }

    $deleteQuickEntry = $pdo->prepare('SELECT id FROM entries WHERE entry_number = ?');
    $deleteQuickEntry->execute([$quickPrefix . '-002']);
    $_POST = ['entry_id' => (int) $deleteQuickEntry->fetchColumn(), 'reason' => 'Correct excess quick-intake count'];
    delete_entry();
    $quickCategoryCountStmt->execute([$quickCategoryId]);
    $reservationStmt = $pdo->prepare('SELECT COUNT(*) FROM entry_number_reservations WHERE entry_number = ?');
    $reservationStmt->execute([$quickPrefix . '-002']);
    if ((int) $quickCategoryCountStmt->fetchColumn() !== 1 || (int) $reservationStmt->fetchColumn() !== 1) {
        throw new RuntimeException('Deleting an entry did not preserve its number reservation.');
    }

    foreach ($quickCategories as $quickCategory) {
        $quickCounts[(string) $quickCategory['id']] = '0';
    }
    $quickCounts[(string) $quickCategoryId] = '1';
    $_POST = ['counts' => $quickCounts, 'confirm_received' => '1'];
    quick_register_entries();
    $thirdNumberStmt = $pdo->prepare('SELECT COUNT(*) FROM entries WHERE entry_number = ?');
    $thirdNumberStmt->execute([$quickPrefix . '-003']);
    if ((int) $thirdNumberStmt->fetchColumn() !== 1) {
        throw new RuntimeException('A deleted entry number was reused by quick intake.');
    }

    $pdo->prepare('UPDATE stages SET status = ? WHERE stage_key = ?')->execute(['in_progress', 'judging']);
    $pdo->prepare('INSERT INTO users (username, display_name, password_hash, role) VALUES (?, ?, ?, ?)')
        ->execute(['judge-smoke-test', 'Judge Smoke Test', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'judge']);
    $judgeId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO judge_assignments (judge_id, category_id) VALUES (?, ?)')->execute([$judgeId, $categoryId]);

    $insertEntry = $pdo->prepare(
        'INSERT INTO entries (entry_number, category_id, description, owner_name, contact, registration_date)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $rankings = [];
    for ($rank = 1; $rank <= 5; $rank++) {
        $insertEntry->execute([
            'SMOKE-' . $rank, $categoryId, 'Test plant ' . $rank,
            'PRIVATE_OWNER_' . $rank, 'PRIVATE_CONTACT_' . $rank, date('Y-m-d'),
        ]);
        $entryId = (int) $pdo->lastInsertId();
        $rankings[$entryId] = $rank;
    }

    $_SESSION['user_id'] = $judgeId;
    $_POST = ['category_id' => $categoryId, 'rank' => $rankings, 'submit_state' => 'submitted'];
    save_judging(['id' => $judgeId, 'role' => 'judge']);

    $scores = consolidated_scores($categoryId);
    if (count($scores) !== 5 || $scores[0]['score'] !== 5 || $scores[4]['score'] !== 1) {
        throw new RuntimeException('Judge ranks did not consolidate to the expected points.');
    }

    ob_start();
    page_judge_form(['id' => $judgeId, 'role' => 'judge']);
    $judgeHtml = (string) ob_get_clean();
    if (
        str_contains($judgeHtml, 'PRIVATE_OWNER_') ||
        str_contains($judgeHtml, 'PRIVATE_CONTACT_') ||
        !str_contains($judgeHtml, 'SMOKE-1')
    ) {
        throw new RuntimeException('The judge interface did not preserve blind judging.');
    }

    $_SESSION['user_id'] = $adminId;
    $_POST = ['entry_id' => (int) array_key_first($rankings), 'reason' => 'Verify judging-history protection'];
    $judgedEntryDeletionBlocked = false;
    try {
        delete_entry();
    } catch (RuntimeException) {
        $judgedEntryDeletionBlocked = true;
    }
    if (!$judgedEntryDeletionBlocked) {
        throw new RuntimeException('Quick deletion allowed an entry with judging history.');
    }

    $metadata = $pdo->query('SELECT entry_number, owner_name FROM entries WHERE 1 = 0')->getColumnMeta(0);
    if (($metadata['name'] ?? null) !== 'entry_number') {
        throw new RuntimeException('SQLite report column metadata is unavailable.');
    }

    echo "PASS: category seed, quick intake, safe deletion, judge submission, score consolidation, blind interface, and SQLite metadata.\n";
} finally {
    $_SESSION['user_id'] = null;
}
