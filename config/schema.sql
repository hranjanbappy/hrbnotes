-- ===========================================================================
-- Hrb Notes - SQLite schema
-- ---------------------------------------------------------------------------
-- The vault Markdown files are the source of truth. These tables only hold
-- metadata and a full-text search index for fast lookups.
-- ===========================================================================

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- ---------------------------------------------------------------------------
-- Single admin user (the app supports one admin account by design).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    role          TEXT    NOT NULL DEFAULT 'user',
    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ---------------------------------------------------------------------------
-- Notes metadata. `path` is the vault-relative path (e.g. research/safir.md).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL,
    path        TEXT    NOT NULL UNIQUE,
    slug        TEXT    NOT NULL,
    folder      TEXT    NOT NULL DEFAULT '',
    tags        TEXT    NOT NULL DEFAULT '',   -- space separated cache for quick display
    size_bytes  INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    modified_at TEXT    NOT NULL DEFAULT (datetime('now')),
    opened_at   TEXT
);

CREATE INDEX IF NOT EXISTS idx_notes_slug   ON notes(slug);
CREATE INDEX IF NOT EXISTS idx_notes_folder ON notes(folder);
CREATE INDEX IF NOT EXISTS idx_notes_mod    ON notes(modified_at);

-- ---------------------------------------------------------------------------
-- Wiki links between notes. target_note may be null if the target does not
-- (yet) exist as a file - we still record the target title.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS links (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    source_note  INTEGER NOT NULL,
    target_note  INTEGER,
    target_title TEXT    NOT NULL,
    FOREIGN KEY (source_note) REFERENCES notes(id) ON DELETE CASCADE,
    FOREIGN KEY (target_note) REFERENCES notes(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_links_source ON links(source_note);
CREATE INDEX IF NOT EXISTS idx_links_target ON links(target_note);

-- ---------------------------------------------------------------------------
-- Tags and the many-to-many join with notes.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tags (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    tag_name TEXT    NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS note_tags (
    note_id INTEGER NOT NULL,
    tag_id  INTEGER NOT NULL,
    PRIMARY KEY (note_id, tag_id),
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id)  REFERENCES tags(id)  ON DELETE CASCADE
);

-- ---------------------------------------------------------------------------
-- Full-text search index (FTS5). Stores the title and content per note.
-- `note_id` is an unindexed column used to join back to notes.
-- ---------------------------------------------------------------------------
CREATE VIRTUAL TABLE IF NOT EXISTS search_index USING fts5(
    title,
    content,
    tags,
    note_id UNINDEXED,
    tokenize = 'porter unicode61'
);

-- ---------------------------------------------------------------------------
-- Settings table (key-value store for app configuration).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT
);

-- ---------------------------------------------------------------------------
-- User permissions mapping to allow vault folder access control.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_permissions (
    user_id    INTEGER NOT NULL,
    vault_path TEXT NOT NULL,
    PRIMARY KEY (user_id, vault_path),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

