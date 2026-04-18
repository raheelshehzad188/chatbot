<?php
/**
 * Super admin: store categories (name + developer prompt) + per-category JSON cache + recache all.
 */
session_start();
require_once '../config.php';
require_once '../functions.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'super_admin') {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
categories_ensure_schema($conn);
faq_ensure_schema($conn);

$message = '';
$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$edit_row = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['recache_all'])) {
        faq_rebuild_all_stores_and_categories();
        $message = 'All store caches and per-category JSON files have been rebuilt.';
    } elseif (isset($_POST['add_category'])) {
        $name = trim($_POST['name'] ?? '');
        $developer_prompt = trim($_POST['developer_prompt'] ?? '');
        if ($name === '') {
            $message = 'Category name is required.';
        } else {
            $ins = $conn->prepare('INSERT INTO store_categories (name, developer_prompt) VALUES (?, ?)');
            $ins->bind_param('ss', $name, $developer_prompt);
            if ($ins->execute()) {
                $newId = (int) $conn->insert_id;
                $ins->close();
                $st = $conn->prepare('SELECT id FROM admins WHERE role = ? AND category_id = ?');
                $role = 'sub_admin';
                $st->bind_param('si', $role, $newId);
                $st->execute();
                $res = $st->get_result();
                while ($row = $res->fetch_assoc()) {
                    faq_rebuild_cache_file((int) $row['id']);
                }
                $st->close();
                category_rebuild_aggregate_cache($newId);
                $message = 'Category added and caches updated.';
            } else {
                $message = 'Could not add category.';
                $ins->close();
            }
        }
    } elseif (isset($_POST['update_category'])) {
        $id = (int) ($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $developer_prompt = trim($_POST['developer_prompt'] ?? '');
        if ($id <= 0 || $name === '') {
            $message = 'Invalid update.';
        } else {
            $up = $conn->prepare('UPDATE store_categories SET name = ?, developer_prompt = ? WHERE id = ?');
            $up->bind_param('ssi', $name, $developer_prompt, $id);
            if ($up->execute()) {
                $up->close();
                $st = $conn->prepare('SELECT id FROM admins WHERE role = ? AND category_id = ?');
                $role = 'sub_admin';
                $st->bind_param('si', $role, $id);
                $st->execute();
                $resStores = $st->get_result();
                while ($row = $resStores->fetch_assoc()) {
                    faq_rebuild_cache_file((int) $row['id']);
                }
                $st->close();
                category_rebuild_aggregate_cache($id);
                $message = 'Category saved. Store JSON + category JSON + Gemini prompt context updated.';
            } else {
                $message = 'Update failed.';
                $up->close();
            }
        }
    } elseif (isset($_POST['delete_category'])) {
        $id = (int) ($_POST['category_id'] ?? 0);
        if ($id > 0) {
            $del = $conn->prepare('DELETE FROM store_categories WHERE id = ?');
            $del->bind_param('i', $id);
            if ($del->execute()) {
                @unlink(faq_category_cache_file_path($id));
                $message = 'Category deleted (sub-admins unlinked via database).';
            }
            $del->close();
        }
    }
}

if ($edit_id > 0) {
    $est = $conn->prepare('SELECT id, name, developer_prompt FROM store_categories WHERE id = ? LIMIT 1');
    $est->bind_param('i', $edit_id);
    $est->execute();
    $edit_row = $est->get_result()->fetch_assoc();
    $est->close();
    if (!$edit_row) {
        $edit_id = 0;
    }
}

$rows = $conn->query('SELECT c.id, c.name, c.developer_prompt, c.updated_at,
    (SELECT COUNT(*) FROM admins a WHERE a.role = \'sub_admin\' AND a.category_id = c.id) AS store_count
    FROM store_categories c ORDER BY c.name ASC')->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store categories</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; }
        .header { background: #667eea; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .header h1 { font-size: 22px; }
        .header a { color: white; text-decoration: none; padding: 8px 15px; background: rgba(255,255,255,0.2); border-radius: 5px; }
        .nav { background: white; padding: 15px 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .nav a { margin-right: 18px; text-decoration: none; color: #667eea; font-weight: bold; }
        .container { max-width: 1000px; margin: 20px auto; padding: 0 20px; }
        .card { background: white; padding: 24px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .message { padding: 12px; margin-bottom: 16px; border-radius: 5px; background: #d4edda; color: #155724; }
        label { display: block; font-weight: bold; margin-bottom: 6px; color: #333; }
        input[type=text], textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; }
        textarea { min-height: 140px; font-family: Consolas, monospace; }
        .form-group { margin-bottom: 14px; }
        button, .btn { padding: 10px 18px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
        button.warn { background: #fd7e14; }
        button.danger { background: #dc3545; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
        th { background: #667eea; color: white; }
        .help { font-size: 13px; color: #666; margin-top: 8px; line-height: 1.5; }
        code { background: #f0f0f0; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Store categories</h1>
        <a href="dashboard.php">Dashboard</a>
    </div>
    <div class="nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="sub_admins.php">Sub Admins</a>
        <a href="categories.php">Categories</a>
        <a href="platform_ai.php">Platform AI</a>
        <a href="faq.php">FAQ</a>
        <a href="settings.php">Settings</a>
    </div>
    <div class="container">
        <?php if ($message): ?><div class="message"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

        <div class="card">
            <h2 style="margin-bottom:10px;">Recache everything</h2>
            <p class="help">Rebuilds every sub-admin <code>cache/faq/store_*.json</code>, each <code>cache/faq/category_*.json</code>, and clears per-phone Gemini tenant files for each store. Use after bulk DB changes or if caches look wrong.</p>
            <form method="post" onsubmit="return confirm('Rebuild all store + category FAQ caches? This may take a moment.');">
                <button type="submit" name="recache_all" value="1" class="warn">Recache all</button>
            </form>
        </div>

        <?php if ($edit_row): ?>
        <div class="card">
            <h2>Edit category</h2>
            <form method="post" action="categories.php?edit=<?php echo (int) $edit_row['id']; ?>">
                <input type="hidden" name="category_id" value="<?php echo (int) $edit_row['id']; ?>">
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="name" required value="<?php echo htmlspecialchars($edit_row['name']); ?>">
                </div>
                <div class="form-group">
                    <label>Developer prompt</label>
                    <textarea name="developer_prompt" placeholder="Instructions merged into Gemini / FAQ cache for every store in this category…"><?php echo htmlspecialchars($edit_row['developer_prompt']); ?></textarea>
                </div>
                <button type="submit" name="update_category" value="1">Save</button>
                <a href="categories.php" style="margin-left:12px;">Cancel</a>
            </form>
        </div>
        <?php else: ?>
        <div class="card">
            <h2>Add category</h2>
            <form method="post">
                <div class="form-group">
                    <label>Category name</label>
                    <input type="text" name="name" required placeholder="e.g. Electronics, Fashion">
                </div>
                <div class="form-group">
                    <label>Developer prompt</label>
                    <textarea name="developer_prompt" placeholder="JSON-oriented rules, tone, or structured hints for this category…"></textarea>
                    <p class="help">This text is concatenated <strong>before</strong> each store’s FAQ when building cache and Gemini <code>systemInstruction</code>. Per-category combined JSON is written to <code>cache/faq/category_{id}.json</code>.</p>
                </div>
                <button type="submit" name="add_category" value="1">Add category</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2 style="margin-bottom:12px;">All categories</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Stores</th>
                        <th>Developer prompt</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="6">No categories yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?php echo (int) $r['id']; ?></td>
                                <td><?php echo htmlspecialchars($r['name']); ?></td>
                                <td><?php echo (int) $r['store_count']; ?></td>
                                <td style="max-width:280px;word-break:break-word;font-size:12px;"><?php
                                    $dp = (string) ($r['developer_prompt'] ?? '');
                                    $snip = function_exists('mb_substr') ? mb_substr($dp, 0, 200, 'UTF-8') : substr($dp, 0, 200);
                                    echo nl2br(htmlspecialchars($snip));
                                    echo strlen($dp) > strlen($snip) ? '…' : '';
                                ?></td>
                                <td><?php echo htmlspecialchars($r['updated_at'] ?? ''); ?></td>
                                <td>
                                    <a href="?edit=<?php echo (int) $r['id']; ?>" class="btn" style="padding:6px 12px;font-size:13px;">Edit</a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this category? Sub-admins will be unlinked.');">
                                        <input type="hidden" name="category_id" value="<?php echo (int) $r['id']; ?>">
                                        <button type="submit" name="delete_category" class="danger" style="padding:6px 12px;font-size:13px;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
