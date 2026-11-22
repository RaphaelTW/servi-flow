<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
use ServiFlow\Support\{DB,Input};
use ServiFlow\Services\Accounts;

if ($argc !== 3) {
    fwrite(STDERR, "Uso: php bin/admin.php nome email. Forneça a senha via entrada padrão a partir de um prompt protegido.\n");
    exit(1);
}
try {
    $password = fgets(STDIN);
    $id = (new Accounts(new DB()))->createUser(Input::text($argv[1], 160, true), Input::email($argv[2]),
        Input::password(rtrim((string) $password, "\r\n")), true);
    echo "Administrador criado ($id).\n";
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof ServiFlow\Support\HttpError ? $error->getMessage() : 'Falha ao criar administrador.') . "\n");
    exit(1);
}
