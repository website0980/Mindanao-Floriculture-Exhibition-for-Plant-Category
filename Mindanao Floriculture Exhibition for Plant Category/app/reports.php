<?php
declare(strict_types=1);

function csv_value(mixed $value): string
{
    $text = (string) $value;
    if (preg_match('/^[\s]*[=+\-@]/u', $text)) {
        return "'" . $text;
    }
    return $text;
}

function send_export(array $user, string $report): never
{
    if (!can_view_reports($user)) {
        http_response_code(403);
        exit('Reports are not enabled for this account.');
    }
    if ($report === 'registrations' && $user['role'] !== 'super_admin') {
        http_response_code(403);
        exit('Private registration reports are restricted to the Super Administrator.');
    }
    $exports = [
        'registrations' => [
            'filename' => 'floriculture-registrations.csv',
            'sql' => 'SELECT e.entry_number, c.name AS category, e.description, e.owner_name, e.contact,
                             e.registration_date, e.status, e.disqualification_reason, e.remarks
                      FROM entries e JOIN categories c ON c.id = e.category_id
                      ORDER BY c.position, e.entry_number',
        ],
        'judging' => [
            'filename' => 'floriculture-judge-rankings.csv',
            'sql' => 'SELECT u.display_name AS judge, c.name AS category, e.entry_number, jr.rank AS placement,
                             je.submitted_at
                      FROM judge_rankings jr
                      JOIN judge_evaluations je ON je.id = jr.evaluation_id AND je.status = \'submitted\'
                      JOIN users u ON u.id = je.judge_id
                      JOIN categories c ON c.id = je.category_id
                      JOIN entries e ON e.id = jr.entry_id
                      ORDER BY c.position, u.display_name, jr.rank',
        ],
        'finalists' => [
            'filename' => 'floriculture-finalists.csv',
            'sql' => 'SELECT c.name AS category, e.entry_number, f.finalist_rank,
                             COUNT(DISTINCT je.judge_id) AS selecting_judges,
                             SUM(CASE jr.rank WHEN 1 THEN 5 WHEN 2 THEN 4 WHEN 3 THEN 3 WHEN 4 THEN 2 WHEN 5 THEN 1 ELSE 0 END) AS points
                      FROM finalists f
                      JOIN categories c ON c.id = f.category_id
                      JOIN entries e ON e.id = f.entry_id
                      LEFT JOIN judge_rankings jr ON jr.entry_id = e.id
                      LEFT JOIN judge_evaluations je ON je.id = jr.evaluation_id AND je.status = \'submitted\'
                      GROUP BY c.id, e.id, f.finalist_rank ORDER BY c.position, f.finalist_rank',
        ],
        'official' => [
            'filename' => 'floriculture-official-results.csv',
            'sql' => 'SELECT c.name AS category, e.entry_number, r.placement,
                             CASE WHEN r.placement = 1 THEN \'Category Winner\' ELSE \'\' END AS award,
                             CASE WHEN b.entry_id = e.id THEN \'Best in Show\' ELSE \'\' END AS best_in_show
                      FROM final_category_results r
                      JOIN categories c ON c.id = r.category_id
                      JOIN entries e ON e.id = r.entry_id
                      LEFT JOIN best_in_show_result b ON b.entry_id = e.id
                      ORDER BY c.position, r.placement',
        ],
    ];
    if (!isset($exports[$report])) {
        http_response_code(404);
        exit('Report not found.');
    }
    $output = fopen('php://temp', 'w+b');
    if (!$output) {
        throw new RuntimeException('Could not create an export stream.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    if ($report === 'finalists') {
        fputcsv($output, ['category', 'entry_number', 'finalist_rank', 'judges_selecting', 'consolidated_result'], ',', '"', '');
        foreach (categories() as $category) {
            $scores = [];
            foreach (consolidated_scores((int) $category['id']) as $score) {
                $scores[$score['id']] = $score;
            }
            $stmt = db()->prepare(
                'SELECT e.id, e.entry_number, f.finalist_rank FROM finalists f
                 JOIN entries e ON e.id = f.entry_id WHERE f.category_id = ? ORDER BY f.finalist_rank'
            );
            $stmt->execute([$category['id']]);
            foreach ($stmt->fetchAll() as $row) {
                $score = $scores[(int) $row['id']] ?? null;
                fputcsv($output, array_map('csv_value', [
                    $category['name'], $row['entry_number'], $row['finalist_rank'],
                    $score['judge_count'] ?? 0, $score['score_label'] ?? '',
                ]), ',', '"', '');
            }
        }
    } else {
        $stmt = db()->query($exports[$report]['sql']);
        $headers = [];
        for ($column = 0; $column < $stmt->columnCount(); $column++) {
            $metadata = $stmt->getColumnMeta($column);
            $headers[] = $metadata['name'] ?? '';
        }
        fputcsv($output, array_map('csv_value', $headers), ',', '"', '');
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            fputcsv($output, array_map('csv_value', array_values($row)), ',', '"', '');
        }
    }
    rewind($output);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $exports[$report]['filename'] . '"');
    header('X-Content-Type-Options: nosniff');
    fpassthru($output);
    fclose($output);
    exit;
}

function render_print_report(array $user, string $report): never
{
    if (!can_view_reports($user) || !in_array($report, ['registrations', 'judging', 'finalists', 'official'], true)) {
        http_response_code(403);
        exit('This report is not available to your account.');
    }
    if ($report === 'registrations' && $user['role'] !== 'super_admin') {
        http_response_code(403);
        exit('Private registration reports are restricted to the Super Administrator.');
    }
    $titles = [
        'registrations' => 'Plant Registration Report',
        'judging' => 'Individual Judge Selections',
        'finalists' => 'Consolidated Finalists',
        'official' => 'Official Competition Results',
    ];
    $headers = [];
    $rows = [];
    if ($report === 'registrations') {
        $headers = ['Entry', 'Category', 'Plant description', 'Owner', 'Contact', 'Registration date', 'Status'];
        $rows = db()->query(
            'SELECT e.entry_number, c.name AS category, e.description, e.owner_name, e.contact,
                    e.registration_date, e.status
             FROM entries e JOIN categories c ON c.id = e.category_id ORDER BY c.position, e.entry_number'
        )->fetchAll(PDO::FETCH_NUM);
    } elseif ($report === 'judging') {
        $headers = ['Judge', 'Category', 'Entry', 'Rank', 'Submitted at'];
        $rows = db()->query(
            'SELECT u.display_name, c.name, e.entry_number, jr.rank, je.submitted_at
             FROM judge_rankings jr
             JOIN judge_evaluations je ON je.id = jr.evaluation_id AND je.status = \'submitted\'
             JOIN users u ON u.id = je.judge_id JOIN categories c ON c.id = je.category_id
             JOIN entries e ON e.id = jr.entry_id ORDER BY c.position, u.display_name, jr.rank'
        )->fetchAll(PDO::FETCH_NUM);
    } elseif ($report === 'finalists') {
        $headers = ['Category', 'Entry', 'Finalist rank', 'Judges selecting', 'Consolidated result'];
        foreach (categories() as $category) {
            $scores = [];
            foreach (consolidated_scores((int) $category['id']) as $score) {
                $scores[$score['id']] = $score;
            }
            $stmt = db()->prepare(
                'SELECT e.id, e.entry_number, f.finalist_rank FROM finalists f
                 JOIN entries e ON e.id = f.entry_id WHERE f.category_id = ? ORDER BY f.finalist_rank'
            );
            $stmt->execute([$category['id']]);
            foreach ($stmt->fetchAll() as $row) {
                $score = $scores[(int) $row['id']] ?? null;
                $rows[] = [
                    $category['name'], $row['entry_number'], $row['finalist_rank'],
                    $score['judge_count'] ?? 0, $score['score_label'] ?? '',
                ];
            }
        }
    } else {
        $headers = ['Category', 'Entry', 'Placement', 'Award'];
        $rows = db()->query(
            'SELECT c.name, e.entry_number, r.placement,
                    CASE WHEN b.entry_id = e.id THEN \'Best in Show\' WHEN r.placement = 1 THEN \'Category Winner\' ELSE \'\' END
             FROM final_category_results r JOIN categories c ON c.id = r.category_id
             JOIN entries e ON e.id = r.entry_id LEFT JOIN best_in_show_result b ON b.entry_id = e.id
             ORDER BY c.position, r.placement'
        )->fetchAll(PDO::FETCH_NUM);
    }
    http_response_code(200);
    ?>
    <!doctype html>
    <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= e($titles[$report]) ?></title><link rel="stylesheet" href="assets/app.css"><script src="assets/app.js" defer></script></head>
    <body><main class="wrap"><div class="spread no-print"><div><h1><?= e($titles[$report]) ?></h1><p class="sub">Floriculture Plants Competition · generated <?= e(date('Y-m-d H:i')) ?></p></div><button type="button" data-print>Print / Save PDF</button></div>
    <h1 class="print-only"><?= e($titles[$report]) ?></h1><p class="print-only">Floriculture Plants Competition</p>
    <section class="card"><div class="table-wrap"><table><thead><tr><?php foreach ($headers as $header): ?><th><?= e($header) ?></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach ($rows as $row): ?><tr><?php foreach ($row as $value): ?><td><?= e($value) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div></section></main></body></html>
    <?php
    exit;
}
