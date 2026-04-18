<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
faq_ensure_schema($conn);

$admin_id = (int) $_SESSION['admin_id'];
$admin_role = $_SESSION['admin_role'];
$message = '';

/** @var int */
$target_admin_id = $admin_id;
if ($admin_role === 'super_admin') {
    $target_admin_id = (int) ($_GET['store'] ?? $_POST['target_admin_id'] ?? 0);
}

function faq_admin_assert_target(mysqli $conn, $admin_role, &$target_admin_id, $admin_id) {
    if ($admin_role !== 'super_admin') {
        $target_admin_id = $admin_id;
        return true;
    }
    if ($target_admin_id <= 0) {
        return false;
    }
    $st = $conn->prepare("SELECT id FROM admins WHERE id = ? AND role = 'sub_admin' AND status = 'active' LIMIT 1");
    $st->bind_param("i", $target_admin_id);
    $st->execute();
    $ok = (bool) $st->get_result()->fetch_assoc();
    $st->close();
    return $ok;
}

$stores = [];
if ($admin_role === 'super_admin') {
    $rs = $conn->query("SELECT id, username FROM admins WHERE role = 'sub_admin' ORDER BY username ASC");
    while ($row = $rs->fetch_assoc()) {
        $stores[] = $row;
    }
}

$can_manage = faq_admin_assert_target($conn, $admin_role, $target_admin_id, $admin_id);

if ($can_manage && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_faq'])) {
        $q = trim($_POST['question'] ?? '');
        $a = trim($_POST['answer'] ?? '');
        if ($q !== '' && $a !== '') {
            $sort = isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0;
            $ins = $conn->prepare("INSERT INTO store_faq (sub_admin_id, question, answer, sort_order) VALUES (?, ?, ?, ?)");
            $ins->bind_param("issi", $target_admin_id, $q, $a, $sort);
            if ($ins->execute()) {
                $message = 'FAQ saved. FAQ file cache, Gemini FAQ context, and any per-phone Gemini history for this store were refreshed.';
                faq_rebuild_cache_file($target_admin_id);
            } else {
                $message = 'Could not add FAQ.';
            }
            $ins->close();
        } else {
            $message = 'Question and answer are required.';
        }
    } elseif (isset($_POST['update_faq'])) {
        $fid = (int) ($_POST['faq_id'] ?? 0);
        $q = trim($_POST['question'] ?? '');
        $a = trim($_POST['answer'] ?? '');
        $sort = isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0;
        if ($fid > 0 && $q !== '' && $a !== '') {
            $up = $conn->prepare("UPDATE store_faq SET question = ?, answer = ?, sort_order = ? WHERE id = ? AND sub_admin_id = ?");
            $up->bind_param("ssiii", $q, $a, $sort, $fid, $target_admin_id);
            if ($up->execute() && $up->affected_rows >= 0) {
                $message = 'FAQ updated. FAQ file cache, Gemini FAQ context, and per-phone Gemini history for this store were refreshed.';
                faq_rebuild_cache_file($target_admin_id);
            }
            $up->close();
        }
    } elseif (isset($_POST['delete_faq'])) {
        $fid = (int) ($_POST['faq_id'] ?? 0);
        if ($fid > 0) {
            $del = $conn->prepare("DELETE FROM store_faq WHERE id = ? AND sub_admin_id = ?");
            $del->bind_param("ii", $fid, $target_admin_id);
            $del->execute();
            $del->close();
            $message = 'FAQ deleted. Caches refreshed (FAQ + Gemini context + tenant history files).';
            faq_rebuild_cache_file($target_admin_id);
        }
    } elseif (isset($_POST['answer_pending'])) {
        $pid = (int) ($_POST['pending_id'] ?? 0);
        $ans = trim($_POST['pending_answer'] ?? '');
        if ($pid > 0 && $ans !== '') {
            $sel = $conn->prepare("SELECT message_text FROM pending_questions WHERE id = ? AND sub_admin_id = ? AND status = 'open' LIMIT 1");
            $sel->bind_param("ii", $pid, $target_admin_id);
            $sel->execute();
            $row = $sel->get_result()->fetch_assoc();
            $sel->close();
            if ($row) {
                $qtext = $row['message_text'];
                $ins = $conn->prepare("INSERT INTO store_faq (sub_admin_id, question, answer, sort_order) VALUES (?, ?, ?, 99)");
                $ins->bind_param("iss", $target_admin_id, $qtext, $ans);
                $ins->execute();
                $ins->close();
                $up = $conn->prepare("UPDATE pending_questions SET status = 'answered', answered_at = NOW() WHERE id = ? AND sub_admin_id = ?");
                $up->bind_param("ii", $pid, $target_admin_id);
                $up->execute();
                $up->close();
                $message = 'Answer saved as new FAQ. Caches refreshed (FAQ + Gemini context + tenant history).';
                faq_rebuild_cache_file($target_admin_id);
            }
        }
    } elseif (isset($_POST['dismiss_pending'])) {
        $pid = (int) ($_POST['pending_id'] ?? 0);
        if ($pid > 0) {
            $up = $conn->prepare("UPDATE pending_questions SET status = 'dismissed', answered_at = NOW() WHERE id = ? AND sub_admin_id = ?");
            $up->bind_param("ii", $pid, $target_admin_id);
            $up->execute();
            $up->close();
            $message = 'Pending question dismissed.';
        }
    }
}

$faq_rows = [];
$pending_rows = [];
if ($can_manage) {
    $st = $conn->prepare("SELECT id, question, answer, sort_order, updated_at FROM store_faq WHERE sub_admin_id = ? ORDER BY sort_order ASC, id ASC");
    $st->bind_param("i", $target_admin_id);
    $st->execute();
    $faq_rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $st2 = $conn->prepare("SELECT id, customer_phone, message_text, status, created_at FROM pending_questions WHERE sub_admin_id = ? AND status = 'open' ORDER BY created_at DESC");
    $st2->bind_param("i", $target_admin_id);
    $st2->execute();
    $pending_rows = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
    $st2->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAQ</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; }
        .header { background: #667eea; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 24px; }
        .header a { color: white; text-decoration: none; padding: 8px 15px; background: rgba(255,255,255,0.2); border-radius: 5px; }
        .nav { background: white; padding: 15px 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .nav a { margin-right: 20px; text-decoration: none; color: #667eea; font-weight: bold; }
        .container { max-width: 960px; margin: 20px auto; padding: 0 20px; }
        .card { background: white; padding: 24px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .message { padding: 12px; margin-bottom: 16px; border-radius: 5px; background: #d4edda; color: #155724; }
        label { display: block; font-weight: bold; margin-bottom: 6px; color: #333; }
        input[type=text], textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; }
        textarea { min-height: 80px; resize: vertical; }
        .form-row { margin-bottom: 14px; }
        button, .btn { padding: 10px 18px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
        button.secondary { background: #6c757d; }
        button.danger { background: #dc3545; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
        th { background: #667eea; color: white; }
        .help { font-size: 13px; color: #666; margin-top: 8px; line-height: 1.5; }
        h2 { margin-bottom: 12px; font-size: 18px; color: #333; }
        .inline { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
        .inline .form-row { flex: 1; min-width: 200px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>FAQ &amp; pending questions</h1>
        <a href="dashboard.php">Dashboard</a>
    </div>
    <div class="nav">
        <a href="dashboard.php">Dashboard</a>
        <?php if ($admin_role == 'super_admin'): ?>
            <a href="sub_admins.php">Sub Admins</a>
            <a href="platform_ai.php">Platform AI</a>
        <?php endif; ?>
        <a href="leads.php">Leads</a>
        <a href="contacts.php">Contacts</a>
        <a href="faq.php">FAQ</a>
        <a href="chatgpt_history.php">ChatGPT History</a>
        <a href="gemini_history.php">Gemini History</a>
        <a href="whatsapp_history.php">WhatsApp History</a>
        <a href="settings.php">Settings</a>
    </div>
    <div class="container">
        <?php if ($message): ?><div class="message"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

        <?php if ($admin_role === 'super_admin'): ?>
            <div class="card">
                <h2>Store</h2>
                <form method="get" action="faq.php">
                    <div class="form-row">
                        <label for="store">Sub-admin (store)</label>
                        <select name="store" id="store" onchange="this.form.submit()">
                            <option value="">— Select —</option>
                            <?php foreach ($stores as $s): ?>
                                <option value="<?php echo (int) $s['id']; ?>" <?php echo $target_admin_id === (int) $s['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['username']); ?> (id <?php echo (int) $s['id']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
                <p class="help">Super admin: pick a store to manage its FAQ and pending list.</p>
            </div>
        <?php endif; ?>

        <?php if (!$can_manage): ?>
            <div class="card">
                <p class="help"><?php echo $admin_role === 'super_admin' ? 'Please select a store above.' : 'Access error.'; ?></p>
            </div>
        <?php else: ?>

            <div class="card">
                <h2>Customer questions (no FAQ match yet)</h2>
                <p class="help">When <strong>FAQ strict mode</strong> is on in <a href="settings.php">Settings</a>, unknown messages are listed here. Write an answer and save — it becomes a FAQ row and the file cache refreshes immediately.</p>
                <?php if ($pending_rows === []): ?>
                    <p style="color:#888;margin-top:10px;">No open pending questions.</p>
                <?php else: ?>
                    <?php foreach ($pending_rows as $p): ?>
                        <form method="post" style="border:1px solid #eee;padding:14px;border-radius:8px;margin-top:12px;">
                            <input type="hidden" name="target_admin_id" value="<?php echo (int) $target_admin_id; ?>">
                            <input type="hidden" name="pending_id" value="<?php echo (int) $p['id']; ?>">
                            <p><strong>Phone:</strong> <?php echo htmlspecialchars($p['customer_phone']); ?> &nbsp; <strong>At:</strong> <?php echo htmlspecialchars($p['created_at']); ?></p>
                            <p style="margin:8px 0;"><strong>They asked:</strong> <?php echo nl2br(htmlspecialchars($p['message_text'])); ?></p>
                            <div class="form-row">
                                <label>Your answer (becomes FAQ with same question text)</label>
                                <textarea name="pending_answer" required placeholder="Type the official reply..."></textarea>
                            </div>
                            <button type="submit" name="answer_pending">Save as FAQ</button>
                            <button type="submit" name="dismiss_pending" class="secondary" style="margin-left:8px;" formnovalidate onclick="return confirm('Dismiss this question?');">Dismiss</button>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>Add FAQ</h2>
                <form method="post">
                    <input type="hidden" name="target_admin_id" value="<?php echo (int) $target_admin_id; ?>">
                    <div class="form-row">
                        <label>Question (how customers usually ask)</label>
                        <textarea name="question" required placeholder="e.g. What are your delivery charges?"></textarea>
                    </div>
                    <div class="form-row">
                        <label>Answer</label>
                        <textarea name="answer" required></textarea>
                    </div>
                    <div class="form-row" style="max-width:120px;">
                        <label>Sort order</label>
                        <input type="number" name="sort_order" value="0">
                    </div>
                    <button type="submit" name="add_faq">Save FAQ</button>
                </form>
                <p class="help">Saving updates the FAQ file cache immediately, injects the latest FAQ into the next Gemini <code>systemInstruction</code>, and clears any stored per-customer Gemini history files for this store so nothing stays stale.</p>
            </div>

            <div class="card">
                <h2>Your FAQ list</h2>
                <?php if ($faq_rows === []): ?>
                    <p style="color:#888;">No entries yet. Add questions above.</p>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Q</th><th>A</th><th>Sort</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($faq_rows as $f): ?>
                            <tr>
                                <td colspan="4" style="background:#fafafa;">
                                    <form method="post" style="padding:8px 0;">
                                        <input type="hidden" name="target_admin_id" value="<?php echo (int) $target_admin_id; ?>">
                                        <input type="hidden" name="faq_id" value="<?php echo (int) $f['id']; ?>">
                                        <div class="form-row">
                                            <label>Question</label>
                                            <textarea name="question" required><?php echo htmlspecialchars($f['question']); ?></textarea>
                                        </div>
                                        <div class="form-row">
                                            <label>Answer</label>
                                            <textarea name="answer" required><?php echo htmlspecialchars($f['answer']); ?></textarea>
                                        </div>
                                        <div class="inline">
                                            <div class="form-row">
                                                <label>Sort</label>
                                                <input type="number" name="sort_order" value="<?php echo (int) $f['sort_order']; ?>">
                                            </div>
                                            <button type="submit" name="update_faq">Update</button>
                                        </div>
                                    </form>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this FAQ?');">
                                        <input type="hidden" name="target_admin_id" value="<?php echo (int) $target_admin_id; ?>">
                                        <input type="hidden" name="faq_id" value="<?php echo (int) $f['id']; ?>">
                                        <button type="submit" name="delete_faq" class="danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>
</body>
</html>
