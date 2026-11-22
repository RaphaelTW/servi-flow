<?php
declare(strict_types=1);
namespace ServiFlow\Controllers;

use ServiFlow\Repositories\TenantRecords;
use ServiFlow\Services\Subscriptions;
use ServiFlow\Support\{HttpError,Input};

final class PlatformController
{
    public function __construct(private readonly Web $web) {}

    public function show(string $action, ?int $id): void
    {
        $w = $this->web;
        $w->platform();
        match ($action) {
            'planos' => $this->plans($id),
            'empresa' => $id ? $this->company($id) : throw new HttpError(404,'Empresa não encontrada.'),
            'privacidade' => $this->privacy($id),
            default => $w->get() ? $this->indicators() : throw new HttpError(405,'Método indisponível.'),
        };
    }

    private function indicators(): void
    {
        $w = $this->web;
        $db = $w->db;
        $qualified = "s.status='active' AND p.price>0 AND s.ends_on IS NOT NULL AND DATE_ADD(s.ends_on,INTERVAL p.grace_days DAY)>=CURRENT_DATE";
        $measures = $db->one("SELECT COUNT(*) total,SUM(t.blocked=0) enabled,SUM(t.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) recent,
            SUM(($qualified) AND t.blocked=0) paid,SUM(s.status='cancelled') cancelled,
            SUM(s.status='active' AND p.price>0 AND s.ends_on<CURRENT_DATE) overdue,
            COALESCE(SUM(IF(($qualified) AND t.blocked=0,p.price,0)),0.00) mrr
            FROM tenants t JOIN subscriptions s ON s.tenant_id=t.id JOIN plans p ON p.id=s.plan_id");
        $events = array_column($db->all("SELECT event,COUNT(DISTINCT tenant_id) total FROM audit_logs WHERE event IN
            ('customers_created','quotation_shared','quotation_approved','order_completed','paid_conversion') GROUP BY event"),'total','event');
        $cash = $db->run('SELECT COALESCE(SUM(amount_received),0.00) FROM subscription_events WHERE created_at>=?', [date('Y-m-01')])->fetchColumn();
        $billed = $db->run('SELECT COALESCE(SUM(amount_billed),0.00) FROM subscription_events WHERE created_at>=?', [date('Y-m-01')])->fetchColumn();
        $plans = $db->all("SELECT p.name,COUNT(*) accounts,SUM(p.price) mrr FROM tenants t JOIN subscriptions s ON s.tenant_id=t.id
            JOIN plans p ON p.id=s.plan_id WHERE ($qualified) AND t.blocked=0 GROUP BY p.id,p.name ORDER BY p.name");
        $q = Input::text($_GET['q'] ?? '',100);
        $page = Input::int($_GET['pagina'] ?? '1',1,100000);
        $offset = ($page-1)*50;
        $companies = $db->all("SELECT t.id,t.name,t.blocked,s.ends_on,s.status,p.name plan FROM tenants t
            JOIN subscriptions s ON s.tenant_id=t.id JOIN plans p ON p.id=s.plan_id WHERE t.name LIKE ? ORDER BY t.id DESC LIMIT 51 OFFSET $offset",['%'.$q.'%']);
        $upcoming = $db->all("SELECT t.id,t.name,s.ends_on FROM tenants t JOIN subscriptions s ON s.tenant_id=t.id JOIN plans p ON p.id=s.plan_id
            WHERE s.status='active' AND p.price>0 AND s.ends_on BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE,INTERVAL 30 DAY)
            ORDER BY s.ends_on LIMIT 30");
        $activated = $events['quotation_shared'] ?? 0;
        $metrics = ['Empresas'=>$measures['total'],'Empresas desbloqueadas'=>$measures['enabled'] ?? 0,'Novas contas em 30 dias'=>$measures['recent'] ?? 0,
            'Assinaturas pagas vigentes'=>$measures['paid'] ?? 0,'Empresas convertidas ao pago'=>$events['paid_conversion'] ?? 0,
            'MRR estimado'=>money($measures['mrr']),'Faturado no mês'=>money($billed),'Recebido no mês'=>money($cash),'Assinaturas canceladas'=>$measures['cancelled'] ?? 0,
            'Assinaturas vencidas'=>$measures['overdue'] ?? 0,'Ativação por envio de orçamento'=>($measures['total'] ? number_format(100*$activated/$measures['total'],1,',','.') : '0').'%'];
        $funnel = [['Empresa cadastrada',$measures['total']],['Primeiro cliente',$events['customers_created'] ?? 0],
            ['Primeiro orçamento enviado',$activated],['Primeiro orçamento aprovado',$events['quotation_approved'] ?? 0],
            ['Primeira OS concluída',$events['order_completed'] ?? 0],['Conversão ao pago',$events['paid_conversion'] ?? 0]];
        $w->page('Indicadores da plataforma',static function () use ($metrics,$funnel,$plans,$companies,$q,$page,$upcoming): void { ?>
            <div class="metric-grid"><?php foreach ($metrics as $label=>$value): ?><div class="metric"><span><?=e($label)?></span><strong><?=e($value)?></strong></div><?php endforeach; ?></div>
            <div class="split"><div class="panel"><?php section('Etapas de ativação'); table(['Etapa','Empresas'],$funnel); ?></div>
            <div class="panel"><?php section('Receita recorrente por plano'); table(['Plano','Vigentes','MRR'],array_map(static fn($row)=>[$row['name'],$row['accounts'],money($row['mrr'])],$plans)); ?></div></div>
            <div class="panel"><?php section('Vencimentos nos próximos 30 dias'); table(['Empresa','Vencimento'],array_map(static fn($row)=>[['/plataforma/empresa/'.$row['id'],$row['name']],brDate($row['ends_on'])],$upcoming)); ?></div>
            <div class="panel"><?php section('Empresas'); ?><form action="/plataforma" method="get"><?php field('q','Buscar empresa',$q); button('Buscar','outline-secondary'); ?></form>
                <?php table(['Empresa','Plano contratado','Vencimento','Acesso'],array_map(static fn($row)=>[
                    ['/plataforma/empresa/'.$row['id'],$row['name']],$row['plan'],brDate($row['ends_on']),$row['blocked']?'Bloqueado':'Liberado',
                ],array_slice($companies,0,50)));
                $url = static fn($next)=>'/plataforma?'.http_build_query(['q'=>$q,'pagina'=>$next]); ?>
                <div class="actions"><?php if ($page>1): ?><a href="<?=e($url($page-1))?>">Anterior</a><?php endif; ?><?php if (count($companies)>50): ?><a href="<?=e($url($page+1))?>">Próxima</a><?php endif; ?></div>
            </div>
        <?php },'O MRR considera assinaturas vigentes e tolerância. O recebido no mês soma pagamentos confirmados manualmente.');
    }

    private function plans(?int $id): void
    {
        $w = $this->web;
        $plan = $id ? $w->db->one('SELECT * FROM plans WHERE id=?',[$id]) : null;
        if ($id && !$plan) {
            throw new HttpError(404,'Plano não encontrado.');
        }
        if (!$w->get()) {
            $code = $plan ? $plan['code'] : strtolower(Input::text($_POST['code'] ?? '',24,true));
            if (!preg_match('/^[a-z][a-z0-9_-]*$/D',$code)) {
                throw new HttpError(422,'Informe um código com letras minúsculas, dígitos ou hífen.');
            }
            $data = [
                Input::text($_POST['name'] ?? '',80,true),
                $code==='free' ? '0.00' : Input::decimal(Input::cents($_POST['price'] ?? '')),
                Input::int($_POST['member_limit'] ?? '',1,1000),
                Input::int($_POST['order_limit'] ?? '',1,100000),
                Input::int($_POST['storage_mb'] ?? '',0,100000),
                $code==='free' ? 0 : (int) Input::choice($_POST['premium'] ?? '',['0','1']),
                Input::int($_POST['grace_days'] ?? '',0,60),
            ];
            if ($code!=='free' && Input::cents($data[1])===0) {
                throw new HttpError(422,'Use um preço positivo para planos pagos.');
            }
            try {
                $w->db->transaction(function () use ($w,$data,$code,$id): void {
                    if ($id) {
                        $w->db->run('UPDATE plans SET name=?,price=?,member_limit=?,order_limit=?,storage_mb=?,premium=?,grace_days=? WHERE id=?',[...$data,$id]);
                    } else {
                        $w->db->run('INSERT INTO plans (name,price,member_limit,order_limit,storage_mb,premium,grace_days,code) VALUES (?,?,?,?,?,?,?,?)',[...$data,$code]);
                    }
                    self::audit($w,'plan_saved',$id ?? $w->db->id());
                });
            } catch (\PDOException $error) {
                if (($error->errorInfo[1] ?? null)===1062) {
                    throw new HttpError(409,'Já existe um plano com esse código.');
                }
                throw $error;
            }
            $w->redirect('/plataforma/planos','Plano salvo.');
        }
        $rows = $w->db->all('SELECT * FROM plans ORDER BY price,id');
        $w->page('Planos de assinatura',static function () use ($plan,$rows,$id): void { ?>
            <div class="split"><div class="panel"><?php section('Planos cadastrados'); table(['Plano','Mensalidade','Usuários','OS/mês'],array_map(static fn($row)=>[
                ['/plataforma/planos/'.$row['id'],$row['name']],money($row['price']),$row['member_limit'],$row['order_limit'],
            ],$rows)); ?></div><div class="panel"><?php section($id?'Editar plano':'Cadastrar plano');
            form('/plataforma/planos'.($id?'/'.$id:''));
            if (!$id) { field('code','Código do plano','','text',true); }
            field('name','Nome',$plan['name'] ?? '','text',true); field('price','Mensalidade em BRL',$plan['price'] ?? '','text',true);
            field('member_limit','Limite de usuários',$plan['member_limit'] ?? 1,'number',true);
            field('order_limit','Limite mensal de ordens',$plan['order_limit'] ?? 5,'number',true);
            field('storage_mb','Armazenamento em MB',$plan['storage_mb'] ?? 10,'number',true);
            field('grace_days','Dias de tolerância',$plan['grace_days'] ?? 5,'number',true);
            field('premium','Acesso premium',$plan['premium'] ?? '1',['1'=>'Sim','0'=>'Não']);
            button('Salvar plano'); ?></form></div></div>
        <?php });
    }

    private function company(int $id): void
    {
        $w = $this->web;
        $r = new TenantRecords($w->db,$id);
        $company = $w->db->one('SELECT id,name,blocked,created_at FROM tenants WHERE id=?',[$id]) ?? throw new HttpError(404,'Empresa não encontrada.');
        if (!$w->get()) {
            if (isset($_POST['blocked'])) {
                $w->db->transaction(function () use ($w,$r,$id): void {
                    $r->lockCompany();
                    $blocked = (int) Input::choice($_POST['blocked'],['0','1']);
                    $w->db->run('UPDATE tenants SET blocked=? WHERE id=?',[$blocked,$id]);
                    $r->audit($blocked?'company_blocked':'company_unblocked',$w->actor(),$id);
                });
            } else {
                (new Subscriptions($r))->change($w->actor(),$_POST);
            }
            $w->redirect('/plataforma/empresa/'.$id,'Empresa atualizada.');
        }
        $plans = array_column($w->db->all('SELECT id,name FROM plans ORDER BY price,id'),'name','id');
        $subscription = $w->db->one('SELECT * FROM subscriptions WHERE tenant_id=?',[$id]);
        $history = $w->db->all('SELECT * FROM subscription_events WHERE tenant_id=? ORDER BY id DESC LIMIT 50',[$id]);
        $audit = $w->db->all('SELECT event,resource_id,created_at FROM audit_logs WHERE tenant_id=? ORDER BY id DESC LIMIT 50',[$id]);
        $w->page($company['name'],static function () use ($company,$id,$plans,$subscription,$history,$audit): void { ?>
            <div class="split"><div class="panel"><?php section('Assinatura'); form('/plataforma/empresa/'.$id);
            ?><input type="hidden" name="request_key" value="<?=e(bin2hex(random_bytes(32)))?>"><?php
            field('plan_id','Plano',$subscription['plan_id'],$plans,true); field('status','Situação',$subscription['status'],['active'=>'Ativa','cancelled'=>'Cancelada']);
            field('ends_on','Vencimento do plano pago',$subscription['ends_on'],'date');
            field('amount_billed','Valor faturado neste lançamento em BRL','0','text',true);
            field('amount_received','Valor efetivamente recebido em BRL','0','text',true);
            ?><p>Cadastre um valor apenas após confirmar o crédito.</p><?php button('Registrar alteração'); ?></form></div>
            <div><div class="panel"><?php section('Acesso administrativo'); form('/plataforma/empresa/'.$id); ?>
                <input type="hidden" name="blocked" value="<?=$company['blocked']?'0':'1'?>"><?php button($company['blocked']?'Liberar empresa':'Bloquear empresa','outline-secondary'); ?></form>
            </div><div class="panel"><?php section('Histórico da assinatura'); table(['Data','Plano','Situação','Faturado','Recebido'],array_map(static fn($row)=>[
                brDate($row['created_at']),$plans[$row['new_plan_id']],$row['status']==='active'?'Ativa':'Cancelada',money($row['amount_billed']),money($row['amount_received']),
            ],$history)); ?></div></div></div>
            <div class="panel"><?php section('Auditoria'); table(['Data','Evento','Registro'],array_map(static fn($row)=>[brDate($row['created_at']),$row['event'],$row['resource_id']],$audit)); ?></div>
        <?php });
    }

    private function privacy(?int $id): void
    {
        $w = $this->web;
        if (!$w->get()) {
            if ($id) {
                $w->db->transaction(function () use ($w,$id): void {
                    if (!$w->db->one('SELECT id FROM privacy_requests WHERE id=? FOR UPDATE',[$id])) {
                        throw new HttpError(404,'Solicitação não encontrada.');
                    }
                    $w->db->run("UPDATE privacy_requests SET status='closed' WHERE id=?",[$id]);
                    self::audit($w,'privacy_request_closed',$id);
                });
            } else {
                $kind = Input::choice($_POST['kind'] ?? '',['terms','privacy']);
                $content = Input::text($_POST['content'] ?? '',20000,true);
                $w->db->transaction(function () use ($w,$kind,$content): void {
                    $current = $w->db->one('SELECT version FROM legal_documents WHERE kind=? ORDER BY version DESC LIMIT 1 FOR UPDATE',[$kind]);
                    $w->db->run('INSERT INTO legal_documents (kind,version,content) VALUES (?,?,?)',[$kind,(int)$current['version']+1,$content]);
                    self::audit($w,'legal_document_published',null);
                });
            }
            $w->redirect('/plataforma/privacidade','Alteração registrada.');
        }
        if ($id) {
            throw new HttpError(404,'Página não encontrada.');
        }
        $requests = $w->db->all("SELECT p.*,t.name FROM privacy_requests p JOIN tenants t ON t.id=p.tenant_id ORDER BY (p.status='open') DESC,p.id DESC LIMIT 100");
        $documents = (new \ServiFlow\Services\Accounts($w->db))->terms();
        $w->page('Privacidade e suporte',static function () use ($requests,$documents): void { ?>
            <div class="split"><div class="panel"><?php section('Solicitações'); ?>
                <?php if (!$requests): ?><p class="empty">Sem solicitações registradas.</p><?php endif; ?>
                <?php foreach ($requests as $row): ?><article class="border-bottom mb-3 pb-3">
                    <h3>#<?=e($row['id'])?> · <?=e($row['name'])?></h3><p><?=e(brDate($row['created_at']))?> · <?=$row['status']==='open'?'Aberta':'Encerrada'?></p>
                    <p class="preserve"><?=e($row['detail'])?></p>
                    <?php if ($row['status']==='open') { form('/plataforma/privacidade/'.$row['id']); button('Encerrar','outline-secondary'); ?></form><?php } ?>
                </article><?php endforeach; ?>
            </div><div class="panel"><?php section('Documentos públicos');
                table(['Documento','Versão'],array_map(static fn($row)=>[$row['kind']==='terms'?'Termos de uso':'Política de privacidade',$row['version']],$documents));
                form('/plataforma/privacidade'); field('kind','Documento','terms',['terms'=>'Termos de uso','privacy'=>'Privacidade']);
                field('content','Conteúdo integral da nova versão','','textarea',true); button('Publicar nova versão'); ?></form>
            </div></div>
        <?php });
    }

    private static function audit(Web $w,string $event,?int $resource): void
    {
        $w->db->run('INSERT INTO audit_logs (actor_id,event,resource_id) VALUES (?,?,?)',[$w->actor(),$event,$resource]);
    }
}
