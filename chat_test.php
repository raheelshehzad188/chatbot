<?php
/**
 * Public test-chat page (open from admin via unique token link).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';

$conn = getDBConnection();
$settings = load_sub_admin_settings_by_test_chat_token($conn, $token);
$conn->close();

if (!$settings) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Invalid link</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;"><p>Invalid or expired test link.</p></body></html>';
    exit;
}

$storeLabel = htmlspecialchars($settings['store_username'] ?? 'Store', ENT_QUOTES, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test chat — <?php echo $storeLabel; ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: #e8eaf0; min-height: 100vh; display: flex; flex-direction: column; }
        .top {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
            padding: 14px 18px;
            font-size: 15px;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0,0,0,.12);
        }
        .top small { display: block; font-weight: 400; opacity: .9; font-size: 12px; margin-top: 4px; }
        .chat-wrap { flex: 1; display: flex; flex-direction: column; max-width: 560px; margin: 0 auto; width: 100%; padding: 12px; }
        #messages {
            flex: 1; overflow-y: auto; padding: 8px 4px 100px;
            display: flex; flex-direction: column; gap: 10px;
        }
        .bubble { max-width: 88%; padding: 10px 14px; border-radius: 16px; line-height: 1.45; font-size: 15px; white-space: pre-wrap; word-break: break-word; }
        .bubble.user { align-self: flex-end; background: #667eea; color: #fff; border-bottom-right-radius: 4px; }
        .bubble.bot { align-self: flex-start; background: #fff; color: #222; border: 1px solid #e0e0e0; border-bottom-left-radius: 4px; }
        .bubble.err { align-self: flex-start; background: #fde8e8; color: #a00; border: 1px solid #f5c2c2; }
        .bubble.typing { align-self: flex-start; background: #fff; color: #888; font-style: italic; border: 1px dashed #ccc; }
        .composer {
            position: fixed; bottom: 0; left: 0; right: 0; background: #fff; border-top: 1px solid #ddd;
            padding: 10px 12px; display: flex; gap: 8px; align-items: flex-end; max-width: 560px; margin: 0 auto; width: 100%;
            box-shadow: 0 -2px 10px rgba(0,0,0,.06);
        }
        .composer textarea {
            flex: 1; border: 1px solid #ccc; border-radius: 12px; padding: 10px 12px; font-size: 15px; resize: none; min-height: 44px; max-height: 120px; font-family: inherit;
        }
        .composer button {
            background: #667eea; color: #fff; border: none; border-radius: 12px; padding: 10px 18px; font-weight: 600; cursor: pointer; font-size: 15px;
        }
        .composer button:disabled { opacity: .55; cursor: not-allowed; }
    </style>
</head>
<body>
    <div class="top">
        Test bot — <?php echo $storeLabel; ?>
        <small>Messages are not sent on WhatsApp. Same FAQ + AI rules as your live bot.</small>
    </div>
    <div class="chat-wrap">
        <div id="messages"></div>
    </div>
    <form class="composer" id="f" action="#" method="post">
        <textarea id="msg" rows="1" placeholder="Type a message…" autocomplete="off"></textarea>
        <button type="submit" id="send">Send</button>
    </form>
    <script>
(function () {
    var token = <?php echo json_encode($token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    var apiUrl = <?php echo json_encode(base_url('chat_test_api.php'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    var messages = document.getElementById('messages');
    var form = document.getElementById('f');
    var input = document.getElementById('msg');
    var btn = document.getElementById('send');

    function addBubble(text, role) {
        var d = document.createElement('div');
        d.className = 'bubble ' + role;
        d.textContent = text;
        messages.appendChild(d);
        messages.scrollTop = messages.scrollHeight;
    }

    function setLoading(on) {
        btn.disabled = on;
        input.disabled = on;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = (input.value || '').trim();
        if (!text) return;
        input.value = '';
        addBubble(text, 'user');
        var typing = document.createElement('div');
        typing.className = 'bubble typing';
        typing.textContent = '…';
        messages.appendChild(typing);
        messages.scrollTop = messages.scrollHeight;
        setLoading(true);

        fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: token, message: text })
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (x) {
            typing.remove();
            if (x.j && x.j.ok && typeof x.j.reply === 'string') {
                addBubble(x.j.reply, 'bot');
            } else {
                addBubble((x.j && x.j.error) ? x.j.error : 'Something went wrong.', 'err');
            }
        })
        .catch(function () {
            typing.remove();
            addBubble('Network error. Try again.', 'err');
        })
        .finally(function () {
            setLoading(false);
            input.focus();
        });
    });

    input.focus();
})();
    </script>
</body>
</html>
