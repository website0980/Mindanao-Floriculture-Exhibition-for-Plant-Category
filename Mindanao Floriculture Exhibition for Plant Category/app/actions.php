<?php
declare(strict_types=1);

function post_value(string $key, int $max = 5000): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if (strlen($value) > $max * 4) {
        throw new RuntimeException('A submitted value is too long.');
    }
    return $value;
}

function must_be_open(string $stage): void
{
    if (!stage_open($stage)) {
        throw new RuntimeException('This judging stage is not open.');
    }
}

function next_entry_sequence(array $category): int
{
    $stmt = db()->prepare(
        'SELECT COALESCE(MAX(sequence), 0) + 1 FROM (
            SELECT CAST(substr(entry_number, length(?) + 2) AS INTEGER) AS sequence
            FROM entries WHERE entry_number LIKE ?
            UNION ALL
            SELECT CAST(substr(entry_number, length(?) + 2) AS INTEGER) AS sequence
            FROM entry_number_reservations WHERE entry_number LIKE ?
         )'
    );
    $stmt->execute([
        $category['prefix'], $category['prefix'] . '-%',
        $category['prefix'], $category['prefix'] . '-%',
    ]);
    return (int) $stmt->fetchColumn();
}

function format_entry_number(array $category, int $sequence): string
{
    return $category['prefix'] . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
}

function handle_action(string $action, ?array $user): never
{
    try {
        if ($action === 'login') {
            $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
            $stmt->execute([post_value('username', 80)]);
            $account = $stmt->fetch();
            if (!$account || !password_verify((string) ($_POST['password'] ?? ''), $account['password_hash'])) {
                throw new RuntimeException('Incorrect username or password.');
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $account['id'];
            redirect('dashboard');
        }

        $user = $user ?? require_login();
        $adminOnly = [
            'save_entry', 'quick_register_entries', 'delete_entry', 'create_user', 'set_stage', 'toggle_category', 'confirm_finalists',
            'save_deliberation', 'confirm_category_results', 'confirm_bis', 'publish_results',
            'set_settings', 'reopen_evaluation', 'save_publication', 'toggle_user',
        ];
        if (in_array($action, $adminOnly, true) && $user['role'] !== 'super_admin') {
            throw new RuntimeException('This action is restricted to the Super Administrator.');
        }

        switch ($action) {
            case 'save_entry':
                save_entry();
                break;
            case 'quick_register_entries':
                quick_register_entries();
                break;
            case 'delete_entry':
                delete_entry();
                break;
            case 'create_user':
                create_user();
                break;
            case 'set_stage':
                set_stage();
                break;
            case 'toggle_category':
                toggle_category();
                break;
            case 'save_judging':
                save_judging($user);
                break;
            case 'confirm_finalists':
                confirm_finalists();
                break;
            case 'save_justification':
                save_justification($user);
                break;
            case 'save_deliberation':
                save_deliberation();
                break;
            case 'confirm_category_results':
                confirm_category_results();
                break;
            case 'save_bis_vote':
                save_bis_vote($user);
                break;
            case 'confirm_bis':
                confirm_bis();
                break;
            case 'publish_results':
                publish_results();
                break;
            case 'set_settings':
                set_settings();
                break;
            case 'reopen_evaluation':
                reopen_evaluation();
                break;
            case 'save_publication':
                save_publication();
                break;
            case 'toggle_user':
                toggle_user();
                break;
            default:
                throw new RuntimeException('Unknown action.');
        }
    } catch (RuntimeException $error) {
        flash($error->getMessage(), 'error');
    } catch (PDOException $error) {
        error_log('Competition database action failed: ' . $error->getMessage());
        flash('The database could not save this change. Check required fields and uniqueness, then try again.', 'error');
    }
    redirect(safe_return_page());
}

function save_entry(): void
{
    must_be_open('registration');
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $categoryStmt = db()->prepare('SELECT * FROM categories WHERE id = ?');
    $categoryStmt->execute([$categoryId]);
    $category = $categoryStmt->fetch();
    if (!$category) {
        throw new RuntimeException('Choose a valid category.');
    }
    $description = post_value('description', 500);
    $owner = post_value('owner_name', 160);
    if ($description === '' || $owner === '') {
        throw new RuntimeException('Plant description and owner name are required.');
    }
    $id = (int) ($_POST['entry_id'] ?? 0);
    $status = (string) ($_POST['status'] ?? 'eligible');
    if (!in_array($status, ['eligible', 'disqualified'], true)) {
        throw new RuntimeException('Choose a valid eligibility status.');
    }
    $reason = post_value('disqualification_reason', 500);
    if ($status === 'disqualified' && $reason === '') {
        throw new RuntimeException('A disqualification reason is required.');
    }
    $before = null;
    $photoPath = '';
    if ($id > 0) {
        $stmt = db()->prepare('SELECT * FROM entries WHERE id = ?');
        $stmt->execute([$id]);
        $before = $stmt->fetch();
        if (!$before) {
            throw new RuntimeException('Entry not found.');
        }
        if ((int) $before['category_id'] !== $categoryId) {
            $history = db()->prepare(
                'SELECT
                   (SELECT COUNT(*) FROM judge_rankings WHERE entry_id = ?) +
                   (SELECT COUNT(*) FROM finalists WHERE entry_id = ?) +
                   (SELECT COUNT(*) FROM final_category_results WHERE entry_id = ?)'
            );
            $history->execute([$id, $id, $id]);
            if ((int) $history->fetchColumn() > 0) {
                throw new RuntimeException('This entry has judging history and cannot be moved to another category. Existing selections and results have been preserved.');
            }
        }
        $photoPath = (string) $before['photo_path'];
    }
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK || $_FILES['photo']['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('Photo upload failed or exceeds the 5 MB limit.');
        }
        $mime = mime_content_type($_FILES['photo']['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            throw new RuntimeException('Upload a JPEG, PNG, or WebP photo.');
        }
        $uploadDir = STORAGE_DIR . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0700, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Could not create the private photo directory.');
        }
        $photoPath = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . DIRECTORY_SEPARATOR . $photoPath)) {
            throw new RuntimeException('Could not save the uploaded photo.');
        }
    }
    if ($id > 0) {
        $stmt = db()->prepare(
            'UPDATE entries SET category_id = ?, description = ?, owner_name = ?, contact = ?,
             status = ?, disqualification_reason = ?, remarks = ?, photo_path = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([
            $categoryId, $description, $owner, post_value('contact', 250), $status, $reason,
            post_value('remarks', 1000), $photoPath, $id,
        ]);
        audit('updated_entry', 'entry', $id, $before, ['category_id' => $categoryId, 'description' => $description, 'status' => $status]);
    } else {
        $entryNumber = format_entry_number($category, next_entry_sequence($category));
        $stmt = db()->prepare(
            'INSERT INTO entries (entry_number, category_id, description, owner_name, contact, registration_date, status, disqualification_reason, remarks, photo_path)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $entryNumber, $categoryId, $description, $owner, post_value('contact', 250),
            post_value('registration_date', 20) ?: date('Y-m-d'), $status, $reason,
            post_value('remarks', 1000), $photoPath,
        ]);
        audit('created_entry', 'entry', db()->lastInsertId(), null, ['entry_number' => $entryNumber, 'category_id' => $categoryId]);
    }
    flash('Registration saved.');
}

function quick_register_entries(): void
{
    must_be_open('registration');
    if ((string) ($_POST['confirm_received'] ?? '') !== '1') {
        throw new RuntimeException('Confirm that the counts represent plants physically received.');
    }

    $submittedCounts = $_POST['counts'] ?? null;
    if (!is_array($submittedCounts)) {
        throw new RuntimeException('Enter a count for each active category.');
    }

    $activeCategories = categories(true);
    $validCategoryIds = [];
    $counts = [];
    foreach ($activeCategories as $category) {
        $categoryId = (int) $category['id'];
        $key = (string) $categoryId;
        $validCategoryIds[$key] = true;
        $value = $submittedCounts[$key] ?? null;
        if (!is_scalar($value) || !preg_match('/^\d+$/', (string) $value)) {
            throw new RuntimeException('Enter a whole-number count from 0 to 500 for every active category.');
        }
        $count = (int) $value;
        if ($count > 500) {
            throw new RuntimeException('Each category count must be between 0 and 500 per batch.');
        }
        $counts[$categoryId] = $count;
    }
    foreach (array_keys($submittedCounts) as $categoryId) {
        if (!isset($validCategoryIds[(string) $categoryId])) {
            throw new RuntimeException('The submitted category list is invalid.');
        }
    }

    $total = array_sum($counts);
    if ($total < 1) {
        throw new RuntimeException('Enter at least one plant to register.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO entries (entry_number, category_id, description, owner_name, registration_date)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($activeCategories as $category) {
            $categoryId = (int) $category['id'];
            $count = $counts[$categoryId];
            if ($count === 0) {
                continue;
            }

            $sequence = next_entry_sequence($category);
            $entryNumbers = [];
            for ($index = 0; $index < $count; $index++, $sequence++) {
                $entryNumber = format_entry_number($category, $sequence);
                $insert->execute([
                    $entryNumber,
                    $categoryId,
                    'Plant details pending',
                    'Owner details pending',
                    date('Y-m-d'),
                ]);
                $entryNumbers[] = $entryNumber;
            }
            audit(
                'quick_registered_entries',
                'category',
                $categoryId,
                null,
                ['count' => $count, 'entry_numbers' => $entryNumbers],
                'Confirmed plants physically received; details pending.'
            );
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    flash($total . ' numbered entries added. Complete their plant and owner details from Plant Registrations when available.');
}

function delete_entry(): void
{
    must_be_open('registration');
    $entryId = (int) ($_POST['entry_id'] ?? 0);
    $reason = post_value('reason', 500);
    if ($entryId < 1 || $reason === '') {
        throw new RuntimeException('Choose an entry and provide a deletion reason.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM entries WHERE id = ?');
        $stmt->execute([$entryId]);
        $entry = $stmt->fetch();
        if (!$entry) {
            throw new RuntimeException('Entry not found.');
        }

        $references = $pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM judge_rankings WHERE entry_id = ?) +
                (SELECT COUNT(*) FROM finalists WHERE entry_id = ?) +
                (SELECT COUNT(*) FROM deliberation_notes WHERE entry_id = ?) +
                (SELECT COUNT(*) FROM final_category_results WHERE entry_id = ?) +
                (SELECT COUNT(*) FROM best_in_show_evaluations WHERE entry_id = ?) +
                (SELECT COUNT(*) FROM best_in_show_result WHERE entry_id = ?)'
        );
        $references->execute(array_fill(0, 6, $entryId));
        if ((int) $references->fetchColumn() > 0) {
            throw new RuntimeException('This entry has judging or results history and cannot be deleted. Keep the audit trail and update its eligibility instead.');
        }

        $pdo->prepare('INSERT INTO entry_number_reservations (entry_number, category_id) VALUES (?, ?)')
            ->execute([$entry['entry_number'], $entry['category_id']]);
        audit('deleted_entry', 'entry', $entryId, $entry, null, $reason);
        $delete = $pdo->prepare('DELETE FROM entries WHERE id = ?');
        $delete->execute([$entryId]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('The entry could not be deleted.');
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    $photoPath = (string) $entry['photo_path'];
    if ($photoPath !== '') {
        $photoFile = STORAGE_DIR . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($photoPath);
        if (is_file($photoFile) && !unlink($photoFile)) {
            error_log('Could not remove the private photo for deleted entry ' . $entryId . '.');
            throw new RuntimeException('The entry was deleted, but its private photo could not be removed.');
        }
    }

    flash('Entry deleted. Remaining entry numbers were not changed, and this number will not be reused.');
}

function create_user(): void
{
    $username = post_value('username', 80);
    $displayName = post_value('display_name', 120);
    $password = (string) ($_POST['password'] ?? '');
    $role = (string) ($_POST['role'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) || $displayName === '' || strlen($password) < 12) {
        throw new RuntimeException('Use a valid username, display name, and password with at least 12 characters.');
    }
    if (!in_array($role, ['judge', 'tabulator'], true)) {
        throw new RuntimeException('Choose Judge or Tabulator.');
    }
    $permissions = [];
    if ($role === 'tabulator' && isset($_POST['reports_permission'])) {
        $permissions[] = 'reports';
    }
    $stmt = db()->prepare('INSERT INTO users (username, display_name, password_hash, role, tabulator_permissions) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$username, $displayName, password_hash($password, PASSWORD_DEFAULT), $role, json_encode($permissions)]);
    $newId = (int) db()->lastInsertId();
    if ($role === 'judge') {
        $assign = db()->prepare('INSERT INTO judge_assignments (judge_id, category_id) VALUES (?, ?)');
        foreach (array_unique(array_map('intval', (array) ($_POST['categories'] ?? []))) as $categoryId) {
            $assign->execute([$newId, $categoryId]);
        }
    }
    audit('created_user', 'user', $newId, null, ['username' => $username, 'role' => $role]);
    flash('User account created.');
}

function set_stage(): void
{
    $key = post_value('stage_key', 60);
    $status = post_value('status', 30);
    $allowed = ['not_started', 'in_progress', 'pending_review', 'completed', 'locked'];
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('Invalid stage status.');
    }
    $stmt = db()->prepare('SELECT status FROM stages WHERE stage_key = ?');
    $stmt->execute([$key]);
    $before = $stmt->fetchColumn();
    if ($before === false) {
        throw new RuntimeException('Stage not found.');
    }
    $prerequisites = [
        'verification' => 'registration',
        'judge_setup' => 'verification',
        'judging' => 'judge_setup',
        'judge_submissions' => 'judging',
        'finalist_consolidation' => 'judge_submissions',
        'finalist_confirmation' => 'finalist_consolidation',
        'deliberation' => 'finalist_confirmation',
        'final_ranking' => 'deliberation',
        'best_in_show' => 'final_ranking',
        'approval' => 'best_in_show',
        'publication' => 'approval',
    ];
    $reason = post_value('reason', 500);
    $prerequisite = $prerequisites[$key] ?? null;
    if ($status !== 'not_started' && $status !== 'locked' && $prerequisite && stage_status($prerequisite) !== 'completed' && $reason === '') {
        throw new RuntimeException('Complete the preceding stage first, or enter an explicit override reason.');
    }
    if ($status === 'completed' && $reason === '') {
        throw new RuntimeException('A reason or completion note is required when completing a stage.');
    }
    if ($status === 'completed' && $key === 'judge_submissions') {
        $pending = (int) db()->query(
            "SELECT COUNT(*) FROM judge_assignments ja
             LEFT JOIN judge_evaluations je ON je.judge_id = ja.judge_id AND je.category_id = ja.category_id AND je.status = 'submitted'
             WHERE je.id IS NULL"
        )->fetchColumn();
        if ($pending > 0 && $reason === '') {
            throw new RuntimeException("$pending assigned judge evaluation(s) are pending. Enter an explicit override reason to continue.");
        }
    }
    if ($status === 'completed') {
        $requiredCounts = [
            'finalist_confirmation' => ['SELECT COUNT(*) FROM finalists', 40, 'All 40 finalists must be confirmed.'],
            'deliberation' => ["SELECT COUNT(*) FROM deliberations WHERE status = 'completed'", 8, 'All eight category deliberations must be completed.'],
            'final_ranking' => ['SELECT COUNT(*) FROM final_category_results', 40, 'All 40 official category placements must be confirmed.'],
        ];
        if (isset($requiredCounts[$key])) {
            [$sql, $expected, $message] = $requiredCounts[$key];
            $actual = (int) db()->query($sql)->fetchColumn();
            if ($actual !== $expected && $reason === '') {
                throw new RuntimeException($message . ' Provide an explicit override reason to continue.');
            }
        }
        if ($key === 'best_in_show' && !(int) db()->query('SELECT COUNT(*) FROM best_in_show_result')->fetchColumn() && $reason === '') {
            throw new RuntimeException('Confirm the Best in Show result or provide an explicit override reason.');
        }
        if ($key === 'approval' && ((int) db()->query('SELECT COUNT(*) FROM final_category_results')->fetchColumn() !== 40 || !(int) db()->query('SELECT COUNT(*) FROM best_in_show_result')->fetchColumn()) && $reason === '') {
            throw new RuntimeException('Complete all official results or provide an explicit override reason.');
        }
    }
    $stmt = db()->prepare('UPDATE stages SET status = ? WHERE stage_key = ?');
    $stmt->execute([$status, $key]);
    audit('changed_stage', 'stage', $key, ['status' => $before], ['status' => $status], $reason);
    flash('Stage status updated.');
}

function toggle_category(): void
{
    $id = (int) ($_POST['category_id'] ?? 0);
    $stmt = db()->prepare('SELECT active, name FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    $category = $stmt->fetch();
    if (!$category) {
        throw new RuntimeException('Category not found.');
    }
    $active = (int) !$category['active'];
    $stmt = db()->prepare('UPDATE categories SET active = ? WHERE id = ?');
    $stmt->execute([$active, $id]);
    audit('changed_category_status', 'category', $id, $category, ['active' => $active], post_value('reason', 500));
    flash('Category status updated. Existing entries and judging records were not changed.');
}

function save_judging(array $user): void
{
    if ($user['role'] !== 'judge') {
        throw new RuntimeException('Only judges may submit judging records.');
    }
    must_be_open('judging');
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    if (!can_judge_category($user, $categoryId)) {
        throw new RuntimeException('This category is not assigned to your account.');
    }
    $stmt = db()->prepare('SELECT * FROM judge_evaluations WHERE judge_id = ? AND category_id = ?');
    $stmt->execute([$user['id'], $categoryId]);
    $evaluation = $stmt->fetch();
    if ($evaluation && $evaluation['status'] === 'submitted') {
        throw new RuntimeException('This evaluation is finalized. Ask the administrator to reopen it.');
    }
    $ranks = $_POST['rank'] ?? [];
    if (!is_array($ranks)) {
        throw new RuntimeException('Invalid ranking data.');
    }
    $selected = [];
    foreach ($ranks as $entryId => $rank) {
        if ($rank === '' || $rank === null) {
            continue;
        }
        $entryId = (int) $entryId;
        $rank = filter_var($rank, FILTER_VALIDATE_INT);
        if ($entryId < 1 || $rank === false || $rank < 1 || $rank > 5) {
            throw new RuntimeException('Each selection must have one rank from 1 to 5.');
        }
        $selected[$entryId] = (int) $rank;
    }
    if (count($selected) !== 5 || count(array_unique(array_values($selected))) !== 5) {
        throw new RuntimeException('Select exactly five distinct entries and assign each rank 1 through 5 once.');
    }
    $ids = array_keys($selected);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT COUNT(*) FROM entries WHERE category_id = ? AND status = 'eligible' AND id IN ($placeholders)");
    $stmt->execute(array_merge([$categoryId], $ids));
    if ((int) $stmt->fetchColumn() !== 5) {
        throw new RuntimeException('One or more selected entries are not eligible in this category.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (!$evaluation) {
            $stmt = $pdo->prepare('INSERT INTO judge_evaluations (judge_id, category_id, status) VALUES (?, ?, \'draft\')');
            $stmt->execute([$user['id'], $categoryId]);
            $evaluationId = (int) $pdo->lastInsertId();
        } else {
            $evaluationId = (int) $evaluation['id'];
            $pdo->prepare('DELETE FROM judge_rankings WHERE evaluation_id = ?')->execute([$evaluationId]);
        }
        $submit = (string) ($_POST['submit_state'] ?? 'draft') === 'submitted';
        $insert = $pdo->prepare('INSERT INTO judge_rankings (evaluation_id, entry_id, rank) VALUES (?, ?, ?)');
        foreach ($selected as $entryId => $rank) {
            $insert->execute([$evaluationId, $entryId, $rank]);
        }
        $pdo->prepare('UPDATE judge_evaluations SET status = ?, submitted_at = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$submit ? 'submitted' : 'draft', $submit ? date('Y-m-d H:i:s') : null, $evaluationId]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    audit($submit ? 'submitted_judging' : 'saved_judging_draft', 'evaluation', $evaluationId);
    flash($submit ? 'Your category evaluation is submitted and locked.' : 'Draft saved.');
}

function confirm_finalists(): void
{
    if (!stage_open('finalist_confirmation')) {
        throw new RuntimeException('The finalist confirmation stage is not open.');
    }
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $ids = array_map('intval', (array) ($_POST['entry_ids'] ?? []));
    $ranksRaw = $_POST['finalist_rank'] ?? [];
    $reason = post_value('reason', 500);
    if (count(array_unique($ids)) !== 5 || count($ids) !== 5 || $reason === '') {
        throw new RuntimeException('Select five unique entries and record the reason for confirmation.');
    }
    $eligible = [];
    foreach (consolidated_scores($categoryId) as $score) {
        $eligible[$score['id']] = $score;
    }
    foreach ($ids as $id) {
        if (!isset($eligible[$id])) {
            throw new RuntimeException('Finalists must have at least one submitted judge selection.');
        }
    }
    $ranks = [];
    foreach ($ids as $id) {
        $rank = filter_var($ranksRaw[$id] ?? null, FILTER_VALIDATE_INT);
        if ($rank === false || $rank < 1 || $rank > 5) {
            throw new RuntimeException('Assign a unique finalist rank from 1 to 5 to each selected entry.');
        }
        $ranks[$id] = $rank;
    }
    if (count(array_unique($ranks)) !== 5) {
        throw new RuntimeException('Finalist ranks must be unique.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM finalists WHERE category_id = ?')->execute([$categoryId]);
        $insert = $pdo->prepare('INSERT INTO finalists (category_id, entry_id, finalist_rank, confirmed_by) VALUES (?, ?, ?, ?)');
        foreach ($ranks as $id => $rank) {
            $insert->execute([$categoryId, $id, $rank, current_user()['id']]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    audit('confirmed_finalists', 'category', $categoryId, null, $ranks, $reason);
    flash('Finalists confirmed for the selected category.');
}

function save_justification(array $user): void
{
    if ($user['role'] !== 'judge') {
        throw new RuntimeException('Only judges may submit justifications.');
    }
    must_be_open('deliberation');
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $entryId = (int) ($_POST['entry_id'] ?? 0);
    if (!can_judge_category($user, $categoryId)) {
        throw new RuntimeException('This category is not assigned to your account.');
    }
    $stmt = db()->prepare('SELECT 1 FROM finalists WHERE category_id = ? AND entry_id = ? AND finalist_rank <= 3');
    $stmt->execute([$categoryId, $entryId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Justifications are limited to the top three finalists.');
    }
    $notes = post_value('notes', 2000);
    if ($notes === '') {
        throw new RuntimeException('Enter a justification.');
    }
    $stmt = db()->prepare(
        'INSERT INTO deliberation_notes (category_id, entry_id, judge_id, notes) VALUES (?, ?, ?, ?)
         ON CONFLICT(category_id, entry_id, judge_id) DO UPDATE SET notes = excluded.notes, updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$categoryId, $entryId, $user['id'], $notes]);
    audit('saved_justification', 'entry', $entryId);
    flash('Justification saved.');
}

function save_deliberation(): void
{
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $stmt = db()->prepare(
        'INSERT INTO deliberations (category_id, discussion_notes, administrator_remarks, status, updated_by)
         VALUES (?, ?, ?, ?, ?)
         ON CONFLICT(category_id) DO UPDATE SET discussion_notes = excluded.discussion_notes,
         administrator_remarks = excluded.administrator_remarks, status = excluded.status,
         updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP'
    );
    $status = isset($_POST['completed']) ? 'completed' : 'in_progress';
    $stmt->execute([$categoryId, post_value('discussion_notes', 5000), post_value('administrator_remarks', 2000), $status, current_user()['id']]);
    audit('updated_deliberation', 'category', $categoryId, null, ['status' => $status]);
    flash('Deliberation record saved.');
}

function confirm_category_results(): void
{
    if (!stage_open('final_ranking')) {
        throw new RuntimeException('The final ranking stage is not open.');
    }
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $reason = post_value('reason', 500);
    $deliberation = db()->prepare("SELECT status FROM deliberations WHERE category_id = ?");
    $deliberation->execute([$categoryId]);
    if ($deliberation->fetchColumn() !== 'completed' && $reason === '') {
        throw new RuntimeException('Complete deliberation for this category or provide an explicit override reason.');
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM finalists WHERE category_id = ?');
    $stmt->execute([$categoryId]);
    if ((int) $stmt->fetchColumn() !== 5) {
        throw new RuntimeException('Confirm five finalists before recording official placements.');
    }
    $placements = $_POST['placement'] ?? [];
    $valid = [];
    foreach ($placements as $entryId => $placement) {
        if ($placement === '' || $placement === null) {
            continue;
        }
        $place = filter_var($placement, FILTER_VALIDATE_INT);
        if ($place === false || $place < 1 || $place > 5) {
            throw new RuntimeException('Placements must be between 1 and 5.');
        }
        $valid[(int) $entryId] = (int) $place;
    }
    if (count($valid) !== 5 || count(array_unique($valid)) !== 5) {
        throw new RuntimeException('Assign each of the five finalists a unique placement from 1 to 5.');
    }
    $finalistStmt = db()->prepare('SELECT COUNT(*) FROM finalists WHERE category_id = ? AND entry_id = ?');
    foreach ($valid as $entryId => $_place) {
        $finalistStmt->execute([$categoryId, $entryId]);
        if ((int) $finalistStmt->fetchColumn() !== 1) {
            throw new RuntimeException('Official placements must use confirmed finalists only.');
        }
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM final_category_results WHERE category_id = ?')->execute([$categoryId]);
        $insert = $pdo->prepare('INSERT INTO final_category_results (category_id, entry_id, placement, confirmed_by) VALUES (?, ?, ?, ?)');
        foreach ($valid as $entryId => $placement) {
            $insert->execute([$categoryId, $entryId, $placement, current_user()['id']]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    audit('confirmed_category_results', 'category', $categoryId, null, $valid, $reason);
    flash('Official category results confirmed. Original judging records remain unchanged.');
}

function save_bis_vote(array $user): void
{
    if ($user['role'] !== 'judge' || !stage_open('best_in_show') || setting('best_in_show_method') !== 'judge_selection') {
        throw new RuntimeException('Best in Show judging is not available.');
    }
    $entryId = (int) ($_POST['entry_id'] ?? 0);
    $stmt = db()->prepare('SELECT COUNT(*) FROM final_category_results WHERE entry_id = ? AND placement = 1');
    $stmt->execute([$entryId]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Select one of the approved category winners.');
    }
    $stmt = db()->prepare(
        'INSERT INTO best_in_show_evaluations (judge_id, entry_id, notes) VALUES (?, ?, ?)
         ON CONFLICT(judge_id) DO UPDATE SET entry_id = excluded.entry_id, notes = excluded.notes, updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$user['id'], $entryId, post_value('notes', 1000)]);
    audit('submitted_bis_vote', 'entry', $entryId);
    flash('Best in Show selection saved.');
}

function confirm_bis(): void
{
    if (!stage_open('best_in_show')) {
        throw new RuntimeException('The Best in Show stage is not open.');
    }
    $entryId = (int) ($_POST['entry_id'] ?? 0);
    $stmt = db()->prepare('SELECT COUNT(*) FROM final_category_results WHERE entry_id = ? AND placement = 1');
    $stmt->execute([$entryId]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new RuntimeException('The winner must be one of the eight category winners.');
    }
    $reason = post_value('reason', 500);
    if ($reason === '') {
        throw new RuntimeException('Record the reason for the Best in Show confirmation.');
    }
    db()->prepare(
        'INSERT INTO best_in_show_result (id, entry_id, confirmed_by) VALUES (1, ?, ?)
         ON CONFLICT(id) DO UPDATE SET entry_id = excluded.entry_id, confirmed_by = excluded.confirmed_by, confirmed_at = CURRENT_TIMESTAMP'
    )->execute([$entryId, current_user()['id']]);
    audit('confirmed_best_in_show', 'entry', $entryId, null, ['entry_id' => $entryId], $reason);
    flash('Best in Show result confirmed.');
}

function publish_results(): void
{
    if (stage_status('approval') !== 'completed') {
        throw new RuntimeException('Mark the final result approval stage completed before publishing.');
    }
    if (!stage_open('publication')) {
        throw new RuntimeException('Open the public result publication stage before publishing.');
    }
    $categories = categories(true);
    if (count($categories) !== 8) {
        throw new RuntimeException('All eight initial categories must be active before publication.');
    }
    $count = (int) db()->query('SELECT COUNT(*) FROM final_category_results')->fetchColumn();
    if ($count !== 40 || !(int) db()->query('SELECT COUNT(*) FROM best_in_show_result')->fetchColumn()) {
        throw new RuntimeException('Approve all 40 category placements and one Best in Show result before publication.');
    }
    db()->prepare("UPDATE settings SET setting_value = '1' WHERE setting_key = 'published'")->execute();
    db()->prepare("UPDATE stages SET status = 'completed' WHERE stage_key = 'publication'")->execute();
    audit('published_results', 'competition');
    flash('Approved results are now publicly visible.');
}

function set_settings(): void
{
    $method = post_value('consolidation_method', 40);
    if (!in_array($method, ['points_5_to_1', 'average_rank'], true)) {
        throw new RuntimeException('Choose a supported consolidation method.');
    }
    $points = [];
    foreach (range(1, 5) as $rank) {
        $value = filter_var($_POST['points'][$rank] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < 0 || $value > 100) {
            throw new RuntimeException('Ranking points must be whole numbers from 0 to 100.');
        }
        $points[] = $value;
    }
    $bisMethod = post_value('best_in_show_method', 40);
    if (!in_array($bisMethod, ['administrator_decision', 'judge_selection'], true)) {
        throw new RuntimeException('Choose a supported Best in Show method.');
    }
    $stmt = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value');
    $stmt->execute(['consolidation_method', $method]);
    $stmt->execute(['consolidation_points', json_encode($points)]);
    $stmt->execute(['best_in_show_method', $bisMethod]);
    audit('changed_judging_settings', 'settings', 'rules');
    flash('Judging rules saved. Confirm the active consolidation method with the organizer.');
}

function reopen_evaluation(): void
{
    $evaluationId = (int) ($_POST['evaluation_id'] ?? 0);
    $reason = post_value('reason', 500);
    if ($reason === '') {
        throw new RuntimeException('A reason is required to reopen a finalized evaluation.');
    }
    $stmt = db()->prepare('SELECT status FROM judge_evaluations WHERE id = ?');
    $stmt->execute([$evaluationId]);
    $before = $stmt->fetchColumn();
    if ($before !== 'submitted') {
        throw new RuntimeException('Only submitted evaluations can be reopened.');
    }
    db()->prepare('UPDATE judge_evaluations SET status = \'draft\', submitted_at = NULL WHERE id = ?')->execute([$evaluationId]);
    audit('reopened_evaluation', 'evaluation', $evaluationId, ['status' => $before], ['status' => 'draft'], $reason);
    flash('Evaluation reopened. Its original ranking rows are retained until the judge edits them.');
}

function save_publication(): void
{
    $enabled = isset($_POST['public_owner_names']) ? '1' : '0';
    db()->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = \'public_owner_names\'')->execute([$enabled]);
    audit('changed_public_owner_setting', 'settings', 'public_owner_names', null, ['enabled' => $enabled]);
    flash('Public display settings saved.');
}

function toggle_user(): void
{
    $userId = (int) ($_POST['user_id'] ?? 0);
    if ($userId === (int) current_user()['id']) {
        throw new RuntimeException('You cannot deactivate your own account.');
    }
    $stmt = db()->prepare('SELECT id, username, role, active FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target || $target['role'] === 'super_admin') {
        throw new RuntimeException('This account cannot be changed here.');
    }
    $active = (int) !$target['active'];
    db()->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$active, $userId]);
    audit('changed_user_status', 'user', $userId, $target, ['active' => $active], post_value('reason', 500));
    flash('User account status updated.');
}
