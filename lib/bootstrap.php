<?php

date_default_timezone_set('America/Chicago');

function db_path(): string {
    $path = getenv('FOLIO_DB_PATH');
    if ($path !== false && $path !== '') {
        return $path;
    }

    return __DIR__ . '/../db.sqlite';
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . db_path());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        apply_migrations($pdo);
    }
    return $pdo;
}

function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM sqlite_master
        WHERE type = 'table' AND name = ?
        LIMIT 1
    ");
    $stmt->execute([$table]);

    return $stmt->fetchColumn() !== false;
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")');
    foreach ($stmt->fetchAll() as $row) {
        if ($row['name'] === $column) {
            return true;
        }
    }

    return false;
}

function index_exists(PDO $pdo, string $index): bool {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM sqlite_master
        WHERE type = 'index' AND name = ?
        LIMIT 1
    ");
    $stmt->execute([$index]);

    return $stmt->fetchColumn() !== false;
}

function apply_migrations(PDO $pdo): void {
    if (!table_exists($pdo, 'documents')) {
        return;
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS schema_migrations (
            filename TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )
    ');

    $migrationFiles = glob(__DIR__ . '/../migrations/*.sql') ?: [];
    sort($migrationFiles, SORT_STRING);

    foreach ($migrationFiles as $migrationFile) {
        $filename = basename($migrationFile);
        $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE filename = ?');
        $stmt->execute([$filename]);
        if ($stmt->fetchColumn() !== false) {
            continue;
        }

        if (migration_is_reflected($pdo, $filename)) {
            record_migration($pdo, $filename);
            continue;
        }

        prepare_for_migration($pdo, $filename);
        $pdo->exec(file_get_contents($migrationFile));
        record_migration($pdo, $filename);
    }

    backfill_readable_document_ids($pdo);
}

function migration_is_reflected(PDO $pdo, string $filename): bool {
    if ($filename === '001_add_publish_at_to_documents.sql') {
        return column_exists($pdo, 'documents', 'publish_at');
    }

    if ($filename === '002_add_readable_id_to_documents.sql') {
        return column_exists($pdo, 'documents', 'readable_id');
    }

    if ($filename === '003_add_unique_document_title_index.sql') {
        return index_exists($pdo, 'idx_documents_title_nocase');
    }

    return false;
}

function record_migration(PDO $pdo, string $filename): void {
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO schema_migrations (filename) VALUES (?)');
    $stmt->execute([$filename]);
}

function prepare_for_migration(PDO $pdo, string $filename): void {
    if ($filename === '003_add_unique_document_title_index.sql') {
        ensure_unique_document_titles($pdo);
    }
}

function ensure_unique_document_titles(PDO $pdo): void {
    $documents = $pdo->query('
        SELECT id, title
        FROM documents
        ORDER BY id ASC
    ')->fetchAll();

    $seen = [];
    $stmt = $pdo->prepare('UPDATE documents SET title = ? WHERE id = ?');
    foreach ($documents as $document) {
        $key = strtolower($document['title']);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            continue;
        }

        $title = unique_existing_document_title($document['title'], (int) $document['id'], $seen);
        $seen[strtolower($title)] = true;
        $stmt->execute([$title, $document['id']]);
    }
}

function unique_existing_document_title(string $title, int $document_id, array $seen): string {
    $base = slugify_title($title);
    $candidate = substr($base, 0, 48) . '-' . $document_id;

    while (isset($seen[strtolower($candidate)])) {
        $candidate .= '~';
    }

    return $candidate;
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function validate_document_title(string $title): void {
    if ($title === '') {
        throw new InvalidArgumentException('Title and body are required.');
    }

    if (!preg_match('/^[A-Za-z0-9 ._~-]+$/', $title)) {
        throw new InvalidArgumentException('Title can only include letters, numbers, spaces, and - _ . ~ characters.');
    }
}

function document_title_exists(string $title): bool {
    $stmt = db()->prepare('SELECT 1 FROM documents WHERE title = ? COLLATE NOCASE LIMIT 1');
    $stmt->execute([$title]);

    return $stmt->fetchColumn() !== false;
}

function share_recipient_exists(int $document_id, string $recipient_email): bool {
    $stmt = db()->prepare('
        SELECT 1
        FROM shares
        WHERE document_id = ? AND recipient_email = ? COLLATE NOCASE
        LIMIT 1
    ');
    $stmt->execute([$document_id, $recipient_email]);

    return $stmt->fetchColumn() !== false;
}

function slugify_title(string $title): string {
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    if ($slug === '') {
        $slug = 'document';
    }

    return trim(substr($slug, 0, 40), '-');
}

function generate_readable_document_id(string $title, ?PDO $pdo = null): string {
    $database = $pdo ?? db();
    $base = slugify_title($title);
    $stmt = $database->prepare('SELECT COUNT(*) FROM documents WHERE readable_id = ?');

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $candidate = $base . '-' . strtolower(bin2hex(random_bytes(3)));
        $stmt->execute([$candidate]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }
    }

    throw new RuntimeException('Unable to generate a unique readable document ID.');
}

function generate_share_token(array $document): string {
    $base = slugify_title($document['title']);
    $stmt = db()->prepare('SELECT COUNT(*) FROM shares WHERE token = ?');

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $code = random_int(100000, 999999);
        if ($code === (int) $document['id']) {
            continue;
        }

        $candidate = $base . '-' . $code;
        $stmt->execute([$candidate]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }
    }

    throw new RuntimeException('Unable to generate a unique share token.');
}

function backfill_readable_document_ids(PDO $pdo): void {
    if (!column_exists($pdo, 'documents', 'readable_id')) {
        return;
    }

    $docs = $pdo->query('
        SELECT id, title
        FROM documents
        WHERE readable_id IS NULL OR readable_id = \'\'
        ORDER BY id ASC
    ')->fetchAll();

    $stmt = $pdo->prepare('UPDATE documents SET readable_id = ? WHERE id = ?');
    foreach ($docs as $doc) {
        $stmt->execute([
            generate_readable_document_id($doc['title'], $pdo),
            $doc['id'],
        ]);
    }
}

function find_document_for_share(string $document_identifier): ?array {
    $stmt = db()->prepare('SELECT * FROM documents WHERE readable_id = ?');
    $stmt->execute([$document_identifier]);
    $doc = $stmt->fetch();
    if ($doc) {
        return $doc;
    }

    if (ctype_digit($document_identifier)) {
        $stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
        $stmt->execute([(int) $document_identifier]);
        $doc = $stmt->fetch();
        if ($doc) {
            return $doc;
        }
    }

    return null;
}

function current_publish_at(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

function normalize_publish_at_input(string $value, ?int $timezone_offset_minutes = null): string {
    $value = trim($value);
    if ($value === '') {
        return current_publish_at();
    }

    $inputTimezone = $timezone_offset_minutes === null
        ? new DateTimeZone(date_default_timezone_get())
        : new DateTimeZone('UTC');
    $publishAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $inputTimezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$publishAt || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new InvalidArgumentException('Publish time must be a valid date and time.');
    }

    if ($timezone_offset_minutes !== null) {
        $publishAt = $publishAt->modify(sprintf('%+d minutes', $timezone_offset_minutes));
    }

    return $publishAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function document_is_published(array $document, ?DateTimeImmutable $now = null): bool {
    if (empty($document['publish_at'])) {
        return true;
    }

    $utc = new DateTimeZone('UTC');
    $publishAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $document['publish_at'], $utc);
    if (!$publishAt) {
        return false;
    }

    return $publishAt <= ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
