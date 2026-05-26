<?php

const TEST_DB_PATH = __DIR__ . '/test_db.sqlite';

putenv('FOLIO_DB_PATH=' . TEST_DB_PATH);

require __DIR__ . '/../lib/bootstrap.php';

if (file_exists(TEST_DB_PATH)) {
    unlink(TEST_DB_PATH);
}

$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
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
$stmt = $pdo->prepare('
    INSERT INTO shares (document_id, token, recipient_email)
    VALUES (?, ?, ?)
');
$stmt->execute([$docId, generate_share_token($doc), 'recipient@example.com']);

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

function assert_contains(string $needle, string $haystack, string $msg = ''): void {
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($msg !== '' ? $msg : "expected output to contain {$needle}");
    }
}

function render_share_link(string $token): string {
    $code = '$_GET["token"] = ' . var_export($token, true) . '; include ' . var_export(__DIR__ . '/../public/view.php', true) . ';';
    return run_php_with_test_db($code);
}

function submit_admin_document(array $post): string {
    $code = '$_SERVER["REQUEST_METHOD"] = "POST"; $_POST = ' . var_export($post, true) . '; include ' . var_export(__DIR__ . '/../public/admin.php', true) . ';';
    return run_php_with_test_db($code);
}

function submit_share_for_document(string $document_identifier, string $email): string {
    $code = '$_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["HTTP_HOST"] = "localhost:8000"; $_GET["doc"] = '
        . var_export($document_identifier, true)
        . '; $_POST = '
        . var_export(['email' => $email], true)
        . '; include '
        . var_export(__DIR__ . '/../public/share.php', true)
        . ';';
    return run_php_with_test_db($code);
}

function run_php_with_test_db(string $code): string {
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = array_merge($_ENV, ['FOLIO_DB_PATH' => TEST_DB_PATH]);
    $process = proc_open([PHP_BINARY, '-r', $code], $descriptorSpec, $pipes, __DIR__ . '/..', $env);
    if (!is_resource($process)) {
        throw new RuntimeException('failed to start PHP subprocess');
    }

    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $message = trim($error . "\n" . $output);
        throw new RuntimeException($message !== '' ? $message : 'PHP subprocess failed');
    }

    return $output;
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome-Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('seeded document has a readable document ID', function () {
    $stmt = db()->query("
        SELECT readable_id
        FROM documents
        WHERE title = 'Welcome-Packet'
        LIMIT 1
    ");
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected seeded document');
    assert_true(preg_match('/^welcome-packet-[a-f0-9]{6}$/', $row['readable_id']) === 1, 'unexpected readable ID: ' . $row['readable_id']);
});

test('migrations add and backfill readable IDs for existing databases', function () {
    $legacyDbPath = __DIR__ . '/legacy_test_db.sqlite';
    if (file_exists($legacyDbPath)) {
        unlink($legacyDbPath);
    }

    $legacy = new PDO('sqlite:' . $legacyDbPath);
    $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $legacy->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $legacy->exec(file_get_contents(__DIR__ . '/../schema.sql'));
    $legacy->exec(file_get_contents(__DIR__ . '/../migrations/001_add_publish_at_to_documents.sql'));
    $legacy->exec("
        INSERT INTO staff (email, name) VALUES
            ('legacy@folio.example', 'Legacy Folio')
    ");
    $stmt = $legacy->prepare('
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES (?, ?, 1, ?)
    ');
    $stmt->execute(['Legacy Packet', 'Created before readable IDs.', current_publish_at()]);

    apply_migrations($legacy);

    assert_true(column_exists($legacy, 'documents', 'readable_id'), 'readable_id column should be added');
    $row = $legacy->query("
        SELECT readable_id
        FROM documents
        WHERE title = 'Legacy Packet'
        LIMIT 1
    ")->fetch();
    assert_true($row !== false, 'expected legacy document');
    assert_true(preg_match('/^legacy-packet-[a-f0-9]{6}$/', $row['readable_id']) === 1, 'legacy document should be backfilled');

    $legacy = null;
    unlink($legacyDbPath);
});

test('migrations rename existing duplicate titles before adding unique title index', function () {
    $legacyDbPath = __DIR__ . '/legacy_test_db.sqlite';
    if (file_exists($legacyDbPath)) {
        unlink($legacyDbPath);
    }

    $legacy = new PDO('sqlite:' . $legacyDbPath);
    $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $legacy->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $legacy->exec(file_get_contents(__DIR__ . '/../schema.sql'));
    $legacy->exec(file_get_contents(__DIR__ . '/../migrations/001_add_publish_at_to_documents.sql'));
    $legacy->exec(file_get_contents(__DIR__ . '/../migrations/002_add_readable_id_to_documents.sql'));
    $legacy->exec("
        INSERT INTO staff (email, name) VALUES
            ('legacy-dupes@folio.example', 'Legacy Dupes')
    ");
    $stmt = $legacy->prepare('
        INSERT INTO documents (title, body, created_by, publish_at, readable_id)
        VALUES (?, ?, 1, ?, ?)
    ');
    $stmt->execute(['Duplicate-Title', 'First copy.', current_publish_at(), 'duplicate-title-a1b2c3']);
    $stmt->execute(['duplicate-title', 'Second copy.', current_publish_at(), 'duplicate-title-d4e5f6']);

    apply_migrations($legacy);

    assert_true(index_exists($legacy, 'idx_documents_title_nocase'), 'unique title index should be created');
    $rows = $legacy->query('
        SELECT title
        FROM documents
        ORDER BY id ASC
    ')->fetchAll();
    assert_true($rows[0]['title'] === 'Duplicate-Title', 'first duplicate should keep original title');
    assert_true($rows[1]['title'] !== 'duplicate-title', 'later duplicate should be renamed');

    $legacy = null;
    unlink($legacyDbPath);
});

test('document titles allow spaces and slug URLs safely', function () {
    submit_admin_document([
        'title' => 'Allowed Title AZaz09_~.',
        'body' => 'Allowed title characters.',
        'publish_at' => '',
    ]);

    $stmt = db()->query("
        SELECT COUNT(*)
        FROM documents
        WHERE title = 'Allowed Title AZaz09_~.'
    ");
    assert_true((int) $stmt->fetchColumn() === 1, 'allowed title should create a document');

    $output = submit_admin_document([
        'title' => 'Invalid/Title',
        'body' => 'Slash should not be accepted.',
        'publish_at' => '',
    ]);

    assert_contains('Title can only include letters, numbers, spaces, and - _ . ~ characters.', $output);

    $stmt = db()->query("
        SELECT COUNT(*)
        FROM documents
        WHERE title = 'Invalid/Title'
    ");
    assert_true((int) $stmt->fetchColumn() === 0, 'invalid title should not create a document');
});

test('document titles are unique regardless of case', function () {
    submit_admin_document([
        'title' => 'Quarterly Update',
        'body' => 'First copy.',
        'publish_at' => '',
    ]);
    $output = submit_admin_document([
        'title' => 'quarterly update',
        'body' => 'Second copy.',
        'publish_at' => '',
    ]);

    assert_contains('A document with this title already exists.', $output);

    $stmt = db()->query("
        SELECT readable_id
        FROM documents
        WHERE title = 'Quarterly Update' COLLATE NOCASE
        ORDER BY id ASC
    ");
    $rows = $stmt->fetchAll();
    assert_true(count($rows) === 1, 'case-insensitive duplicate title should be rejected');
    assert_true(strpos($rows[0]['readable_id'], 'quarterly-update-') === 0, 'readable ID should use title slug');
});

test('staff can create share by readable document ID while recipient link uses readable token', function () {
    $stmt = db()->query("
        SELECT id, readable_id
        FROM documents
        WHERE title = 'Welcome-Packet'
        LIMIT 1
    ");
    $doc = $stmt->fetch();
    assert_true($doc !== false, 'expected seeded document');
    $stmt = null;

    $output = submit_share_for_document($doc['readable_id'], 'readable@example.com');
    assert_contains('/view.php?token=', $output);
    assert_true(strpos($output, 'doc=') === false, 'recipient link should not expose readable document ID');
    assert_true(preg_match('/view\.php\?token=welcome-packet-\d{6}/', $output) === 1, 'recipient token should include title slug and 6-digit code');

    $stmt = db()->prepare('
        SELECT token
        FROM shares
        WHERE document_id = ? AND recipient_email = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([$doc['id'], 'readable@example.com']);
    $share = $stmt->fetch();
    assert_true($share !== false, 'expected share created by readable ID');
    assert_true(preg_match('/^welcome-packet-\d{6}$/', $share['token']) === 1, 'share token should use readable format');
    assert_true(substr($share['token'], -6) !== str_pad((string) $doc['id'], 6, '0', STR_PAD_LEFT), 'share token code should not be the document ID');

    $viewOutput = render_share_link($share['token']);
    assert_contains('Welcome-Packet', $viewOutput);
});

test('same document cannot be shared to the same recipient email twice', function () {
    $stmt = db()->query("
        SELECT id, readable_id
        FROM documents
        WHERE title = 'Welcome-Packet'
        LIMIT 1
    ");
    $doc = $stmt->fetch();
    assert_true($doc !== false, 'expected seeded document');
    $stmt = null;

    submit_share_for_document($doc['readable_id'], 'duplicate@example.com');
    $output = submit_share_for_document($doc['readable_id'], 'DUPLICATE@example.com');

    assert_contains('This document has already been shared with that recipient email.', $output);

    $stmt = db()->prepare('
        SELECT COUNT(*)
        FROM shares
        WHERE document_id = ? AND recipient_email = ? COLLATE NOCASE
    ');
    $stmt->execute([$doc['id'], 'duplicate@example.com']);
    assert_true((int) $stmt->fetchColumn() === 1, 'duplicate recipient share should not be created');
});

test('share audit log includes readable document ID', function () {
    $stmt = db()->query("
        SELECT readable_id
        FROM documents
        WHERE title = 'Welcome-Packet'
        LIMIT 1
    ");
    $doc = $stmt->fetch();
    assert_true($doc !== false, 'expected seeded document');
    $stmt = null;

    submit_share_for_document($doc['readable_id'], 'audit-readable@example.com');

    $stmt = db()->query("
        SELECT details
        FROM audit_log
        WHERE action = 'create' AND entity_type = 'share'
        ORDER BY id DESC
        LIMIT 1
    ");
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected share audit log');

    $details = json_decode($row['details'], true);
    assert_true($details['readable_id'] === $doc['readable_id'], 'share audit log should include readable ID');
    assert_true($details['recipient_email'] === 'audit-readable@example.com', 'share audit log should include recipient email');
    assert_true($details['token_format'] === 'title-slug-6-digit-code', 'share audit log should include token format');
});

test('blank publish date stores document creation time', function () {
    submit_admin_document([
        'title' => 'Immediate-Publish-Packet',
        'body' => 'No scheduled delay.',
        'publish_at' => '',
    ]);

    $stmt = db()->query("
        SELECT publish_at
        FROM documents
        WHERE title = 'Immediate-Publish-Packet'
        LIMIT 1
    ");
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected document to be created');
    assert_true($row['publish_at'] !== null && $row['publish_at'] !== '', 'expected publish_at to be stored');
    assert_true(document_is_published($row), 'blank publish date should publish immediately');
});

test('document publish date blocks before and allows after scheduled time', function () {
    $document = ['publish_at' => '2026-01-01 12:00:00'];

    assert_true(
        !document_is_published($document, new DateTimeImmutable('2026-01-01 11:59:59', new DateTimeZone('UTC'))),
        'document should be blocked before publish time'
    );
    assert_true(
        document_is_published($document, new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('UTC'))),
        'document should publish at scheduled time'
    );
    assert_true(
        document_is_published($document, new DateTimeImmutable('2026-01-01 12:00:01', new DateTimeZone('UTC'))),
        'document should remain available after publish time'
    );
});

test('future scheduled share link shows not yet available message', function () {
    $publishAt = (new DateTimeImmutable('+1 day', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES (?, ?, 1, ?)
    ');
    $stmt->execute(['Future-Packet', 'This body should stay hidden.', $publishAt]);
    $docId = (int) db()->lastInsertId();

    $token = generate_share_token([
        'id' => $docId,
        'title' => 'Future-Packet',
    ]);
    $stmt = db()->prepare('
        INSERT INTO shares (document_id, token, recipient_email)
        VALUES (?, ?, ?)
    ');
    $stmt->execute([$docId, $token, 'future@example.com']);

    $output = render_share_link($token);
    assert_contains('Document not yet available', $output);
    assert_true(strpos($output, 'This body should stay hidden.') === false, 'future document body should not be rendered');
});

test('past scheduled share link renders document body', function () {
    $publishAt = (new DateTimeImmutable('-1 day', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES (?, ?, 1, ?)
    ');
    $stmt->execute(['Published-Packet', 'This scheduled body is visible.', $publishAt]);
    $docId = (int) db()->lastInsertId();

    $token = generate_share_token([
        'id' => $docId,
        'title' => 'Published-Packet',
    ]);
    $stmt = db()->prepare('
        INSERT INTO shares (document_id, token, recipient_email)
        VALUES (?, ?, ?)
    ');
    $stmt->execute([$docId, $token, 'published@example.com']);

    $output = render_share_link($token);
    assert_contains('Published-Packet', $output);
    assert_contains('This scheduled body is visible.', $output);
});

test('scheduled document creation audit includes publish time', function () {
    $publishAtInput = (new DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i');
    $expectedPublishAt = normalize_publish_at_input($publishAtInput);

    submit_admin_document([
        'title' => 'Scheduled-Audit-Packet',
        'body' => 'Audit me later.',
        'publish_at' => $publishAtInput,
    ]);

    $stmt = db()->query("
        SELECT details
        FROM audit_log
        WHERE action = 'create' AND entity_type = 'document'
        ORDER BY id DESC
        LIMIT 1
    ");
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected document creation audit log');

    $details = json_decode($row['details'], true);
    assert_true($details['title'] === 'Scheduled-Audit-Packet', 'audit log should include title');
    assert_true($details['publish_at'] === $expectedPublishAt, 'audit log should include normalized publish time');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
