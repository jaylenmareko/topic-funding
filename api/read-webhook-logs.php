<?php
$secret = $_GET['secret'] ?? '';
if ($secret !== getenv('CRON_SECRET')) {
    http_response_code(403);
    die('Forbidden');
}
header('Content-Type: text/plain');

try {
    $url = getenv('DATABASE_URL');
    $p = parse_url($url);
    $dsn = "pgsql:host={$p['host']};port=" . ($p['port'] ?? 5432) . ";dbname=" . ltrim($p['path'], '/');
    $pdo = new PDO($dsn, $p['user'], $p['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // Auto-create if missing so endpoint never errors
    $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_logs (id SERIAL PRIMARY KEY, level VARCHAR(20), message TEXT, created_at TIMESTAMP DEFAULT NOW())");

    $stmt = $pdo->query("SELECT created_at, level, message FROM webhook_logs ORDER BY id DESC LIMIT 300");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo "(no log entries yet)\n";
        exit;
    }

    echo "=== WEBHOOK LOGS (newest first, last 300 entries) ===\n\n";
    foreach ($rows as $r) {
        echo "[{$r['created_at']}] [{$r['level']}] {$r['message']}\n";
    }

    if (isset($_GET['clear']) && $_GET['clear'] === '1') {
        $pdo->exec("TRUNCATE webhook_logs RESTART IDENTITY");
        echo "\n--- Logs cleared ---\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
