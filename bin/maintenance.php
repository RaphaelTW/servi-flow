<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$db = new ServiFlow\Support\DB();
foreach ([
    'login_limits'=>'expires_at<=NOW()',
    'password_resets'=>'expires_at<=NOW()',
    'external_api_cache'=>'expires_at<=NOW()',
    'shared_links'=>'expires_at<DATE_SUB(NOW(),INTERVAL 30 DAY) OR revoked_at<DATE_SUB(NOW(),INTERVAL 30 DAY)',
] as $table=>$condition) {
    $deleted = 0;
    do {
        $batch = $db->run("DELETE FROM $table WHERE $condition LIMIT 500")->rowCount();
        $deleted += $batch;
    } while ($batch===500);
    echo "$table: $deleted registros temporários removidos.\n";
}
