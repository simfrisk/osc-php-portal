<?php

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Views;
use Carbon\Carbon;
use Ramsey\Uuid\Uuid;

class ContactController
{
    private static function requireLogin(): bool
    {
        if (!Auth::check()) {
            header('Location: /login');
            return false;
        }
        return true;
    }

    public static function index(): void
    {
        if (!self::requireLogin()) {
            return;
        }
        $pdo = Database::connect();
        $rows = [];
        if ($pdo !== null) {
            $stmt = $pdo->query('SELECT c.id, c.uuid, c.name, c.email, c.phone, c.created_at,
                (SELECT COUNT(*) FROM uploads u WHERE u.contact_id = c.id) AS upload_count
                FROM contacts c ORDER BY c.created_at DESC');
            $rows = $stmt->fetchAll();
        }

        $body = '<h1>Contacts</h1><p><a class="btn" href="/contacts/new">Add contact</a></p>';
        if ($pdo === null) {
            $body .= '<p class="error">Database is not reachable: ' . Views::e(Database::lastError()) . '</p>';
        } elseif (empty($rows)) {
            $body .= '<p>No contacts yet.</p>';
        } else {
            $body .= '<table><tr><th>Name</th><th>Email</th><th>Phone</th><th>Added</th><th>Files</th><th></th></tr>';
            foreach ($rows as $row) {
                $added = Carbon::parse($row['created_at'])->diffForHumans();
                $id = (int) $row['id'];
                $body .= '<tr>'
                    . '<td>' . Views::e($row['name']) . '</td>'
                    . '<td>' . Views::e($row['email']) . '</td>'
                    . '<td>' . Views::e($row['phone']) . '</td>'
                    . '<td>' . Views::e($added) . '</td>'
                    . '<td>' . (int) $row['upload_count'] . '</td>'
                    . '<td><a href="/contacts/' . $id . '/edit">edit</a></td>'
                    . '</tr>';
            }
            $body .= '</table>';
        }

        Views::layout('Contacts', $body);
    }

    public static function showNew(): void
    {
        if (!self::requireLogin()) {
            return;
        }
        Views::layout('New contact', self::form([], '/contacts', 'Add contact'));
    }

    public static function create(): void
    {
        if (!self::requireLogin()) {
            return;
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            echo 'Invalid form submission.';
            return;
        }

        $pdo = Database::connect();
        if ($pdo === null) {
            http_response_code(500);
            echo 'Database is not reachable.';
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO contacts (uuid, name, email, phone, notes) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            Uuid::uuid4()->toString(),
            trim($_POST['name'] ?? ''),
            trim($_POST['email'] ?? ''),
            trim($_POST['phone'] ?? ''),
            trim($_POST['notes'] ?? ''),
        ]);

        header('Location: /contacts');
    }

    public static function showEdit(int $id): void
    {
        if (!self::requireLogin()) {
            return;
        }
        $pdo = Database::connect();
        if ($pdo === null) {
            http_response_code(500);
            echo 'Database is not reachable.';
            return;
        }

        $stmt = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
        $stmt->execute([$id]);
        $contact = $stmt->fetch();
        if (!$contact) {
            self::notFound();
            return;
        }

        $uploadStmt = $pdo->prepare('SELECT id, filename, mime_type, file_size, created_at FROM uploads WHERE contact_id = ? ORDER BY created_at DESC');
        $uploadStmt->execute([$id]);
        $uploads = $uploadStmt->fetchAll();

        $uploadRows = '';
        foreach ($uploads as $u) {
            $uploadRows .= '<li><a href="/contacts/' . $id . '/upload/' . (int) $u['id'] . '">'
                . Views::e($u['filename']) . '</a> (' . Views::e($u['mime_type']) . ', ' . (int) $u['file_size'] . ' bytes)</li>';
        }
        if ($uploadRows === '') {
            $uploadRows = '<li>No files uploaded yet.</li>';
        }

        $csrf = Csrf::field();
        $body = self::form($contact, '/contacts/' . $id . '/edit', 'Save changes');
        $body .= <<<HTML
<form method="post" action="/contacts/{$id}/delete" onsubmit="return confirm('Delete this contact?');" style="margin-top:1rem;">
  {$csrf}
  <button class="btn btn-danger" type="submit">Delete contact</button>
</form>
<h2>Files</h2>
<ul>{$uploadRows}</ul>
<form method="post" action="/contacts/{$id}/upload" enctype="multipart/form-data">
  {$csrf}
  <label for="file">Upload a file (image or PDF)</label>
  <input type="file" id="file" name="file" accept=".jpg,.jpeg,.png,.pdf" required>
  <button class="btn" type="submit">Upload</button>
</form>
HTML;

        Views::layout('Edit contact', $body);
    }

    public static function update(int $id): void
    {
        if (!self::requireLogin()) {
            return;
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            echo 'Invalid form submission.';
            return;
        }

        $pdo = Database::connect();
        if ($pdo === null) {
            http_response_code(500);
            echo 'Database is not reachable.';
            return;
        }

        $stmt = $pdo->prepare('UPDATE contacts SET name = ?, email = ?, phone = ?, notes = ?, updated_at = now() WHERE id = ?');
        $stmt->execute([
            trim($_POST['name'] ?? ''),
            trim($_POST['email'] ?? ''),
            trim($_POST['phone'] ?? ''),
            trim($_POST['notes'] ?? ''),
            $id,
        ]);

        header('Location: /contacts/' . $id . '/edit');
    }

    public static function delete(int $id): void
    {
        if (!self::requireLogin()) {
            return;
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            echo 'Invalid form submission.';
            return;
        }

        $pdo = Database::connect();
        if ($pdo !== null) {
            $stmt = $pdo->prepare('DELETE FROM contacts WHERE id = ?');
            $stmt->execute([$id]);
        }

        header('Location: /contacts');
    }

    public static function upload(int $id): void
    {
        if (!self::requireLogin()) {
            return;
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            echo 'Invalid form submission.';
            return;
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            header('Location: /contacts/' . $id . '/edit?upload_error=1');
            return;
        }

        $allowed = ['image/jpeg', 'image/png', 'application/pdf'];
        $mimeType = mime_content_type($_FILES['file']['tmp_name']);
        if (!in_array($mimeType, $allowed, true)) {
            header('Location: /contacts/' . $id . '/edit?upload_error=type');
            return;
        }

        $pdo = Database::connect();
        if ($pdo === null) {
            http_response_code(500);
            echo 'Database is not reachable.';
            return;
        }

        // Stored as bytea in Postgres, not on local disk. There is no persistent
        // disk on a My App, so anything saved only under /tmp or the docroot is
        // gone the next time the container restarts.
        $data = file_get_contents($_FILES['file']['tmp_name']);
        $stmt = $pdo->prepare('INSERT INTO uploads (contact_id, filename, mime_type, file_size, data) VALUES (?, ?, ?, ?, ?)');
        $stmt->bindValue(1, $id, \PDO::PARAM_INT);
        $stmt->bindValue(2, basename($_FILES['file']['name']));
        $stmt->bindValue(3, $mimeType);
        $stmt->bindValue(4, strlen($data), \PDO::PARAM_INT);
        $stmt->bindValue(5, $data, \PDO::PARAM_LOB);
        $stmt->execute();

        header('Location: /contacts/' . $id . '/edit');
    }

    public static function download(int $id, int $uploadId): void
    {
        if (!self::requireLogin()) {
            return;
        }
        $pdo = Database::connect();
        if ($pdo === null) {
            http_response_code(500);
            echo 'Database is not reachable.';
            return;
        }

        $stmt = $pdo->prepare('SELECT filename, mime_type, data FROM uploads WHERE id = ? AND contact_id = ?');
        $stmt->execute([$uploadId, $id]);
        $row = $stmt->fetch();
        if (!$row) {
            self::notFound();
            return;
        }

        // pdo_pgsql returns bytea columns as a PHP stream resource, not a plain
        // string, so the bytes have to be read out of the stream before printing.
        $data = $row['data'];
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        header('Content-Type: ' . $row['mime_type']);
        header('Content-Disposition: inline; filename="' . basename($row['filename']) . '"');
        echo $data;
    }

    private static function form(array $contact, string $action, string $submitLabel): string
    {
        $csrf = Csrf::field();
        $name = Views::e($contact['name'] ?? '');
        $email = Views::e($contact['email'] ?? '');
        $phone = Views::e($contact['phone'] ?? '');
        $notes = Views::e($contact['notes'] ?? '');
        return <<<HTML
<h1>{$submitLabel}</h1>
<form method="post" action="{$action}">
  {$csrf}
  <label for="name">Name</label>
  <input type="text" id="name" name="name" value="{$name}" required>
  <label for="email">Email</label>
  <input type="email" id="email" name="email" value="{$email}">
  <label for="phone">Phone</label>
  <input type="text" id="phone" name="phone" value="{$phone}">
  <label for="notes">Notes</label>
  <textarea id="notes" name="notes" rows="3">{$notes}</textarea>
  <button class="btn" type="submit">{$submitLabel}</button>
</form>
HTML;
    }

    public static function notFound(): void
    {
        http_response_code(404);
        Views::layout('Not found', '<h1>404</h1><p>That contact does not exist.</p>');
    }
}
