# Floriculture Plants Competition Management

A PHP 8.1+ and SQLite web application for registration, blind judging, finalist consolidation, deliberation, official placements, Best in Show, audit records, and approved public results. The initial database contains exactly the eight Floriculture plant categories in the competition specification.

## Run locally

Requirements: PHP 8.1+ with `PDO_SQLite`, `SQLite3`, and file uploads enabled.

From the project directory:

```powershell
php -S 127.0.0.1:8000 -t public
```

Open `http://127.0.0.1:8000` and sign in with an account already present in the database. The application does not create administrator accounts. On first run it creates `storage\competition.sqlite` and seeds the categories and workflow stages; you must point it at the database containing your existing account before signing in.

To use an existing SQLite database, set the `FLORICULTURE_DB_PATH` environment variable to its full path before starting PHP. The database must have the application schema. For example:

```powershell
$env:FLORICULTURE_DB_PATH = 'C:\path\to\existing\competition.sqlite'
php -S 127.0.0.1:8000 -t public
```

By default, the database, uploaded photos, and owner-to-entry mapping are stored outside the web document root in `storage\`.

The local PHP server is for development on a trusted machine. Do not expose it directly to the internet. For event or production deployment, use HTTPS, a production web server, private and backed-up storage, and a reviewed operational security configuration.

## Event setup sequence

For faster on-site registration, **Plant Registrations → Quick intake** can add confirmed counts of plants physically received by category. It creates sequential entry numbers for blind judging; complete the owner and plant details later from each entry’s Edit link. Quick-intake records are eligible immediately, so never enter estimates or plants that have not arrived. During open registration, excess entries without judging or result history can be deleted with a required reason. Deletions are audited and their entry numbers are never reused; entries already used in judging or results cannot be deleted.

1. Sign in with the existing Super Administrator account; create judge and tabulator accounts and assign judge categories.
2. Use **Workflow** to open registration, verify entries, complete judge setup, and open judging in sequence. Stage transitions require the preceding stage to be complete, or a Super Administrator must record an explicit override reason.
3. Configure and confirm the ranking and Best in Show methods with the organizer before judging.
4. Judges select five eligible entries per assigned category, rank 1–5, and submit to lock the evaluation.
5. After submissions, consolidate and explicitly confirm five unique finalists per category. Ties are displayed; the system does not apply an automatic tie-break.
6. Record top-three justifications and deliberation notes, confirm five official category placements, then confirm one of the eight category winners as Best in Show.
7. Complete result approval, open publication, and publish. The public page shows no results before publication.

## Security and privacy

- Passwords use PHP's `password_hash` / `password_verify`; forms use session-bound CSRF tokens and PDO prepared statements.
- Judge queries return entry numbers only and enforce assigned categories server-side. Owner names, contacts, and photos are limited to Super Administrator views.
- Uploaded photos are stored outside `public\` and served only to an authenticated Super Administrator.
- Result, stage, registration, and judging actions are audited. User passwords are never included in audit records.
- SQLite backups should be taken after stopping writes; keep backups encrypted and access-restricted.

## Reports

CSV exports are Excel-compatible and available from **Reports**, subject to role permissions. Print-friendly pages support printing or “Save as PDF” through the browser's print dialog. This standalone build does not depend on PhpSpreadsheet or Dompdf.

## Smoke test

Run `php tests\smoke.php` to verify the seeded categories, a judge submission, consolidation, blind display, and SQLite report metadata. Test fixtures use an in-memory database and do not alter the competition database.
