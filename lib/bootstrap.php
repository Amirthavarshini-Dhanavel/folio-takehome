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
    }
    return $pdo;
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
