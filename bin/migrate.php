<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
$db = new ServiFlow\Support\DB();
if ((int) $db->run("SELECT GET_LOCK(CONCAT(DATABASE(), ':migrations'), 10)")->fetchColumn() !== 1) {
    exit("Outra migração está em execução.\n");
}
$db->run('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(120) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') as $file) {
    $name = basename($file);
    if ($db->one('SELECT name FROM schema_migrations WHERE name = ?', [$name])) {
        continue;
    }
    foreach (explode(';', file_get_contents($file)) as $statement) {
        if (trim($statement) !== '') {
            $db->run($statement);
        }
    }
    $db->run('INSERT INTO schema_migrations (name) VALUES (?)', [$name]);
    echo "Aplicada: $name\n";
}
