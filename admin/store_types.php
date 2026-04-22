<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'super_admin') {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
require_once __DIR__ . '/../functions.php';
faq_ensure_schema($conn);
store_config_ensure_schema($conn);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_type'])) {
        $typeId = (int) ($_POST['type_id'] ?? 0);
        $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
        $title = trim((string) ($_POST['title'] ?? ''));
        $details = trim((string) ($_POST['details'] ?? ''));
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);

        if ($slug === '' || !preg_match('/^[a-z0-9_-]{2,64}$/', $slug)) {
            $message = 'Invalid slug. Use lowercase letters, numbers, dash or underscore.';
        } elseif ($title === '') {
            $message = 'Store type title is required.';
        } else {
            if ($typeId > 0) {
                $stmt = $conn->prepare('UPDATE store_type_definitions SET slug = ?, title = ?, details = ?, is_active = ?, sort_order = ? WHERE id = ?');
                $stmt->bind_param('sssiii', $slug, $title, $details, $isActive, $sortOrder, $typeId);
                if ($stmt->execute()) {
                    $message = 'Store type updated.';
                } else {
                    $message = 'Could not update store type.';
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO store_type_definitions (slug, title, details, is_active, sort_order) VALUES (?, ?, ?, ?, ?)');
                $stmt->bind_param('sssii', $slug, $title, $details, $isActive, $sortOrder);
                if ($stmt->execute()) {
                    $message = 'Store type added.';
                } else {
                    $message = 'Could not add store type (maybe duplicate slug).';
                }
                $stmt->close();
            }
        }
    } elseif (isset($_POST['delete_type'])) {
        $typeId = (int) ($_POST['type_id'] ?? 0);
        if ($typeId > 0) {
            $s = $conn->prepare('SELECT slug FROM store_type_definitions WHERE id = ? LIMIT 1');
            $s->bind_param('i', $typeId);
            $s->execute();
            $row = $s->get_result()->fetch_assoc();
            $s->close();
            if ($row && !empty($row['slug'])) {
                $slug = (string) $row['slug'];
                $df = $conn->prepare('DELETE FROM store_type_fields WHERE store_type_slug = ?');
                $df->bind_param('s', $slug);
                $df->execute();
                $df->close();
            }
            $stmt = $conn->prepare('DELETE FROM store_type_definitions WHERE id = ?');
            $stmt->bind_param('i', $typeId);
            $stmt->execute();
            $stmt->close();
            $message = 'Store type deleted.';
        }
    } elseif (isset($_POST['save_field'])) {
        $fieldId = (int) ($_POST['field_id'] ?? 0);
        $storeTypeSlug = strtolower(trim((string) ($_POST['store_type_slug'] ?? '')));
        $fieldKey = strtolower(trim((string) ($_POST['field_key'] ?? '')));
        $fieldLabel = trim((string) ($_POST['field_label'] ?? ''));
        $fieldType = trim((string) ($_POST['field_type'] ?? 'text'));
        $placeholder = trim((string) ($_POST['placeholder'] ?? ''));
        $helpText = trim((string) ($_POST['help_text'] ?? ''));
        $optionsJson = trim((string) ($_POST['options_json'] ?? ''));
        $isRequired = !empty($_POST['is_required']) ? 1 : 0;
        $isActive = !empty($_POST['field_is_active']) ? 1 : 0;
        $sortOrder = (int) ($_POST['field_sort_order'] ?? 0);
        $allowedTypes = ['text', 'textarea', 'number', 'password', 'select'];

        if ($storeTypeSlug === '' || $fieldKey === '' || $fieldLabel === '') {
            $message = 'Store type, field key and field label are required.';
        } elseif (!preg_match('/^[a-z0-9_\\-]{2,100}$/', $fieldKey)) {
            $message = 'Invalid field key format.';
        } elseif (!in_array($fieldType, $allowedTypes, true)) {
            $message = 'Invalid field type.';
        } else {
            if ($optionsJson !== '') {
                $decoded = json_decode($optionsJson, true);
                if (!is_array($decoded)) {
                    $message = 'options_json must be a valid JSON array (e.g. ["basic","pro"]).';
                }
            }
            if ($message === '') {
                if ($fieldId > 0) {
                    $stmt = $conn->prepare('UPDATE store_type_fields SET store_type_slug = ?, field_key = ?, field_label = ?, field_type = ?, placeholder = ?, help_text = ?, options_json = ?, is_required = ?, is_active = ?, sort_order = ? WHERE id = ?');
                    $stmt->bind_param('sssssssiiii', $storeTypeSlug, $fieldKey, $fieldLabel, $fieldType, $placeholder, $helpText, $optionsJson, $isRequired, $isActive, $sortOrder, $fieldId);
                    if ($stmt->execute()) {
                        $message = 'Field updated.';
                    } else {
                        $message = 'Could not update field (maybe duplicate key).';
                    }
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare('INSERT INTO store_type_fields (store_type_slug, field_key, field_label, field_type, placeholder, help_text, options_json, is_required, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->bind_param('sssssssiii', $storeTypeSlug, $fieldKey, $fieldLabel, $fieldType, $placeholder, $helpText, $optionsJson, $isRequired, $isActive, $sortOrder);
                    if ($stmt->execute()) {
                        $message = 'Field added.';
                    } else {
                        $message = 'Could not add field (maybe duplicate key).';
                    }
                    $stmt->close();
                }
            }
        }
    } elseif (isset($_POST['delete_field'])) {
        $fieldId = (int) ($_POST['field_id'] ?? 0);
        if ($fieldId > 0) {
            $stmt = $conn->prepare('DELETE FROM store_type_fields WHERE id = ?');
            $stmt->bind_param('i', $fieldId);
            $stmt->execute();
            $stmt->close();
            $message = 'Field deleted.';
        }
    }
}

$types = store_type_get_definitions($conn, false);
$fieldsBySlug = [];
foreach ($types as $t) {
    $slug = (string) ($t['slug'] ?? '');
    if ($slug === '') {
        continue;
    }
    $fieldsBySlug[$slug] = store_type_get_fields($conn, $slug, false);
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Type Configuration</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; }
        .header { background: #667eea; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; }
        .header a { color: white; text-decoration: none; padding: 8px 15px; background: rgba(255,255,255,0.2); border-radius: 5px; }
        .nav { background: white; padding: 15px 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .nav a { margin-right: 20px; text-decoration: none; color: #667eea; font-weight: bold; }
        .container { max-width: 1200px; margin: 20px auto; padding: 0 20px; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .message { padding: 12px; margin-bottom: 20px; border-radius: 5px; background: #d4edda; color: #155724; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .form-group { margin-bottom: 12px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; font-family: Arial, sans-serif; }
        textarea { min-height: 90px; resize: vertical; }
        button { padding: 10px 16px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer; }
        button:hover { background: #5568d3; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #b62b3a; }
        .type-block { border: 1px solid #e8e8e8; border-radius: 8px; padding: 14px; margin-top: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border-bottom: 1px solid #eee; text-align: left; padding: 8px; font-size: 13px; vertical-align: top; }
        th { background: #f7f7ff; }
        .muted { color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Store Type Configuration</h1>
        <a href="dashboard.php">Back to Dashboard</a>
    </div>
    <div class="nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="sub_admins.php">Sub Admins</a>
        <a href="categories.php">Categories</a>
        <a href="store_types.php">Store Types</a>
        <a href="platform_ai.php">Platform AI</a>
    </div>

    <div class="container">
        <?php if ($message !== ''): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="card">
            <h2 style="margin-bottom: 12px;">Add / Update Store Type</h2>
            <p class="muted" style="margin-bottom: 12px;">Define store types like ecommerce/service and their description shown to sub-admins.</p>
            <form method="POST" class="grid">
                <input type="hidden" name="type_id" value="0">
                <div class="form-group">
                    <label>Slug</label>
                    <input type="text" name="slug" placeholder="ecommerce" required>
                </div>
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" placeholder="Ecommerce" required>
                </div>
                <div class="form-group">
                    <label>Sort Order</label>
                    <input type="number" name="sort_order" value="0">
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="is_active" value="1" checked style="width:auto;">
                        Active
                    </label>
                </div>
                <div class="form-group" style="grid-column:1 / -1;">
                    <label>Details (shown on sub-admin settings)</label>
                    <textarea name="details" placeholder="Explain this type and how to configure it."></textarea>
                </div>
                <div>
                    <button type="submit" name="save_type" value="1">Save Store Type</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 12px;">Add / Update Store Type Field</h2>
            <p class="muted" style="margin-bottom: 12px;">These fields appear for selected store type in sub-admin settings.</p>
            <form method="POST" class="grid">
                <input type="hidden" name="field_id" value="0">
                <div class="form-group">
                    <label>Store Type</label>
                    <select name="store_type_slug" required>
                        <option value="">Select type</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?php echo htmlspecialchars((string) $t['slug']); ?>"><?php echo htmlspecialchars((string) $t['title']); ?> (<?php echo htmlspecialchars((string) $t['slug']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Field Key</label>
                    <input type="text" name="field_key" placeholder="whatsapp_api_token" required>
                </div>
                <div class="form-group">
                    <label>Field Label</label>
                    <input type="text" name="field_label" placeholder="WhatsApp API Token" required>
                </div>
                <div class="form-group">
                    <label>Field Type</label>
                    <select name="field_type">
                        <option value="text">text</option>
                        <option value="textarea">textarea</option>
                        <option value="number">number</option>
                        <option value="password">password</option>
                        <option value="select">select</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sort Order</label>
                    <input type="number" name="field_sort_order" value="0">
                </div>
                <div class="form-group">
                    <label>Placeholder</label>
                    <input type="text" name="placeholder" placeholder="Placeholder text">
                </div>
                <div class="form-group">
                    <label>Help Text</label>
                    <input type="text" name="help_text" placeholder="Short guidance shown under field">
                </div>
                <div class="form-group">
                    <label>Options JSON (for select)</label>
                    <input type="text" name="options_json" placeholder='["basic","pro"]'>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="is_required" value="1" style="width:auto;">
                        Required
                    </label>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="field_is_active" value="1" checked style="width:auto;">
                        Active
                    </label>
                </div>
                <div>
                    <button type="submit" name="save_field" value="1">Save Field</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Current Store Types and Fields</h2>
            <?php if (empty($types)): ?>
                <p class="muted">No store type configured.</p>
            <?php else: ?>
                <?php foreach ($types as $t): ?>
                    <?php $slug = (string) ($t['slug'] ?? ''); ?>
                    <div class="type-block">
                        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
                            <div>
                                <h3><?php echo htmlspecialchars((string) ($t['title'] ?? $slug)); ?> <span class="muted">(<?php echo htmlspecialchars($slug); ?>)</span></h3>
                                <div class="muted">Active: <?php echo !empty($t['is_active']) ? 'Yes' : 'No'; ?> | Sort: <?php echo (int) ($t['sort_order'] ?? 0); ?></div>
                                <?php if (!empty($t['details'])): ?>
                                    <p style="margin-top:8px;"><?php echo nl2br(htmlspecialchars((string) $t['details'])); ?></p>
                                <?php endif; ?>
                            </div>
                            <form method="POST" onsubmit="return confirm('Delete this store type and all its fields?');">
                                <input type="hidden" name="type_id" value="<?php echo (int) $t['id']; ?>">
                                <button type="submit" name="delete_type" value="1" class="btn-danger">Delete type</button>
                            </form>
                        </div>

                        <table>
                            <thead>
                                <tr>
                                    <th>Key</th>
                                    <th>Label</th>
                                    <th>Type</th>
                                    <th>Required</th>
                                    <th>Active</th>
                                    <th>Sort</th>
                                    <th>Help</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rows = $fieldsBySlug[$slug] ?? []; ?>
                                <?php if (empty($rows)): ?>
                                    <tr><td colspan="8" class="muted">No fields yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $f): ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars((string) ($f['field_key'] ?? '')); ?></code></td>
                                            <td><?php echo htmlspecialchars((string) ($f['field_label'] ?? '')); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($f['field_type'] ?? 'text')); ?></td>
                                            <td><?php echo !empty($f['is_required']) ? 'Yes' : 'No'; ?></td>
                                            <td><?php echo !empty($f['is_active']) ? 'Yes' : 'No'; ?></td>
                                            <td><?php echo (int) ($f['sort_order'] ?? 0); ?></td>
                                            <td class="muted"><?php echo htmlspecialchars((string) ($f['help_text'] ?? '')); ?></td>
                                            <td>
                                                <form method="POST" onsubmit="return confirm('Delete this field?');">
                                                    <input type="hidden" name="field_id" value="<?php echo (int) $f['id']; ?>">
                                                    <button type="submit" name="delete_field" value="1" class="btn-danger">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

