<?php

// Cron à lancer une fois par jour.
// Exemple crontab : 0 3 * * * php /path/to/Tyrolium-SSO/cron/cleanup.php

require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getPdo();

// UUID anonymes (sans token) non vus depuis 90 jours
$stmt = $pdo->prepare(
    "DELETE FROM sso_sync WHERE token IS NULL AND last_seen < NOW() - INTERVAL 90 DAY"
);
$stmt->execute();
$anonymousDeleted = $stmt->rowCount();

// UUID connectés non vus depuis 365 jours
$stmt = $pdo->prepare(
    "DELETE FROM sso_sync WHERE token IS NOT NULL AND last_seen < NOW() - INTERVAL 365 DAY"
);
$stmt->execute();
$authenticatedDeleted = $stmt->rowCount();

echo '[' . date('Y-m-d H:i:s') . '] Cleanup : '
    . $anonymousDeleted . ' anonymes supprimés, '
    . $authenticatedDeleted . ' connectés supprimés.' . PHP_EOL;
