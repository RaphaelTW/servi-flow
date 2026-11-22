<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

$required = ['pdo_mysql','curl','mbstring','dom','fileinfo','openssl','gd'];
$missing = array_filter($required,static fn($ext)=>!extension_loaded($ext));
echo 'PHP '.PHP_VERSION.PHP_EOL;
echo $missing ? 'Extensões ausentes: '.implode(', ',$missing).PHP_EOL : "Extensões obrigatórias disponíveis.\n";
try {
    $db = new ServiFlow\Support\DB();
    echo 'Banco: '.$db->run('SELECT VERSION()')->fetchColumn().PHP_EOL;
    echo 'Migrações: '.implode(', ',array_column($db->all('SELECT name FROM schema_migrations ORDER BY name'),'name')).PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR,"Banco inacessível ou migrações pendentes.\n");
    exit(1);
}
if (!is_writable(dirname(__DIR__).'/storage')) {
    fwrite(STDERR,"O diretório storage não permite gravação.\n");
    exit(1);
}
exit($missing ? 1 : 0);
