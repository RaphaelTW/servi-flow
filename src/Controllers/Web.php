<?php
declare(strict_types=1);
namespace ServiFlow\Controllers;

use ServiFlow\Middleware\Security;
use ServiFlow\Repositories\TenantRecords;
use ServiFlow\Services\{Accounts,Plans,Operations};
use ServiFlow\Support\{DB,HttpError,Input};

final class Web
{
    public ?array $user = null;
    public array $nav = [];
    public string $company = '';
    public bool $companyLogo = false;
    public ?TenantRecords $records = null;
    public ?int $technician = null;

    public function __construct(public readonly DB $db, public readonly string $base, public readonly string $path)
    {
        $this->user = Security::identity($db);
    }

    public function context(bool $admin = false): TenantRecords
    {
        if (!$this->user) {
            $this->redirect('/entrar');
        }
        $membership = Security::membership($this->db,$this->user);
        $this->company = $membership['name'];
        $this->companyLogo = (bool) $membership['logo'];
        $this->technician = $membership['role'] === 'technician' ? (int) $this->user['id'] : null;
        $this->nav = ['/painel'=>['▦','Visão geral'],'/ordens'=>['⌁','Ordens de serviço']];
        if ($this->technician === null) {
            $this->nav = [
                '/painel'=>['▦','Visão geral'],'/clientes'=>['○','Clientes'],'/equipamentos'=>['▣','Equipamentos'],
                '/servicos'=>['≡','Catálogo'],'/orcamentos'=>['▤','Orçamentos'],'/ordens'=>['⌁','Ordens de serviço'],
                '/financeiro'=>['$','Financeiro'],'/relatorios'=>['▥','Relatórios'],'/empresa'=>['⚙','Empresa'],
            ];
        } elseif ($admin) {
            throw new HttpError(403,'Seu perfil não permite esta ação.');
        }
        return $this->records = new TenantRecords($this->db,(int) $membership['tenant_id']);
    }

    public function actor(): int { return (int) $this->user['id']; }

    public function platform(): void
    {
        if (!$this->user) {
            $this->redirect('/entrar');
        }
        if (!$this->user['platform_admin']) {
            throw new HttpError(403,'Somente a administração da plataforma tem acesso.');
        }
        $this->company = 'Administração da plataforma';
        $this->nav = ['/plataforma'=>['▦','Indicadores'],'/plataforma/planos'=>['▤','Planos'],'/plataforma/privacidade'=>['⚙','Privacidade']];
    }

    public function render(string $title, callable $body, bool $public = false): void
    {
        $content = capture($body);
        $user = $this->user;
        $nav = $this->nav;
        $company = $this->company;
        $companyLogo = $this->companyLogo;
        $path = $this->path;
        $canonical = $this->base . $path;
        $flash = $_SESSION['flash'] ?? '';
        unset($_SESSION['flash']);
        require dirname(__DIR__) . '/Views/layout.php';
    }

    public function page(string $title, callable $body, string $description = '', string $action = '', string $url = ''): void
    {
        $this->render($title,static function () use ($title,$body,$description,$action,$url): void { ?>
            <div class="page-title"><div><h1><?=e($title)?></h1><?php if ($description): ?><p><?=e($description)?></p><?php endif; ?></div>
            <?php if ($url): ?><a class="btn btn-primary" href="<?=e($url)?>"><?=e($action)?></a><?php endif; ?></div>
            <?php $body();
        });
    }

    public function redirect(string $url, string $message = ''): never
    {
        if ($message !== '') {
            $_SESSION['flash'] = $message;
        }
        header('Location: '.$url,true,303);
        exit;
    }

    public function get(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'; }
    public function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? 'unknown'; }

    public function options(TenantRecords $r, string $table, string $key, string $placeholder): array
    {
        $options = [''=>$placeholder];
        foreach ($r->options($table,$key) as $row) {
            $options[$row['id']] = $row[$key];
            if ($table === 'customer_assets') {
                $options[$row['id']] = trim($row['kind'].' '.$row['brand'].' '.$row['model']) . ' · equipamento #'.$row['id'];
            }
        }
        return $options;
    }
}
