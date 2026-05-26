<?php

const TEST_DB_PATH = __DIR__ . '/test_db.sqlite';

putenv('FOLIO_DB_PATH=' . TEST_DB_PATH);

require __DIR__ . '/../lib/bootstrap.php';

if (file_exists(TEST_DB_PATH)) {
    unlink(TEST_DB_PATH);
}

$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));

$migrationFiles = glob(__DIR__ . '/../migrations/*.sql') ?: [];
sort($migrationFiles, SORT_STRING);

foreach ($migrationFiles as $migrationFile) {
    $pdo->exec(file_get_contents($migrationFile));
}

$pdo->exec("
    INSERT INTO staff (email, name) VALUES
        ('freddy@folio.example', 'Freddy Folio')
");

$stmt = $pdo->prepare('
    INSERT INTO documents (title, body, created_by, publish_at)
    VALUES (?, ?, 1, ?)
');
$stmt->execute([
    'Welcome Packet',
    "Welcome to Folio!\n\nThis is the body of your welcome packet.",
    current_publish_at(),
]);
$docId = (int) $pdo->lastInsertId();

$stmt = $pdo->prepare('
    INSERT INTO shares (document_id, token, recipient_email)
    VALUES (?, ?, ?)
');
$stmt->execute([$docId, random_token(), 'recipient@example.com']);

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

function submit_admin_document(array $post): void {
    $code = '$_SERVER["REQUEST_METHOD"] = "POST"; $_POST = ' . var_export($post, true) . '; include ' . var_export(__DIR__ . '/../public/admin.php', true) . ';';
    run_php_with_test_db($code);
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
        throw new RuntimeException(trim($error) !== '' ? trim($error) : 'PHP subprocess failed');
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
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('blank publish date stores document creation time', function () {
    submit_admin_document([
        'title' => 'Immediate Publish Packet',
        'body' => 'No scheduled delay.',
        'publish_at' => '',
    ]);

    $stmt = db()->query("
        SELECT publish_at
        FROM documents
        WHERE title = 'Immediate Publish Packet'
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
    $stmt->execute(['Future Packet', 'This body should stay hidden.', $publishAt]);
    $docId = (int) db()->lastInsertId();

    $token = random_token();
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
    $stmt->execute(['Published Packet', 'This scheduled body is visible.', $publishAt]);
    $docId = (int) db()->lastInsertId();

    $token = random_token();
    $stmt = db()->prepare('
        INSERT INTO shares (document_id, token, recipient_email)
        VALUES (?, ?, ?)
    ');
    $stmt->execute([$docId, $token, 'published@example.com']);

    $output = render_share_link($token);
    assert_contains('Published Packet', $output);
    assert_contains('This scheduled body is visible.', $output);
});

test('scheduled document creation audit includes publish time', function () {
    $publishAtInput = (new DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i');
    $expectedPublishAt = normalize_publish_at_input($publishAtInput);

    submit_admin_document([
        'title' => 'Scheduled Audit Packet',
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
    assert_true($details['title'] === 'Scheduled Audit Packet', 'audit log should include title');
    assert_true($details['publish_at'] === $expectedPublishAt, 'audit log should include normalized publish time');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
