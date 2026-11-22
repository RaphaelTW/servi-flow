<?php
declare(strict_types=1);

use ServiFlow\Controllers\{AccountController,CatalogController,CompanyController,OfficeController,PlatformController,Web,WorkflowController};
use ServiFlow\Middleware\Security;
use ServiFlow\Support\{DB,HttpError,Input};

ini_set('display_errors','0');
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/src/Views/functions.php';
require dirname(__DIR__).'/src/Views/documents.php';

$base = rtrim($_ENV['APP_URL'] ?? 'http://127.0.0.1:8080','/');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isPublic = in_array($path,['/','/privacidade','/termos','/robots.txt','/sitemap.xml'],true);
try {
    if (!is_string($path) || !preg_match('~^https?://[a-zA-Z0-9.-]+(?::[0-9]+)?$~D',$base)) {
        throw new HttpError(400,'Endereço inválido.');
    }
    Security::start($base,!$isPublic);
    if (!in_array($method,['GET','POST'],true)) {
        throw new HttpError(405,'Método indisponível.');
    }
    if ($method === 'GET' && $path === '/robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nDisallow: /acompanhar/\nDisallow: /recuperar/\nSitemap: $base/sitemap.xml\n";
        return;
    }
    if ($method === 'GET' && $path === '/sitemap.xml') {
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (['/','/termos','/privacidade'] as $publicPath) {
            echo '<url><loc>'.e($base.$publicPath).'</loc></url>';
        }
        echo '</urlset>';
        return;
    }
    $web = new Web(new DB(),$base,$path);
    $accounts = new AccountController($web);
    $get = $method === 'GET';
    if ($path === '/' && $get) { $accounts->landing(); return; }
    if ($path === '/termos' && $get) { $accounts->legal('terms'); return; }
    if ($path === '/privacidade' && $get) { $accounts->legal('privacy'); return; }
    if ($path === '/entrar' || $path === '/cadastro') { $accounts->access($path === '/cadastro'); return; }
    if ($path === '/sair' && !$get) { $accounts->logout(); return; }
    if (preg_match('~^/recuperar(?:/([a-f0-9]{64}))?$~D',$path,$m)) { $accounts->recover($m[1] ?? null); return; }
    if (preg_match('~^/acompanhar/([a-f0-9]{64})(/pdf)?$~D',$path,$m) && (empty($m[2]) || $get)) {
        (new WorkflowController($web))->portal($m[1],empty($m[2]) ? null : 'pdf'); return;
    }
    if ($path === '/painel' && $get) { (new OfficeController($web))->dashboard(); return; }
    if (preg_match('~^/cep/(\d{5}-?\d{3})$~D',$path,$m) && $get) { (new CatalogController($web))->postal($m[1]); return; }
    if (preg_match('~^/(clientes|equipamentos|servicos)(?:/(novo|[1-9]\d{0,18})(/arquivo)?)?$~D',$path,$m)
        && ($get ? empty($m[3]) : !empty($m[2])) && !(($m[2] ?? '')==='novo' && !empty($m[3]))) {
        $id = !empty($m[2]) && $m[2]!=='novo' ? Input::int($m[2]) : null;
        (new CatalogController($web))->show($m[1],$id, isset($m[3]) ? 'arquivo' : ($m[2] ?? null)); return;
    }
    if ($get && preg_match('~^/orcamentos/([1-9]\d{0,18})/versoes/([1-9]\d{0,9})/pdf$~D',$path,$m)) {
        (new WorkflowController($web))->quoteVersion(Input::int($m[1]),Input::int($m[2])); return;
    }
    if (preg_match('~^/orcamentos(?:/(novo|[1-9]\d{0,18})(?:/(editar|pdf|compartilhar|revogar|converter))?)?$~D',$path,$m)) {
        $part = $m[1] ?? '';
        $id = $part !== '' && $part!=='novo' ? Input::int($part) : null;
        $action = $m[2] ?? ($part==='novo' ? 'novo' : null);
        if (($id || !isset($m[2])) && ($get ? in_array($action,[null,'novo','editar','pdf'],true) : in_array($action,['novo','editar','compartilhar','revogar','converter'],true))) {
            (new WorkflowController($web))->quote($id,$action); return;
        }
    }
    if (preg_match('~^/ordens(?:/(nova|[1-9]\d{0,18})(?:/(pdf|compartilhar|revogar|cobrar|foto)(?:/([1-9]\d{0,18}))?)?)?$~D',$path,$m)) {
        $part = $m[1] ?? '';
        $id = $part !== '' && $part!=='nova' ? Input::int($part) : null;
        $action = $m[2] ?? ($part==='nova' ? 'novo' : null);
        $attachment = isset($m[3]) ? Input::int($m[3]) : null;
        if (($id || !isset($m[2])) && (!$attachment || ($get && $action==='foto')) &&
            ($get ? (in_array($action,[null,'novo','pdf'],true) || ($action==='foto' && $attachment)) :
            ($part !== '' && $action!=='pdf'))) {
            (new WorkflowController($web))->order($id,$action,$attachment); return;
        }
    }
    if (preg_match('~^/financeiro(?:/([1-9]\d{0,18}))?$~D',$path,$m) && ($get || isset($m[1]))) {
        (new OfficeController($web))->finance(isset($m[1]) ? Input::int($m[1]) : null); return;
    }
    if ($path === '/relatorios' && $get) { (new OfficeController($web))->reports(); return; }
    if (preg_match('~^/empresa(?:/(equipe|privacidade|exportar|logo))?$~D',$path,$m)) {
        (new CompanyController($web))->show($m[1] ?? 'perfil'); return;
    }
    if (preg_match('~^/plataforma(?:/(planos|privacidade|empresa)(?:/([1-9]\d{0,18}))?)?$~D',$path,$m)) {
        (new PlatformController($web))->show($m[1] ?? 'indicadores',isset($m[2]) ? Input::int($m[2]) : null); return;
    }
    throw new HttpError(404,'Página não encontrada.');
} catch (Throwable $error) {
    $status = $error instanceof HttpError ? $error->status : 500;
    $message = $error instanceof HttpError ? $error->getMessage() : 'Não foi possível concluir a solicitação.';
    if ($status >= 500) {
        error_log(json_encode(['event'=>'request_failed','status'=>$status,'type'=>$error::class,'point'=>basename($error->getFile()).':'.$error->getLine()]));
    }
    http_response_code($status);
    header('Cache-Control: no-store');
    if (isset($web)) {
        $web->render('Atenção',static function () use ($message,$web): void { ?>
            <div class="document"><div class="panel"><h1>Atenção</h1><p role="alert"><?=e($message)?></p>
            <a href="<?=$web->nav ? e(array_key_first($web->nav)) : '/'?>">Voltar ao início</a></div></div>
        <?php });
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
}
