<?php

require __DIR__ . '/lib/bootstrap.php';

$dbPath = db_path();
if (file_exists($dbPath) && !@unlink($dbPath)) {
    $pdo = db();
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $tables = $pdo->query("
        SELECT name
        FROM sqlite_master
        WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS "' . str_replace('"', '""', $table) . '"');
    }
    $pdo->exec('PRAGMA foreign_keys = ON');
}

$pdo = $pdo ?? db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
apply_migrations($pdo);

$pdo->exec("
    INSERT INTO staff (email, name) VALUES
        ('freddy@folio.example', 'Freddy Folio')
");

$stmt = $pdo->prepare('
    INSERT INTO documents (title, body, created_by, publish_at, readable_id)
    VALUES (?, ?, 1, ?, ?)
');
$stmt->execute([
    'Welcome-Packet',
    "Welcome to Folio!\n\nThis is the body of your welcome packet.",
    current_publish_at(),
    generate_readable_document_id('Welcome-Packet'),
]);
$docId = (int) $pdo->lastInsertId();

$doc = [
    'id' => $docId,
    'title' => 'Welcome-Packet',
];
$token = generate_share_token($doc);
$stmt = $pdo->prepare('
    INSERT INTO shares (document_id, token, recipient_email)
    VALUES (?, ?, ?)
');
$stmt->execute([$docId, $token, 'recipient@example.com']);

echo "Seeded db.sqlite.\n";
echo "Admin:        http://localhost:8000/admin.php\n";
echo "Sample share: http://localhost:8000/view.php?token={$token}\n";
