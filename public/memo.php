<?php
declare(strict_types=1);

use App\Database;
use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

// 환경 변수 로드
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

// DB 연결
$pdo = Database::getInstance()->getConnection();

$errors = [];
$notice = $_GET['notice'] ?? null;

// 메모 저장/수정/삭제
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $content = trim($_POST['content'] ?? '');

    if ($action === 'create') {
        if ($content === '') {
            $errors[] = '메모를 입력해 주세요.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO memos (content) VALUES (:content)');
            $stmt->execute([':content' => $content]);
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?notice=created');
            exit;
        }
    } elseif ($action === 'update') {
        if ($id < 1) {
            $errors[] = '잘못된 요청입니다.';
        } elseif ($content === '') {
            $errors[] = '메모 내용을 비워둘 수 없어요.';
        } else {
            $stmt = $pdo->prepare('UPDATE memos SET content = :content WHERE id = :id');
            $stmt->execute([':content' => $content, ':id' => $id]);
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?notice=updated');
            exit;
        }
    } elseif ($action === 'delete') {
        if ($id < 1) {
            $errors[] = '잘못된 요청입니다.';
        } else {
            $stmt = $pdo->prepare('DELETE FROM memos WHERE id = :id');
            $stmt->execute([':id' => $id]);
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?notice=deleted');
            exit;
        }
    }
}

if ($notice === 'created') {
    $notice = '메모를 저장했어요.';
} elseif ($notice === 'updated') {
    $notice = '메모를 수정했어요.';
} elseif ($notice === 'deleted') {
    $notice = '메모를 삭제했어요.';
}

// 최신순 메모 불러오기
$memos = $pdo->query('SELECT id, content, created_at FROM memos ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>메모장</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&family=Noto+Sans+KR:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: radial-gradient(circle at 20% 20%, #e6f0ff, #f7f8fb 50%);
            --card: #ffffff;
            --text: #0f172a;
            --muted: #475569;
            --accent: #2563eb;
            --accent-strong: #1d4ed8;
            --border: #e2e8f0;
            --shadow: 0 8px 30px rgba(15, 23, 42, 0.1);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            font-family: 'Space Grotesk', 'Noto Sans KR', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .page {
            max-width: 960px;
            margin: 0 auto;
            padding: 32px 20px 40px;
        }

        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
        }

        h1 {
            font-size: 28px;
            margin: 0;
            letter-spacing: -0.02em;
        }

        .subtitle {
            color: var(--muted);
            margin: 4px 0 0;
            font-size: 15px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            box-shadow: var(--shadow);
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        textarea {
            width: 100%;
            min-height: 140px;
            resize: vertical;
            padding: 14px;
            border-radius: 12px;
            border: 1px solid var(--border);
            font-size: 15px;
            font-family: inherit;
            background: #f8fafc;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        button {
            border: none;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.05s ease, box-shadow 0.2s ease;
        }

.btn-primary {
            background: var(--accent);
            color: #fff;
            box-shadow: 0 10px 30px rgba(37, 99, 235, 0.25);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
            border: 1px solid var(--border);
        }

        .btn-danger {
            background: #ef4444;
            color: #fff;
        }

        .btn-primary:active { transform: translateY(1px); }

        .alert {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 14px;
        }

        .alert.error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecdd3;
        }

        .alert.success {
            background: #ecfdf3;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .memos {
            margin-top: 20px;
            display: grid;
            gap: 12px;
        }

        .memo {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
        }

        .memo time {
            display: block;
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 8px;
        }

        .memo .content {
            white-space: pre-wrap;
            line-height: 1.6;
        }

        .memo-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .edit-panel {
            margin-top: 10px;
            border-top: 1px dashed var(--border);
            padding-top: 10px;
        }

        details {
            margin-top: 16px;
            border-radius: 12px;
            border: 1px dashed var(--border);
            padding: 10px 14px;
            background: #f8fafc;
        }

        summary {
            cursor: pointer;
            font-weight: 600;
            color: var(--accent);
        }

        code, pre {
            font-family: 'Space Grotesk', Consolas, Menlo, monospace;
            background: #0f172a;
            color: #e2e8f0;
            border-radius: 10px;
        }

        pre {
            padding: 14px;
            overflow-x: auto;
            margin: 10px 0 0;
        }

        @media (max-width: 640px) {
            .page { padding: 20px 14px 28px; }
            header { flex-direction: column; align-items: flex-start; }
            h1 { font-size: 24px; }
            textarea { min-height: 160px; }
            .actions { justify-content: stretch; }
            .btn-primary { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="page">
        <header>
            <div>
                <h1>빠른 메모</h1>
                <p class="subtitle">바로 입력하고 최신순으로 확인하세요. 모바일에서도 편하게 사용됩니다.</p>
            </div>
        </header>

        <section class="card">
            <form method="POST" action="">
                <label for="content" style="font-weight:600;">메모 입력</label>
                <textarea id="content" name="content" placeholder="아이디어, 할 일, 회의 메모 등을 자유롭게 적어주세요." required></textarea>
                <div class="actions">
                    <button type="submit" class="btn-primary">메모 저장</button>
                </div>
            </form>

            <?php if (!empty($errors)): ?>
                <div class="alert error"><?php echo htmlspecialchars(implode(' ', $errors), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($notice): ?>
                <div class="alert success"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </section>

        <section class="memos" aria-label="저장된 메모">
            <?php if (empty($memos)): ?>
                <div class="memo">
                    <time>아직 메모가 없습니다.</time>
                    <div class="content">첫 번째 메모를 추가해 보세요.</div>
                </div>
            <?php else: ?>
                <?php foreach ($memos as $memo): ?>
                    <article class="memo">
                        <time><?php echo htmlspecialchars($memo['created_at'], ENT_QUOTES, 'UTF-8'); ?></time>
                        <div class="content"><?php echo nl2br(htmlspecialchars($memo['content'], ENT_QUOTES, 'UTF-8')); ?></div>
                        <div class="memo-actions">
                            <button class="btn-secondary" type="button" data-toggle="edit" data-id="<?php echo $memo['id']; ?>">수정</button>
                            <form method="POST" action="" onsubmit="return confirm('이 메모를 삭제할까요?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $memo['id']; ?>">
                                <button class="btn-danger" type="submit">삭제</button>
                            </form>
                        </div>
                        <div class="edit-panel" id="edit-<?php echo $memo['id']; ?>" style="display:none;">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?php echo $memo['id']; ?>">
                                <textarea name="content" rows="4"><?php echo htmlspecialchars($memo['content'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                                <div class="actions" style="justify-content:flex-start; gap:8px;">
                                    <button type="submit" class="btn-primary">저장</button>
                                    <button type="button" class="btn-secondary" data-toggle="edit" data-id="<?php echo $memo['id']; ?>">닫기</button>
                                </div>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>
<script>
document.querySelectorAll('[data-toggle="edit"]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id = this.getAttribute('data-id');
        var panel = document.getElementById('edit-' + id);
        if (panel) {
            var isOpen = panel.style.display === 'block';
            panel.style.display = isOpen ? 'none' : 'block';
        }
    });
});
</script>
