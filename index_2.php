<?php
/**
 * Demo page: list repos, recent commits, open issues, and create an issue.
 */
session_start();
require __DIR__ . '/GitHubClient.php';

$config = require __DIR__ . '/config.php';
$gh     = new GitHubClient($config);

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// Allow switching repo via ?repo=owner/name, fall back to config
$owner = $config['owner'];
$repo  = $config['repo'];
if (!empty($_GET['repo']) && preg_match('#^([\w.-]+)/([\w.-]+)$#', $_GET['repo'], $m)) {
    [, $owner, $repo] = $m;
}

$flash  = '';
$errors = [];

// Handle "create issue" form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_issue') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid form token.';
    } elseif (trim($_POST['title'] ?? '') === '') {
        $errors[] = 'Issue title is required.';
    } else {
        try {
            $issue = $gh->post("/repos/{$owner}/{$repo}/issues", [
                'title' => trim($_POST['title']),
                'body'  => trim($_POST['body'] ?? ''),
            ]);
            $flash = 'Issue #' . $issue['number'] . ' created.';
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
}

// Fetch data for the page
$user = $repos = $commits = $issues = [];
try {
    $user  = $gh->get('/user');
    $repos = $gh->get('/user/repos', ['per_page' => 20, 'sort' => 'updated']);

    $commits = $gh->get("/repos/{$owner}/{$repo}/commits", ['per_page' => 10]);

    // The issues endpoint also returns pull requests; filter them out
    $issues = array_filter(
        $gh->get("/repos/{$owner}/{$repo}/issues", ['state' => 'open', 'per_page' => 20]),
        fn($i) => !isset($i['pull_request'])
    );
} catch (RuntimeException $ex) {
    $errors[] = $ex->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GitHub API Demo</title>
<style>
    body { font-family: system-ui, sans-serif; max-width: 1000px; margin: 24px auto; padding: 0 16px; color: #1f2328; }
    h1 { font-size: 22px; } h2 { font-size: 17px; margin-top: 28px; border-bottom: 1px solid #d0d7de; padding-bottom: 6px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
    @media (max-width: 760px) { .grid { grid-template-columns: 1fr; } }
    ul { list-style: none; padding: 0; margin: 0; }
    li { padding: 8px 0; border-bottom: 1px solid #eaeef2; font-size: 14px; }
    small { color: #656d76; }
    a { color: #0969da; text-decoration: none; }
    .alert { padding: 10px 12px; border-radius: 6px; margin: 12px 0; font-size: 14px; }
    .ok { background: #dafbe1; } .err { background: #ffebe9; }
    input, textarea { width: 100%; padding: 8px; margin: 6px 0; border: 1px solid #d0d7de; border-radius: 6px; box-sizing: border-box; }
    button { background: #1f883d; color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; cursor: pointer; }
    .meta { font-size: 13px; color: #656d76; }
</style>
</head>
<body>

<h1>GitHub API Demo</h1>
<?php if ($user): ?>
    <p class="meta">
        Logged in as <strong><?= e($user['login']) ?></strong> ·
        Rate limit: <?= e($gh->rateLimit['remaining'] ?? '-') ?>/<?= e($gh->rateLimit['limit'] ?? '-') ?> left
    </p>
<?php endif; ?>

<?php if ($flash): ?><div class="alert ok"><?= e($flash) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert err"><?= e($err) ?></div><?php endforeach; ?>

<div class="grid">
    <section>
        <h2>Your repositories</h2>
        <ul>
            <?php foreach ($repos as $r): ?>
                <li>
                    <a href="?repo=<?= e($r['full_name']) ?>"><?= e($r['full_name']) ?></a>
                    <?= $r['private'] ? '<small>(private)</small>' : '' ?><br>
                    <small><?= e($r['language'] ?? '—') ?> · ★ <?= (int) $r['stargazers_count'] ?>
                        · updated <?= e(date('d M Y', strtotime($r['updated_at']))) ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section>
        <h2>Recent commits — <?= e("$owner/$repo") ?></h2>
        <ul>
            <?php foreach ($commits as $c): ?>
                <li>
                    <a href="<?= e($c['html_url']) ?>" target="_blank" rel="noopener">
                        <?= e(substr($c['sha'], 0, 7)) ?></a>
                    <?= e(strtok($c['commit']['message'], "\n")) ?><br>
                    <small><?= e($c['commit']['author']['name']) ?> ·
                        <?= e(date('d M Y H:i', strtotime($c['commit']['author']['date']))) ?></small>
                </li>
            <?php endforeach; ?>
        </ul>

        <h2>Open issues</h2>
        <ul>
            <?php if (!$issues): ?><li><small>No open issues.</small></li><?php endif; ?>
            <?php foreach ($issues as $i): ?>
                <li>
                    <a href="<?= e($i['html_url']) ?>" target="_blank" rel="noopener">#<?= (int) $i['number'] ?></a>
                    <?= e($i['title']) ?><br>
                    <small>by <?= e($i['user']['login']) ?></small>
                </li>
            <?php endforeach; ?>
        </ul>

        <h2>Create issue</h2>
        <form method="post">
            <input type="hidden" name="action" value="create_issue">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input name="title" placeholder="Issue title" required>
            <textarea name="body" rows="4" placeholder="Description (optional)"></textarea>
            <button type="submit">Create issue</button>
        </form>
    </section>
</div>

</body>
</html>
