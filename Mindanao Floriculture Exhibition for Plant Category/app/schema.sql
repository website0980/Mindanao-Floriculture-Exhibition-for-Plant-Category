PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('super_admin', 'judge', 'tabulator')),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    tabulator_permissions TEXT NOT NULL DEFAULT '[]',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL UNIQUE,
    prefix TEXT NOT NULL UNIQUE,
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    position INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entry_number TEXT NOT NULL UNIQUE,
    category_id INTEGER NOT NULL REFERENCES categories(id),
    description TEXT NOT NULL,
    owner_name TEXT NOT NULL,
    contact TEXT NOT NULL DEFAULT '',
    registration_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'eligible' CHECK (status IN ('eligible', 'disqualified')),
    disqualification_reason TEXT NOT NULL DEFAULT '',
    remarks TEXT NOT NULL DEFAULT '',
    photo_path TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS entry_number_reservations (
    entry_number TEXT PRIMARY KEY,
    category_id INTEGER NOT NULL REFERENCES categories(id),
    reserved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS judge_assignments (
    judge_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    category_id INTEGER NOT NULL REFERENCES categories(id),
    PRIMARY KEY (judge_id, category_id)
);

CREATE TABLE IF NOT EXISTS judge_evaluations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    judge_id INTEGER NOT NULL REFERENCES users(id),
    category_id INTEGER NOT NULL REFERENCES categories(id),
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted')),
    submitted_at TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (judge_id, category_id)
);

CREATE TABLE IF NOT EXISTS judge_rankings (
    evaluation_id INTEGER NOT NULL REFERENCES judge_evaluations(id) ON DELETE CASCADE,
    entry_id INTEGER NOT NULL REFERENCES entries(id),
    rank INTEGER NOT NULL CHECK (rank BETWEEN 1 AND 5),
    PRIMARY KEY (evaluation_id, entry_id),
    UNIQUE (evaluation_id, rank)
);

CREATE TABLE IF NOT EXISTS finalists (
    category_id INTEGER NOT NULL REFERENCES categories(id),
    entry_id INTEGER NOT NULL REFERENCES entries(id),
    finalist_rank INTEGER NOT NULL CHECK (finalist_rank BETWEEN 1 AND 5),
    confirmed_by INTEGER NOT NULL REFERENCES users(id),
    confirmed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (category_id, entry_id),
    UNIQUE (category_id, finalist_rank)
);

CREATE TABLE IF NOT EXISTS deliberation_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL REFERENCES categories(id),
    entry_id INTEGER NOT NULL REFERENCES entries(id),
    judge_id INTEGER NOT NULL REFERENCES users(id),
    notes TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (category_id, entry_id, judge_id)
);

CREATE TABLE IF NOT EXISTS deliberations (
    category_id INTEGER PRIMARY KEY REFERENCES categories(id),
    discussion_notes TEXT NOT NULL DEFAULT '',
    administrator_remarks TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'in_progress' CHECK (status IN ('in_progress', 'completed')),
    updated_by INTEGER NOT NULL REFERENCES users(id),
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS final_category_results (
    category_id INTEGER NOT NULL REFERENCES categories(id),
    entry_id INTEGER NOT NULL REFERENCES entries(id),
    placement INTEGER NOT NULL CHECK (placement BETWEEN 1 AND 5),
    confirmed_by INTEGER NOT NULL REFERENCES users(id),
    confirmed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (category_id, placement),
    UNIQUE (category_id, entry_id)
);

CREATE TABLE IF NOT EXISTS best_in_show_evaluations (
    judge_id INTEGER PRIMARY KEY REFERENCES users(id),
    entry_id INTEGER NOT NULL REFERENCES entries(id),
    notes TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS best_in_show_result (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    entry_id INTEGER NOT NULL REFERENCES entries(id),
    confirmed_by INTEGER NOT NULL REFERENCES users(id),
    confirmed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS stages (
    stage_key TEXT PRIMARY KEY,
    label TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'not_started'
        CHECK (status IN ('not_started', 'in_progress', 'pending_review', 'completed', 'locked'))
);

CREATE TABLE IF NOT EXISTS settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER REFERENCES users(id),
    action TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL DEFAULT '',
    previous_value TEXT,
    new_value TEXT,
    reason TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_entries_category_status ON entries(category_id, status);
CREATE INDEX IF NOT EXISTS idx_rankings_entry ON judge_rankings(entry_id);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);
