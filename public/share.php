<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$docIdentifier = trim($_GET['doc'] ?? '');
$doc = find_document_for_share($docIdentifier);

if (!$doc) {
    http_response_code(404);
    render_header('Not found', $staff);
    ?>
    <div class="banner banner-error">Document not found.</div>
    <p><a href="/admin.php" class="back-link">← back to admin</a></p>
    <?php
    render_footer();
    exit;
}

$error = null;
$created_token = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if ($email === '') {
        $error = 'Recipient email is required.';
    } elseif (share_recipient_exists((int) $doc['id'], $email)) {
        $error = 'This document has already been shared with that recipient email.';
    } else {
        $token = generate_share_token($doc);
        $stmt = db()->prepare('
            INSERT INTO shares (document_id, token, recipient_email)
            VALUES (?, ?, ?)
        ');
        $stmt->execute([$doc['id'], $token, $email]);
        $shareId = (int) db()->lastInsertId();
        audit_log('create', 'share', $shareId, [
            'document_id' => $doc['id'],
            'readable_id' => $doc['readable_id'] ?? null,
            'recipient_email' => $email,
            'token_format' => 'title-slug-6-digit-code',
        ]);
        $created_token = $token;
    }
}

render_header('Share · ' . $doc['title'], $staff);
?>

<a href="/admin.php" class="back-link">← back to admin</a>

<h1 class="page-title">Share "<?= h($doc['title']) ?>"</h1>
<p class="page-subtitle">Generate a one-time link for a recipient.</p>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<?php if ($created_token): ?>
    <div class="banner banner-success">
        Share link ready:
        <code>http://<?= h($_SERVER['HTTP_HOST'] ?? 'localhost:8000') ?>/view.php?token=<?= h($created_token) ?></code>
    </div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">Create share link</h2>
    <p class="meta">Document ID: <code><?= h($doc['readable_id'] ?? ('#' . $doc['id'])) ?></code></p>
    <form method="post">
        <div class="form-field">
            <label for="email">Recipient email</label>
            <input type="email" id="email" name="email" required>
        </div>
        <button type="submit" class="btn">Generate link</button>
    </form>
</section>

<?php render_footer(); ?>
