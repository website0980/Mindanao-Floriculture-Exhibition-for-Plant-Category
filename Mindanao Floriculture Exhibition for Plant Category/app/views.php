<?php
declare(strict_types=1);

function render_page(string $page, ?array $user): void
{
    $routes = [
        'login', 'dashboard', 'entries', 'judging', 'finalists', 'deliberation',
        'results', 'best_in_show', 'workflow', 'users', 'settings', 'audit', 'reports',
    ];
    if (!in_array($page, $routes, true)) {
        http_response_code(404);
        $page = 'dashboard';
    }
    if ($page === 'login' && $user) {
        redirect('dashboard');
    }
    if ($page !== 'login' && !$user) {
        redirect('login');
    }
    $restricted = ['entries', 'finalists', 'deliberation', 'results', 'best_in_show', 'workflow', 'users', 'settings', 'audit', 'reports'];
    if (in_array($page, $restricted, true)) {
        $user = require_login();
        if ($user['role'] !== 'super_admin' && !($page === 'reports' && can_view_reports($user))) {
            http_response_code(403);
            exit('This page is not available to your account.');
        }
    }

    $titles = [
        'login' => 'Sign in', 'dashboard' => 'Dashboard',
        'entries' => 'Plant registrations', 'judging' => 'My judging', 'finalists' => 'Finalist consolidation',
        'deliberation' => 'Deliberation', 'results' => 'Official category results',
        'best_in_show' => 'Best in Show', 'workflow' => 'Competition workflow',
        'users' => 'User accounts', 'settings' => 'Judging settings', 'audit' => 'Audit log', 'reports' => 'Reports',
    ];
    $title = $titles[$page] ?? 'Competition';
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <meta name="theme-color" content="#104633">
      <title><?= e($title) ?> · Floriculture Competition</title>
      <link rel="stylesheet" href="assets/app.css">
    </head>
    <body>
    <?php if ($user): ?>
      <header class="topbar no-print">
        <div class="brand">Floriculture Competition</div>
        <nav>
          <a href="index.php?page=dashboard">Dashboard</a>
          <?php if ($user['role'] === 'judge'): ?>
            <a href="index.php?page=judging">My Judging</a>
          <?php elseif ($user['role'] === 'super_admin'): ?>
            <a href="index.php?page=entries">Entries</a>
            <a href="index.php?page=judging">Judging</a>
            <a href="index.php?page=finalists">Finalists</a>
            <a href="index.php?page=workflow">Workflow</a>
            <a href="index.php?page=reports">Reports</a>
          <?php elseif (can_view_reports($user)): ?>
            <a href="index.php?page=reports">Reports</a>
          <?php endif; ?>
          <?php if ($user['role'] === 'super_admin'): ?>
            <a href="index.php?page=users">Users</a>
            <a href="index.php?page=settings">Settings</a>
          <?php endif; ?>
          <a href="index.php?page=public">Public results</a>
          <a href="index.php?page=logout">Sign out</a>
        </nav>
      </header>
    <?php endif; ?>
    <main class="wrap">
      <?php if (isset($_SESSION['flash'])): $notice = $_SESSION['flash']; unset($_SESSION['flash']); ?>
        <div class="alert <?= e($notice['kind']) ?>"><?= e($notice['message']) ?></div>
      <?php endif; ?>
      <?php
      match ($page) {
          'login' => page_login(),
          'dashboard' => page_dashboard($user),
          'entries' => page_entries(),
          'judging' => page_judging($user),
          'finalists' => page_finalists(),
          'deliberation' => page_deliberation($user),
          'results' => page_results(),
          'best_in_show' => page_best_in_show($user),
          'workflow' => page_workflow(),
          'users' => page_users(),
          'settings' => page_settings(),
          'audit' => page_audit(),
          'reports' => page_reports(),
          default => null,
      };
      ?>
      <script src="assets/app.js" defer></script>
    </main>
    <footer>Floriculture Plants Competition · Private judging records are access-controlled.</footer>
    </body>
    </html>
    <?php
}

function page_login(): void
{
    $accountCount = (int) db()->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn();
    ?>
    <div class="card" style="max-width:460px;margin:8vh auto">
      <h1>Sign in</h1><p class="sub">Floriculture Plants Competition</p>
      <?php if ($accountCount === 0): ?><p class="alert error">No active accounts were found in this database. Connect the database containing your existing account before signing in.</p><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="login">
        <label>Username</label><input name="username" required autocomplete="username">
        <label>Password</label><input type="password" name="password" required autocomplete="current-password">
        <p><button>Sign in</button></p>
      </form>
    </div>
    <?php
}

function page_dashboard(array $user): void
{
    if ($user['role'] === 'judge') {
        $stmt = db()->prepare(
            'SELECT c.id, c.name, je.status, je.submitted_at, COUNT(jr.entry_id) AS selected
             FROM judge_assignments ja JOIN categories c ON c.id = ja.category_id
             LEFT JOIN judge_evaluations je ON je.category_id = c.id AND je.judge_id = ja.judge_id
             LEFT JOIN judge_rankings jr ON jr.evaluation_id = je.id
             WHERE ja.judge_id = ? GROUP BY c.id ORDER BY c.position'
        );
        $stmt->execute([$user['id']]);
        $tasks = $stmt->fetchAll();
        ?>
        <h1>My Judging Tasks</h1><p class="sub">Only eligible entry numbers are shown. Owner and registration details are not available to judge accounts.</p>
        <div class="card"><div class="table-wrap"><table><thead><tr><th>Category</th><th>Progress</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($tasks as $task): ?>
          <tr><td><?= e($task['name']) ?></td><td><?= (int) $task['selected'] ?>/5</td><td><span class="badge <?= e($task['status'] ?: 'not_started') ?>"><?= e(ucfirst(str_replace('_', ' ', $task['status'] ?: 'not started'))) ?></span></td><td><a class="button" href="index.php?page=judging&category=<?= (int) $task['id'] ?>">Open</a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
        <?php
        return;
    }

    if ($user['role'] === 'tabulator') {
        $submitted = (int) db()->query("SELECT COUNT(*) FROM judge_evaluations WHERE status = 'submitted'")->fetchColumn();
        $pending = (int) db()->query("SELECT COUNT(*) FROM judge_assignments ja LEFT JOIN judge_evaluations je ON je.judge_id = ja.judge_id AND je.category_id = ja.category_id AND je.status = 'submitted' WHERE je.id IS NULL")->fetchColumn();
        ?>
        <h1>Tabulator Dashboard</h1><p class="sub">Monitoring view. Final approval and publication remain with the Super Administrator.</p>
        <div class="grid"><div class="card"><div class="metric"><?= $submitted ?></div><div class="metric-label">Submitted judge evaluations</div></div><div class="card"><div class="metric"><?= $pending ?></div><div class="metric-label">Pending assigned evaluations</div></div></div>
        <?php if (can_view_reports($user)): ?><p><a class="button" href="index.php?page=reports">Open reports</a></p><?php endif; ?>
        <?php
        return;
    }

    $categoryFilter = (int) ($_GET['category'] ?? 0);
    $validCategoryIds = array_map(static fn(array $category): int => (int) $category['id'], categories());
    if ($categoryFilter !== 0 && !in_array($categoryFilter, $validCategoryIds, true)) {
        $categoryFilter = 0;
    }
    $stageFilter = (string) ($_GET['stage'] ?? '');
    $validStages = array_column(db()->query('SELECT stage_key FROM stages')->fetchAll(), 'stage_key');
    if ($stageFilter !== '' && !in_array($stageFilter, $validStages, true)) {
        $stageFilter = '';
    }
    $categoryClause = $categoryFilter ? ' AND category_id = ?' : '';
    $categoryArgs = $categoryFilter ? [$categoryFilter] : [];
    $countForSelection = static function (string $sql) use ($categoryClause, $categoryArgs): int {
        $stmt = db()->prepare($sql . $categoryClause);
        $stmt->execute($categoryArgs);
        return (int) $stmt->fetchColumn();
    };
    $metrics = [
        ['Registered plants', $countForSelection('SELECT COUNT(*) FROM entries WHERE 1=1')],
        ['Eligible entries', $countForSelection("SELECT COUNT(*) FROM entries WHERE status = 'eligible'")],
        ['Disqualified entries', $countForSelection("SELECT COUNT(*) FROM entries WHERE status = 'disqualified'")],
        ['Registered judges', (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'judge' AND active = 1")->fetchColumn()],
        ['Submitted evaluations', $countForSelection("SELECT COUNT(*) FROM judge_evaluations WHERE status = 'submitted'")],
        ['Confirmed finalists', $countForSelection('SELECT COUNT(*) FROM finalists')],
        ['Completed deliberations', $countForSelection("SELECT COUNT(*) FROM deliberations WHERE status = 'completed'")],
    ];
    $progressTarget = $categoryFilter ? 5 : 40;
    $progress = min(100, (int) round(($metrics[5][1] / $progressTarget) * 100));
    $chartQuery = db()->query(
        'SELECT c.id, c.name,
           (SELECT COUNT(*) FROM entries e WHERE e.category_id = c.id AND e.status = \'eligible\') AS eligible_entries,
           (SELECT COUNT(*) FROM judge_assignments ja WHERE ja.category_id = c.id) AS assigned_judges,
           (SELECT COUNT(*) FROM judge_evaluations je WHERE je.category_id = c.id AND je.status = \'submitted\') AS submitted_evaluations
         FROM categories c ' . ($categoryFilter ? 'WHERE c.id = ' . $categoryFilter : '') . ' ORDER BY c.position'
    )->fetchAll();
    $maxEntries = max(1, ...array_map(static fn(array $row): int => (int) $row['eligible_entries'], $chartQuery));
    $visibleStages = db()->prepare('SELECT * FROM stages ' . ($stageFilter ? 'WHERE stage_key = ?' : '') . ' ORDER BY rowid');
    $visibleStages->execute($stageFilter ? [$stageFilter] : []);
    ?>
    <div class="spread"><div><h1>Competition Dashboard</h1><p class="sub">Overview of registration, judging, and official results.</p></div><span class="badge"><?= e($user['display_name']) ?> · Super Administrator</span></div>
    <section class="card no-print"><form method="get" class="row"><input type="hidden" name="page" value="dashboard"><label>Category</label><select name="category"><option value="0">All categories</option><?php foreach (categories() as $category): ?><option value="<?= (int) $category['id'] ?>" <?= $categoryFilter === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select><label>Stage</label><select name="stage"><option value="">All stages</option><?php foreach (db()->query('SELECT stage_key, label FROM stages ORDER BY rowid')->fetchAll() as $stage): ?><option value="<?= e($stage['stage_key']) ?>" <?= $stageFilter === $stage['stage_key'] ? 'selected' : '' ?>><?= e($stage['label']) ?></option><?php endforeach; ?></select><button class="secondary">Apply filters</button></form></section>
    <div class="grid">
      <?php foreach ($metrics as [$label, $value]): ?><div class="card"><div class="metric"><?= $value ?></div><div class="metric-label"><?= e($label) ?></div></div><?php endforeach; ?>
      <div class="card"><div class="metric"><?= (int) db()->query('SELECT COUNT(*) FROM best_in_show_result')->fetchColumn() ? 'Confirmed' : 'Pending' ?></div><div class="metric-label">Best in Show</div></div>
    </div>
    <section class="card"><h2>Finalist confirmation progress</h2><p><?= $progress ?>% · <?= (int) $metrics[5][1] ?> / <?= $progressTarget ?> finalists<?= $categoryFilter ? ' in selected category' : ' overall' ?></p><div class="progress"><span style="width:<?= $progress ?>%"></span></div></section>
    <div class="grid two">
      <section class="card"><h2>Eligible entries by category</h2><?php foreach ($chartQuery as $row): $barWidth = (int) round(((int) $row['eligible_entries'] / $maxEntries) * 100); ?><div class="bar-row"><span><?= e($row['name']) ?></span><div class="progress"><span style="width:<?= $barWidth ?>%"></span></div><strong><?= (int) $row['eligible_entries'] ?></strong></div><?php endforeach; ?></section>
      <section class="card"><h2>Judge submissions by category</h2><?php foreach ($chartQuery as $row): $assigned = (int) $row['assigned_judges']; $submittedCount = (int) $row['submitted_evaluations']; $submissionWidth = $assigned ? (int) round(min(100, $submittedCount / $assigned * 100)) : 0; ?><div class="bar-row"><span><?= e($row['name']) ?></span><div class="progress"><span style="width:<?= $submissionWidth ?>%"></span></div><strong><?= $submittedCount ?>/<?= $assigned ?></strong></div><?php endforeach; ?></section>
      <section class="card"><h2>Stage overview</h2><?php foreach ($visibleStages->fetchAll() as $stage): ?><div class="spread stage-row"><span><?= e($stage['label']) ?></span><span class="badge <?= e($stage['status']) ?>"><?= e(ucfirst(str_replace('_', ' ', $stage['status']))) ?></span></div><?php endforeach; ?><p><a href="index.php?page=workflow">Manage workflow →</a></p></section>
      <section class="card"><h2>Quick actions</h2><p><a class="button" href="index.php?page=entries">Register a plant</a></p><p><a class="button secondary" href="index.php?page=users">Manage users and assignments</a></p><p><a class="button secondary" href="index.php?page=reports">Reports and exports</a></p></section>
    </div>
    <?php
}

function page_entries(): void
{
    require_admin();
    $edit = null;
    if (isset($_GET['edit'])) {
        $stmt = db()->prepare('SELECT * FROM entries WHERE id = ?');
        $stmt->execute([(int) $_GET['edit']]);
        $edit = $stmt->fetch() ?: null;
    }
    $query = trim((string) ($_GET['q'] ?? ''));
    $filter = (string) ($_GET['category'] ?? '');
    $sql = 'SELECT e.*, c.name AS category_name FROM entries e JOIN categories c ON c.id = e.category_id WHERE 1=1';
    $args = [];
    if ($query !== '') {
        $sql .= ' AND (e.entry_number LIKE ? OR e.owner_name LIKE ? OR e.description LIKE ?)';
        array_push($args, '%' . $query . '%', '%' . $query . '%', '%' . $query . '%');
    }
    if ($filter !== '') {
        $sql .= ' AND e.category_id = ?';
        $args[] = (int) $filter;
    }
    $sql .= ' ORDER BY c.position, e.entry_number';
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    $entries = $stmt->fetchAll();
    $categoryCounts = db()->query(
        'SELECT category_id, COUNT(*) AS total_entries,
                SUM(CASE WHEN status = \'eligible\' THEN 1 ELSE 0 END) AS eligible_entries
         FROM entries GROUP BY category_id'
    )->fetchAll();
    $countsByCategory = [];
    foreach ($categoryCounts as $row) {
        $countsByCategory[(int) $row['category_id']] = $row;
    }
    $registrationOpen = stage_open('registration');
    ?>
    <div class="spread"><div><h1>Plant Registrations</h1><p class="sub">Owner details are restricted to administrator accounts.</p></div><button type="button" class="no-print" data-print>Print entry labels</button></div>
    <div class="grid two no-print">
      <section class="card">
        <h2><?= $edit ? 'Edit registration' : 'Register plant' ?></h2>
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_entry"><input type="hidden" name="return_page" value="entries"><input type="hidden" name="entry_id" value="<?= (int) ($edit['id'] ?? 0) ?>">
          <label>Category</label><select name="category_id" required><?php foreach (categories() as $cat): ?><option value="<?= (int) $cat['id'] ?>" <?= (int) ($edit['category_id'] ?? 0) === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?><?= !$cat['active'] ? ' (inactive)' : '' ?></option><?php endforeach; ?></select>
          <label>Plant name or description</label><input name="description" maxlength="500" required value="<?= e($edit['description'] ?? '') ?>">
          <label>Owner or grower</label><input name="owner_name" maxlength="160" required value="<?= e($edit['owner_name'] ?? '') ?>">
          <label>Contact information</label><input name="contact" maxlength="250" value="<?= e($edit['contact'] ?? '') ?>">
          <label>Registration date</label><input type="date" name="registration_date" value="<?= e($edit['registration_date'] ?? date('Y-m-d')) ?>">
          <label>Eligibility</label><select name="status"><option value="eligible" <?= ($edit['status'] ?? '') === 'eligible' ? 'selected' : '' ?>>Eligible</option><option value="disqualified" <?= ($edit['status'] ?? '') === 'disqualified' ? 'selected' : '' ?>>Disqualified</option></select>
          <label>Disqualification reason (required when disqualified)</label><input name="disqualification_reason" maxlength="500" value="<?= e($edit['disqualification_reason'] ?? '') ?>">
          <label>Administrative remarks</label><textarea name="remarks" maxlength="1000"><?= e($edit['remarks'] ?? '') ?></textarea>
          <label>Optional photo (JPEG, PNG, WebP; max 5 MB)</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
          <?php if (!empty($edit['photo_path'])): ?><p><a href="index.php?page=photo&id=<?= (int) $edit['id'] ?>" target="_blank" rel="noopener">View existing private photo</a></p><?php endif; ?>
          <p><button><?= $edit ? 'Save changes' : 'Register entry' ?></button> <?php if ($edit): ?><a href="index.php?page=entries">Cancel</a><?php endif; ?></p>
        </form>
      </section>
      <section class="card"><h2>Search registrations</h2><form method="get"><input type="hidden" name="page" value="entries"><label>Search entry, description, or owner</label><input name="q" value="<?= e($query) ?>"><label>Category</label><select name="category"><option value="">All categories</option><?php foreach (categories() as $cat): ?><option value="<?= (int) $cat['id'] ?>" <?= (string) $cat['id'] === $filter ? 'selected' : '' ?>><?= e($cat['name']) ?></option><?php endforeach; ?></select><p><button class="secondary">Filter</button></p></form><p class="muted small">Print labels include entry number and category only; owner details are excluded.</p></section>
    </div>
    <section class="card no-print">
      <h2>Quick intake for plants received</h2>
      <p class="muted">Enter the number of new, actual plants received in each active category. This adds entries; it does not set the category total. Each plant receives a sequential blind entry number.</p>
      <?php if (!$registrationOpen): ?><p class="alert">Open Registration from Workflow before using quick intake. Registration is currently <?= e(str_replace('_', ' ', stage_status('registration'))) ?>.</p><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="quick_register_entries"><input type="hidden" name="return_page" value="entries">
        <div class="table-wrap"><table><thead><tr><th>Category</th><th>Currently eligible</th><th>New plants to add (0–500)</th></tr></thead><tbody>
        <?php foreach (categories(true) as $cat): $currentCounts = $countsByCategory[(int) $cat['id']] ?? []; ?>
          <tr><td><?= e($cat['name']) ?></td><td><?= (int) ($currentCounts['eligible_entries'] ?? 0) ?></td><td><input type="number" name="counts[<?= (int) $cat['id'] ?>]" min="0" max="500" value="0" required aria-label="New plants to add for <?= e($cat['name']) ?>" <?= $registrationOpen ? '' : 'disabled' ?>></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <p><label><input type="checkbox" name="confirm_received" value="1" required <?= $registrationOpen ? '' : 'disabled' ?>> I confirm these counts are plants physically received, not estimates.</label></p>
        <p><button <?= $registrationOpen ? '' : 'disabled' ?>>Create numbered entries</button></p>
      </form>
      <p class="muted small">New entries are eligible for judging and start with plant and owner details marked pending. Complete details later using each entry’s Edit link. You can print entry-number labels after intake.</p>
    </section>
    <section class="card no-print"><h2>Entries (<?= count($entries) ?>)</h2><div class="table-wrap"><table><thead><tr><th>Entry number</th><th>Category</th><th>Plant</th><th>Owner</th><th>Contact</th><th>Eligibility</th><th class="no-print">Action</th></tr></thead><tbody>
    <?php foreach ($entries as $entry): ?><tr><td class="entry-label"><strong><?= e($entry['entry_number']) ?></strong></td><td><?= e($entry['category_name']) ?></td><td><?= e($entry['description']) ?></td><td><?= e($entry['owner_name']) ?></td><td><?= e($entry['contact']) ?></td><td><span class="badge <?= e($entry['status']) ?>"><?= e(ucfirst($entry['status'])) ?></span><?php if ($entry['status'] === 'disqualified'): ?><br><span class="small"><?= e($entry['disqualification_reason']) ?></span><?php endif; ?></td><td class="no-print"><a href="index.php?page=entries&edit=<?= (int) $entry['id'] ?>">Edit</a><?php if ($registrationOpen): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="delete_entry"><input type="hidden" name="return_page" value="entries"><input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>"><input name="reason" maxlength="500" placeholder="Deletion reason" aria-label="Deletion reason for <?= e($entry['entry_number']) ?>" required><button class="danger" data-confirm="Permanently delete <?= e($entry['entry_number']) ?>? Its number will not be reused.">Delete</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
    <div class="print-only"><h2>Plant entry labels</h2><?php foreach ($entries as $entry): ?><div style="display:inline-block;border:1px solid #444;padding:12px;margin:8px;min-width:220px"><strong><?= e($entry['entry_number']) ?></strong><br><?= e($entry['category_name']) ?></div><?php endforeach; ?></div>
    <?php
}

function page_judging(array $user): void
{
    if ($user['role'] === 'judge') {
        page_judge_form($user);
        return;
    }
    require_admin();
    $rows = db()->query(
        'SELECT je.*, u.display_name, c.name AS category_name, c.id AS cat_id,
                (SELECT COUNT(*) FROM judge_rankings jr WHERE jr.evaluation_id = je.id) AS selected_count
         FROM judge_evaluations je JOIN users u ON u.id = je.judge_id JOIN categories c ON c.id = je.category_id
         ORDER BY c.position, u.display_name'
    )->fetchAll();
    ?>
    <h1>Judge Submissions</h1><p class="sub">Review original selections and rankings. Reopening requires a recorded reason.</p>
    <section class="card"><div class="table-wrap"><table><thead><tr><th>Judge</th><th>Category</th><th>Selections</th><th>Status</th><th>Submitted</th><th>Original rankings</th><th>Reopen</th></tr></thead><tbody>
    <?php foreach ($rows as $row): $rankStmt = db()->prepare('SELECT e.entry_number, jr.rank FROM judge_rankings jr JOIN entries e ON e.id = jr.entry_id WHERE jr.evaluation_id = ? ORDER BY jr.rank'); $rankStmt->execute([$row['id']]); $rankData = $rankStmt->fetchAll(); ?>
      <tr><td><?= e($row['display_name']) ?></td><td><?= e($row['category_name']) ?></td><td><?= (int) $row['selected_count'] ?>/5</td><td><span class="badge <?= e($row['status']) ?>"><?= e(ucfirst($row['status'])) ?></span></td><td><?= e(format_datetime($row['submitted_at'])) ?></td><td><?php foreach ($rankData as $r): ?><?= (int) $r['rank'] ?>. <?= e($r['entry_number']) ?><br><?php endforeach; ?></td><td><?php if ($row['status'] === 'submitted'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reopen_evaluation"><input type="hidden" name="return_page" value="judging"><input type="hidden" name="evaluation_id" value="<?= (int) $row['id'] ?>"><input name="reason" placeholder="Reason required" required><button class="danger">Reopen</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div></section>
    <?php
}

function page_judge_form(array $user): void
{
    $categoryId = (int) ($_GET['category'] ?? 0);
    $assigned = db()->prepare('SELECT c.* FROM judge_assignments ja JOIN categories c ON c.id = ja.category_id WHERE ja.judge_id = ? ORDER BY c.position');
    $assigned->execute([$user['id']]);
    $assignedCategories = $assigned->fetchAll();
    if ($categoryId < 1 && $assignedCategories) {
        $categoryId = (int) $assignedCategories[0]['id'];
    }
    $category = null;
    foreach ($assignedCategories as $cat) {
        if ((int) $cat['id'] === $categoryId) {
            $category = $cat;
        }
    }
    if (!$category) {
        echo '<h1>My Judging</h1><p class="alert error">No assigned categories are available.</p>';
        return;
    }
    $stmt = db()->prepare('SELECT id, entry_number FROM entries WHERE category_id = ? AND status = \'eligible\' ORDER BY entry_number');
    $stmt->execute([$categoryId]);
    $entries = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT * FROM judge_evaluations WHERE judge_id = ? AND category_id = ?');
    $stmt->execute([$user['id'], $categoryId]);
    $evaluation = $stmt->fetch();
    $selected = [];
    if ($evaluation) {
        $stmt = db()->prepare('SELECT entry_id, rank FROM judge_rankings WHERE evaluation_id = ?');
        $stmt->execute([$evaluation['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $selected[(int) $row['entry_id']] = (int) $row['rank'];
        }
    }
    ?>
    <h1>My Judging</h1><p class="sub">Blind evaluation · select five eligible entries and assign each rank 1–5. Registration identities are not returned to this interface.</p>
    <div class="row"><?php foreach ($assignedCategories as $cat): ?><a class="button <?= $categoryId === (int) $cat['id'] ? '' : 'secondary' ?>" href="index.php?page=judging&category=<?= (int) $cat['id'] ?>"><?= e($cat['name']) ?></a><?php endforeach; ?></div>
    <section class="card"><div class="spread"><h2><?= e($category['name']) ?></h2><span class="badge <?= e($evaluation['status'] ?? 'not_started') ?>"><?= e(ucfirst($evaluation['status'] ?? 'not started')) ?></span></div>
      <?php if (!stage_open('judging')): ?><p class="alert">Judging is currently <?= e(str_replace('_', ' ', stage_status('judging'))) ?>. You can view, but not change, this category.</p><?php endif; ?>
      <?php if ($evaluation && $evaluation['status'] === 'submitted'): ?><p class="alert">Submitted <?= e(format_datetime($evaluation['submitted_at'])) ?>. This evaluation is locked. Contact the administrator if it needs authorized reopening.</p><?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_judging"><input type="hidden" name="return_page" value="judging"><input type="hidden" name="category_id" value="<?= $categoryId ?>">
      <label for="entry-search">Search by entry number</label><input id="entry-search" data-filter="judge-entry-list" type="search" placeholder="Type an entry number">
      <div class="table-wrap"><table id="judge-entry-list"><thead><tr><th>Entry number</th><th>Your rank</th></tr></thead><tbody>
      <?php foreach ($entries as $entry): ?><tr data-filter-item><td><?= e($entry['entry_number']) ?></td><td><select class="entry-rank" name="rank[<?= (int) $entry['id'] ?>]" <?= !$evaluation || $evaluation['status'] !== 'submitted' ? '' : 'disabled' ?>><option value="">Not selected</option><?php foreach (range(1, 5) as $rank): ?><option value="<?= $rank ?>" <?= ($selected[(int) $entry['id']] ?? 0) === $rank ? 'selected' : '' ?>><?= $rank ?><?= ['st','nd','rd','th','th'][$rank - 1] ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php if (count($entries) < 5): ?><p class="alert error">Only <?= count($entries) ?> eligible entries are available. Contact the administrator; nonexistent finalists will not be created.</p><?php endif; ?>
      <?php if (stage_open('judging') && (!$evaluation || $evaluation['status'] !== 'submitted') && count($entries) >= 5): ?><p><button name="submit_state" value="draft" class="secondary">Save draft</button> <button name="submit_state" value="submitted" data-confirm="Submit and lock this category evaluation?">Submit evaluation</button></p><?php endif; ?>
      </form>
    </section>
    <?php
}

function page_finalists(): void
{
    require_admin();
    $allCategories = categories();
    ?>
    <h1>Finalist Consolidation</h1><p class="sub">Active method: <strong><?= e(setting('consolidation_method')) ?></strong><?php if (setting('consolidation_method') === 'points_5_to_1'): ?> · points by rank: <?= e(implode(', ', rank_points())) ?><?php endif; ?>. Confirm the method with the organizer; ties are displayed and require an administrator decision.</p>
    <?php foreach ($allCategories as $category):
        $scores = consolidated_scores((int) $category['id']);
        $existing = db()->prepare('SELECT f.entry_id, f.finalist_rank, e.entry_number FROM finalists f JOIN entries e ON e.id = f.entry_id WHERE f.category_id = ? ORDER BY f.finalist_rank');
        $existing->execute([$category['id']]);
        $confirmed = $existing->fetchAll();
        $scoreAtCutoff = $scores[4]['score'] ?? null;
        $tie = $scoreAtCutoff !== null && count(array_filter($scores, static fn(array $r): bool => abs($r['score'] - $scoreAtCutoff) < 0.00001)) > 1;
    ?>
    <section class="card"><div class="spread"><h2><?= e($category['name']) ?></h2><span class="badge"><?= count($confirmed) ?>/5 confirmed</span></div>
      <?php if ($tie): ?><p class="alert error">Tie at the proposed fifth-place cutoff. Select finalists and ranks explicitly; record the authorized tie-resolution reason.</p><?php endif; ?>
      <?php if (count($scores) < 5): ?><p class="alert error">Only <?= count($scores) ?> unique eligible entries have received submitted judge selections. Five finalists cannot be confirmed yet.</p><?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_finalists"><input type="hidden" name="return_page" value="finalists"><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
      <div class="table-wrap"><table><thead><tr><th>Include</th><th>Entry</th><th>Consolidated result</th><th>Judges</th><th>Proposed rank</th><th>Finalist rank</th></tr></thead><tbody>
      <?php foreach ($scores as $index => $score):
          $chosenRank = 0;
          foreach ($confirmed as $f) {
              if ((int) $f['entry_id'] === $score['id']) {
                  $chosenRank = (int) $f['finalist_rank'];
              }
          }
          $cutoffTieEntry = $tie && abs($score['score'] - $scoreAtCutoff) < 0.00001;
          $defaultSelected = !$confirmed && $index < 5 && !$cutoffTieEntry;
          $defaultRank = $defaultSelected ? $index + 1 : 0;
      ?>
        <tr><td><input type="checkbox" name="entry_ids[]" value="<?= $score['id'] ?>" <?= $chosenRank || $defaultSelected ? 'checked' : '' ?>></td><td><?= e($score['entry_number']) ?></td><td><?= e($score['score_label']) ?> · average rank <?= number_format($score['average_rank'], 2) ?></td><td><?= (int) $score['judge_count'] ?></td><td><?= $index + 1 ?><?= $index > 0 && abs($score['score'] - $scores[$index - 1]['score']) < 0.00001 ? ' (tie)' : '' ?></td><td><select name="finalist_rank[<?= $score['id'] ?>]"><option value="">—</option><?php foreach (range(1, 5) as $rank): ?><option value="<?= $rank ?>" <?= ($chosenRank ?: $defaultRank) === $rank ? 'selected' : '' ?>><?= $rank ?></option><?php endforeach; ?></select></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php if (stage_open('finalist_confirmation') && count($scores) >= 5): ?><label>Confirmation / tie resolution reason (required)</label><input name="reason" required maxlength="500" placeholder="Explain any tie resolution or administrator confirmation"><p><button>Confirm five finalists</button></p><?php else: ?><p class="muted">Finalist confirmation stage must be open to save.</p><?php endif; ?>
      </form>
    </section>
    <?php endforeach;
}

function page_deliberation(array $user): void
{
    if ($user['role'] === 'judge') {
        page_judge_deliberation($user);
        return;
    }
    require_admin();
    ?>
    <h1>Category Deliberation</h1><p class="sub">Top-three finalists only. Original judge rankings are preserved in the submission records.</p>
    <?php foreach (categories() as $category):
        $top = db()->prepare('SELECT f.entry_id, f.finalist_rank, e.entry_number FROM finalists f JOIN entries e ON e.id = f.entry_id WHERE f.category_id = ? AND f.finalist_rank <= 3 ORDER BY f.finalist_rank');
        $top->execute([$category['id']]);
        $topEntries = $top->fetchAll();
        $d = db()->prepare('SELECT * FROM deliberations WHERE category_id = ?');
        $d->execute([$category['id']]);
        $record = $d->fetch() ?: [];
    ?>
    <section class="card"><div class="spread"><h2><?= e($category['name']) ?></h2><span class="badge <?= e($record['status'] ?? 'not_started') ?>"><?= e($record['status'] ?? 'not started') ?></span></div>
      <div class="table-wrap"><table><thead><tr><th>Finalist rank</th><th>Entry number</th><th>Original judge rankings</th><th>Judge justifications</th></tr></thead><tbody>
      <?php foreach ($topEntries as $entry): $r = db()->prepare('SELECT u.display_name, jr.rank FROM judge_rankings jr JOIN judge_evaluations je ON je.id = jr.evaluation_id JOIN users u ON u.id = je.judge_id WHERE je.category_id = ? AND je.status = \'submitted\' AND jr.entry_id = ? ORDER BY u.display_name'); $r->execute([$category['id'], $entry['entry_id']]); $rankingRows = $r->fetchAll(); $n = db()->prepare('SELECT u.display_name, dn.notes FROM deliberation_notes dn JOIN users u ON u.id = dn.judge_id WHERE dn.category_id = ? AND dn.entry_id = ?'); $n->execute([$category['id'], $entry['entry_id']]); ?>
        <tr><td><?= (int) $entry['finalist_rank'] ?></td><td><?= e($entry['entry_number']) ?></td><td><?php foreach ($rankingRows as $rr): ?><?= e($rr['display_name']) ?>: #<?= (int) $rr['rank'] ?><br><?php endforeach; ?></td><td><?php foreach ($n->fetchAll() as $note): ?><strong><?= e($note['display_name']) ?>:</strong> <?= e($note['notes']) ?><br><?php endforeach; ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_deliberation"><input type="hidden" name="return_page" value="deliberation"><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
        <label>Discussion / deliberation notes</label><textarea name="discussion_notes" maxlength="5000"><?= e($record['discussion_notes'] ?? '') ?></textarea>
        <label>Administrator remarks</label><textarea name="administrator_remarks" maxlength="2000"><?= e($record['administrator_remarks'] ?? '') ?></textarea>
        <label><input type="checkbox" name="completed" <?= ($record['status'] ?? '') === 'completed' ? 'checked' : '' ?>> Mark deliberation complete</label><button>Save deliberation</button>
      </form>
    </section>
    <?php endforeach;
}

function page_judge_deliberation(array $user): void
{
    ?>
    <h1>My Deliberation Notes</h1><p class="sub">Notes are limited to your assigned categories and the top three finalists.</p>
    <?php foreach (categories(true) as $category):
        if (!can_judge_category($user, (int) $category['id'])) { continue; }
        $stmt = db()->prepare('SELECT f.entry_id, f.finalist_rank, e.entry_number FROM finalists f JOIN entries e ON e.id = f.entry_id WHERE f.category_id = ? AND f.finalist_rank <= 3 ORDER BY f.finalist_rank');
        $stmt->execute([$category['id']]);
    ?>
    <section class="card"><h2><?= e($category['name']) ?></h2>
      <?php foreach ($stmt->fetchAll() as $entry): $old = db()->prepare('SELECT notes FROM deliberation_notes WHERE category_id = ? AND entry_id = ? AND judge_id = ?'); $old->execute([$category['id'], $entry['entry_id'], $user['id']]); ?>
        <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="save_justification"><input type="hidden" name="return_page" value="deliberation"><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>"><input type="hidden" name="entry_id" value="<?= (int) $entry['entry_id'] ?>"><strong><?= (int) $entry['finalist_rank'] ?> · <?= e($entry['entry_number']) ?></strong><label>Your justification</label><textarea name="notes" maxlength="2000" required><?= e($old->fetchColumn() ?: '') ?></textarea><button <?= stage_open('deliberation') ? '' : 'disabled' ?>>Save note</button></form>
      <?php endforeach; ?>
    </section>
    <?php endforeach;
}

function page_results(): void
{
    require_admin();
    ?>
    <h1>Official Category Results</h1><p class="sub">Final placements are stored separately and do not overwrite original selections or consolidated finalist ranks.</p>
    <?php foreach (categories() as $category):
        $stmt = db()->prepare('SELECT f.entry_id, f.finalist_rank, e.entry_number, r.placement FROM finalists f JOIN entries e ON e.id = f.entry_id LEFT JOIN final_category_results r ON r.category_id = f.category_id AND r.entry_id = f.entry_id WHERE f.category_id = ? ORDER BY f.finalist_rank');
        $stmt->execute([$category['id']]);
        $rows = $stmt->fetchAll();
    ?>
    <section class="card"><h2><?= e($category['name']) ?></h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_category_results"><input type="hidden" name="return_page" value="results"><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
      <div class="table-wrap"><table><thead><tr><th>Finalist rank</th><th>Entry number</th><th>Final official placement</th></tr></thead><tbody>
      <?php foreach ($rows as $row): ?><tr><td><?= (int) $row['finalist_rank'] ?></td><td><?= e($row['entry_number']) ?></td><td><select name="placement[<?= (int) $row['entry_id'] ?>]"><option value="">Not assigned</option><?php foreach (range(1, 5) as $place): ?><option value="<?= $place ?>" <?= (int) $row['placement'] === $place ? 'selected' : '' ?>><?= $place ?><?= ['st','nd','rd','th','th'][$place - 1] ?> Place</option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php if ($rows): ?><label>Administrator confirmation reason</label><input name="reason" maxlength="500" placeholder="Optional confirmation notes"><p><button <?= stage_open('final_ranking') ? '' : 'disabled' ?>>Confirm official rankings</button></p><?php else: ?><p class="muted">No finalists confirmed for this category yet.</p><?php endif; ?>
      </form></section>
    <?php endforeach;
}

function page_best_in_show(array $user): void
{
    $candidates = db()->query(
        'SELECT r.entry_id, e.entry_number, c.name AS category_name
         FROM final_category_results r JOIN entries e ON e.id = r.entry_id
         JOIN categories c ON c.id = r.category_id WHERE r.placement = 1 ORDER BY c.position'
    )->fetchAll();
    $winner = db()->query('SELECT entry_id FROM best_in_show_result WHERE id = 1')->fetchColumn();
    if ($user['role'] === 'judge') {
        $stmt = db()->prepare('SELECT entry_id, notes FROM best_in_show_evaluations WHERE judge_id = ?');
        $stmt->execute([$user['id']]);
        $myVote = $stmt->fetch() ?: [];
        ?>
        <h1>Best in Show Selection</h1><p class="sub">Choose from the approved first-place category winners. Candidate identities remain blinded to entry numbers.</p>
        <?php if (setting('best_in_show_method') !== 'judge_selection'): ?><section class="card"><p>The configured method is administrator decision after deliberation. No judge ballot is open.</p></section><?php return; endif; ?>
        <section class="card"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_bis_vote"><input type="hidden" name="return_page" value="best_in_show">
          <label>Candidate</label><select name="entry_id" required><option value="">Choose one</option><?php foreach ($candidates as $candidate): ?><option value="<?= (int) $candidate['entry_id'] ?>" <?= (int) ($myVote['entry_id'] ?? 0) === (int) $candidate['entry_id'] ? 'selected' : '' ?>><?= e($candidate['entry_number']) ?> · <?= e($candidate['category_name']) ?></option><?php endforeach; ?></select>
          <label>Optional notes</label><textarea name="notes" maxlength="1000"><?= e($myVote['notes'] ?? '') ?></textarea><button <?= stage_open('best_in_show') && count($candidates) === 8 ? '' : 'disabled' ?>>Save selection</button>
        </form></section>
        <?php
        return;
    }
    require_admin();
    ?>
    <h1>Best in Show</h1><p class="sub">The candidate list is generated from approved first-place category results. Active method: <?= e(setting('best_in_show_method')) ?>.</p>
    <?php if (count($candidates) !== 8): ?><p class="alert error">Expected eight approved category winners; currently <?= count($candidates) ?>. Complete category rankings first.</p><?php endif; ?>
    <section class="card"><div class="table-wrap"><table><thead><tr><th>Category</th><th>Candidate entry</th><th>Judge selections</th><th>Result</th></tr></thead><tbody>
    <?php foreach ($candidates as $candidate): $v = db()->prepare('SELECT u.display_name, b.notes FROM best_in_show_evaluations b JOIN users u ON u.id = b.judge_id WHERE b.entry_id = ?'); $v->execute([$candidate['entry_id']]); ?><tr><td><?= e($candidate['category_name']) ?></td><td><?= e($candidate['entry_number']) ?></td><td><?php foreach ($v->fetchAll() as $vote): ?><?= e($vote['display_name']) ?><?= $vote['notes'] ? ': ' . e($vote['notes']) : '' ?><br><?php endforeach; ?></td><td><?= (int) $winner === (int) $candidate['entry_id'] ? '<strong>Best in Show</strong>' : 'Candidate' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_bis"><input type="hidden" name="return_page" value="best_in_show"><label>Administrator-confirmed winner</label><select name="entry_id" required><option value="">Select one category winner</option><?php foreach ($candidates as $candidate): ?><option value="<?= (int) $candidate['entry_id'] ?>" <?= (int) $winner === (int) $candidate['entry_id'] ? 'selected' : '' ?>><?= e($candidate['entry_number']) ?> · <?= e($candidate['category_name']) ?></option><?php endforeach; ?></select><label>Confirmation reason</label><input name="reason" maxlength="500" required><p><button <?= stage_open('best_in_show') && count($candidates) === 8 ? '' : 'disabled' ?>>Confirm Best in Show</button></p></form></section>
    <?php
}

function page_workflow(): void
{
    require_admin();
    $stages = db()->query('SELECT * FROM stages ORDER BY rowid')->fetchAll();
    ?>
    <h1>Competition Workflow</h1><p class="sub">Open and lock the required stages. Overrides are recorded in the audit log; dependent data should be reviewed before advancing.</p>
    <section class="card"><div class="table-wrap"><table><thead><tr><th>Stage</th><th>Current status</th><th>Change status</th></tr></thead><tbody>
    <?php foreach ($stages as $stage): ?><tr><td><?= e($stage['label']) ?></td><td><span class="badge <?= e($stage['status']) ?>"><?= e(ucfirst(str_replace('_', ' ', $stage['status']))) ?></span></td><td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="set_stage"><input type="hidden" name="return_page" value="workflow"><input type="hidden" name="stage_key" value="<?= e($stage['stage_key']) ?>"><select name="status"><?php foreach (['not_started', 'in_progress', 'pending_review', 'completed', 'locked'] as $status): ?><option value="<?= $status ?>" <?= $stage['status'] === $status ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $status)) ?></option><?php endforeach; ?></select><input name="reason" placeholder="Completion note / override reason"><button>Update</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
    <?php
}

function page_users(): void
{
    require_admin();
    ?>
    <h1>User Accounts and Assignments</h1><p class="sub">Passwords are stored as secure hashes. Judges can only access their assigned categories.</p>
    <div class="grid two"><section class="card"><h2>Create account</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create_user"><input type="hidden" name="return_page" value="users">
      <label>Username</label><input name="username" required minlength="3" maxlength="80"><label>Display name</label><input name="display_name" required maxlength="120"><label>Initial password (at least 12 characters)</label><input type="password" name="password" required minlength="12"><label>Role</label><select name="role"><option value="judge">Plant Category Judge</option><option value="tabulator">Tabulator</option></select>
      <h3>Judge category assignments</h3><div class="check-list"><?php foreach (categories() as $category): ?><label><input type="checkbox" name="categories[]" value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></label><?php endforeach; ?></div>
      <label><input type="checkbox" name="reports_permission"> Allow Tabulator to view reports</label><p><button>Create account</button></p></form></section>
    <section class="card"><h2>Existing accounts</h2><div class="table-wrap"><table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Assignments</th><th>Status</th></tr></thead><tbody>
    <?php foreach (db()->query('SELECT id, username, display_name, role, active FROM users ORDER BY role, display_name')->fetchAll() as $account): $asg = db()->prepare('SELECT c.name FROM judge_assignments ja JOIN categories c ON c.id = ja.category_id WHERE ja.judge_id = ? ORDER BY c.position'); $asg->execute([$account['id']]); ?><tr><td><?= e($account['display_name']) ?></td><td><?= e($account['username']) ?></td><td><?= e($account['role']) ?></td><td><?= e(implode(', ', array_column($asg->fetchAll(), 'name'))) ?></td><td><?= $account['active'] ? 'Active' : 'Inactive' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section></div>
    <section class="card"><h2>Account access</h2><div class="table-wrap"><table><thead><tr><th>Account</th><th>Role</th><th>State</th><th>Action</th></tr></thead><tbody>
    <?php foreach (db()->query("SELECT id, username, display_name, role, active FROM users WHERE role != 'super_admin' ORDER BY display_name")->fetchAll() as $account): ?><tr><td><?= e($account['display_name']) ?> (<?= e($account['username']) ?>)</td><td><?= e($account['role']) ?></td><td><?= $account['active'] ? 'Active' : 'Inactive' ?></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="return_page" value="users"><input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>"><input name="reason" placeholder="Reason"><button class="secondary"><?= $account['active'] ? 'Deactivate' : 'Activate' ?></button></form></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
    <section class="card"><h2>Category configuration</h2><div class="table-wrap"><table><thead><tr><th>Category</th><th>Prefix</th><th>Status</th><th>Change status</th></tr></thead><tbody><?php foreach (categories() as $cat): ?><tr><td><?= e($cat['name']) ?></td><td><?= e($cat['prefix']) ?></td><td><?= $cat['active'] ? 'Active' : 'Inactive' ?></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_category"><input type="hidden" name="return_page" value="users"><input type="hidden" name="category_id" value="<?= (int) $cat['id'] ?>"><input name="reason" placeholder="Change reason"><button class="secondary"><?= $cat['active'] ? 'Deactivate' : 'Activate' ?></button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php
}

function page_settings(): void
{
    require_admin();
    $points = rank_points();
    ?>
    <h1>Judging Settings</h1><p class="sub">The selected scoring method is configurable and must be confirmed by the organizer. No automatic tie-break is applied.</p>
    <div class="grid two"><section class="card"><h2>Consolidation method</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_settings"><input type="hidden" name="return_page" value="settings">
      <label>Ranking consolidation</label><select name="consolidation_method"><option value="points_5_to_1" <?= setting('consolidation_method') === 'points_5_to_1' ? 'selected' : '' ?>>Points (rank points below)</option><option value="average_rank" <?= setting('consolidation_method') === 'average_rank' ? 'selected' : '' ?>>Average rank (lower is better)</option></select>
      <div class="grid"><?php foreach (range(1, 5) as $rank): ?><div><label><?= $rank ?><?= ['st','nd','rd','th','th'][$rank - 1] ?> rank points</label><input type="number" min="0" max="100" name="points[<?= $rank ?>]" value="<?= (int) $points[$rank - 1] ?>"></div><?php endforeach; ?></div>
      <label>Best in Show method</label><select name="best_in_show_method"><option value="administrator_decision" <?= setting('best_in_show_method') === 'administrator_decision' ? 'selected' : '' ?>>Administrator decision after deliberation</option><option value="judge_selection" <?= setting('best_in_show_method') === 'judge_selection' ? 'selected' : '' ?>>Judge selection (administrator confirms)</option></select><p><button>Save judging settings</button></p></form></section>
      <section class="card"><h2>Public display</h2><p>Only approved results are published. Owner names, when enabled, are shown only on public results and never to judges.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_publication"><input type="hidden" name="return_page" value="settings"><label><input type="checkbox" name="public_owner_names" <?= setting('public_owner_names') === '1' ? 'checked' : '' ?>> Allow owner names on the public display</label><button>Save display setting</button></form></section></div>
    <?php
}

function page_audit(): void
{
    require_admin();
    $logs = db()->query('SELECT a.*, u.display_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 300')->fetchAll();
    ?>
    <h1>Audit Log</h1><p class="sub">Recent administrative and judging actions.</p><section class="card"><div class="table-wrap"><table><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Record</th><th>Reason</th><th>Change details</th></tr></thead><tbody>
    <?php foreach ($logs as $log): ?><tr><td><?= e(format_datetime($log['created_at'])) ?></td><td><?= e($log['display_name'] ?? 'System') ?></td><td><?= e($log['action']) ?></td><td><?= e($log['entity_type']) ?> <?= e($log['entity_id']) ?></td><td><?= e($log['reason']) ?></td><td><details><summary>View</summary><pre><?= e($log['previous_value'] ?? '') ?> → <?= e($log['new_value'] ?? '') ?></pre></details></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
    <?php
}

function page_reports(): void
{
    $user = require_login();
    if (!can_view_reports($user)) {
        http_response_code(403);
        exit('Reports are not enabled for this account.');
    }
    $reports = $user['role'] === 'super_admin'
        ? [
            'registrations' => 'Plant registration list (owner data: administrator only)',
            'judging' => 'Individual judge selections and rankings',
            'finalists' => 'Consolidated finalists and scoring',
            'official' => 'Official category results and Best in Show',
        ]
        : [
            'judging' => 'Individual judge selections and rankings',
            'finalists' => 'Consolidated finalists and scoring',
            'official' => 'Official category results and Best in Show',
        ];
    ?>
    <h1>Reports and Exports</h1>    <p class="sub">CSV files open in Excel. Print-friendly reports support “Save as PDF” in the browser print dialog.</p>
    <div class="grid"><?php foreach ($reports as $key => $label): ?><section class="card"><h2><?= e($label) ?></h2><p><a class="button" href="index.php?page=export&report=<?= e($key) ?>">Download CSV</a> <a class="button secondary" href="index.php?page=print_report&report=<?= e($key) ?>" target="_blank" rel="noopener">Open print view</a></p></section><?php endforeach; ?></div>
    <p class="muted small">Exports are permission checked. This local build uses dependency-free CSV for Excel compatibility; PDF output is available through browser printing.</p>
    <?php
}

function render_public(): void
{
    if (setting('published') !== '1') {
        http_response_code(200);
        ?>
        <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Floriculture Competition Results</title><link rel="stylesheet" href="assets/app.css"><script src="assets/app.js" defer></script></head><body><main class="wrap"><section class="card public-pending"><h1>Floriculture Plants Competition</h1><p class="sub">Official results have not been published.</p></section></main></body></html>
        <?php
        return;
    }
    $showOwners = setting('public_owner_names') === '1';
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta http-equiv="refresh" content="60"><title>Official Floriculture Competition Results</title><link rel="stylesheet" href="assets/app.css"><script src="assets/app.js" defer></script></head><body><header class="topbar"><strong>Floriculture Plants Competition · Official Results</strong><a href="index.php?page=public">Refresh</a></header><main class="wrap">
    <div class="spread"><div><h1>Official Competition Results</h1><p class="sub">Administrator-approved results</p></div><button type="button" class="no-print" data-print>Print / Save PDF</button></div>
    <?php foreach (categories(true) as $category): $stmt = db()->prepare('SELECT r.placement, e.entry_number, e.owner_name FROM final_category_results r JOIN entries e ON e.id = r.entry_id WHERE r.category_id = ? ORDER BY r.placement'); $stmt->execute([$category['id']]); $rows = $stmt->fetchAll(); ?>
      <section class="card"><h2><?= e($category['name']) ?></h2><div class="grid"><?php foreach ($rows as $row): ?><div class="card"><div class="metric"><?= (int) $row['placement'] ?><?= ['st','nd','rd','th','th'][$row['placement'] - 1] ?></div><h3><?= e($row['entry_number']) ?><?= (int) $row['placement'] === 1 ? ' · Category Winner' : '' ?></h3><?php if ($showOwners): ?><span><?= e($row['owner_name']) ?></span><?php endif; ?></div><?php endforeach; ?></div></section>
    <?php endforeach; ?>
    <?php $bis = db()->query('SELECT e.entry_number, e.owner_name, c.name AS category_name FROM best_in_show_result b JOIN entries e ON e.id = b.entry_id JOIN categories c ON c.id = e.category_id WHERE b.id = 1')->fetch(); if ($bis): ?><section class="card" style="text-align:center"><h2>Best in Show</h2><div class="metric"><?= e($bis['entry_number']) ?></div><p><?= e($bis['category_name']) ?><?= $showOwners ? ' · ' . e($bis['owner_name']) : '' ?></p></section><?php endif; ?>
    </main></body></html>
    <?php
}
