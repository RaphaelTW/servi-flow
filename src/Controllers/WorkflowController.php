<?php
declare(strict_types=1);
namespace ServiFlow\Controllers;

use ServiFlow\Repositories\TenantRecords;
use ServiFlow\Services\{Accounts,Documents,Operations,Photos,Plans,Portal};
use ServiFlow\Support\{HttpError,Input};

final class WorkflowController
{
    public function __construct(private readonly Web $web) {}

    private function items(TenantRecords $r, ?int $id): array
    {
        return $id ? $r->db->all('SELECT * FROM quotation_items WHERE tenant_id=? AND quotation_id=? ORDER BY id',[$r->tenant,$id]) : [];
    }

    public function quoteVersion(int $id, int $version): void
    {
        $r = $this->web->context(true);
        $r->get('quotations',$id);
        $entry = $r->db->one('SELECT snapshot FROM quotation_decision_documents WHERE tenant_id=? AND quotation_id=? AND version=?',
            [$r->tenant,$id,$version]) ?? throw new HttpError(404,'Cópia da proposta indisponível.');
        $snapshot = json_decode($entry['snapshot'],true,32,JSON_THROW_ON_ERROR);
        Documents::pdf('orcamento-'.$id.'-versao-'.$version,capture(static fn()=>quoteDocument($snapshot['quotation'],$snapshot['items'])));
    }

    private function share(TenantRecords $r, int $id, bool $order, bool $revoke): void
    {
        $web = $this->web;
        $ops = new Operations($r,$web->actor());
        $token = $ops->share($id,$order,$revoke);
        $back = ($order ? '/ordens/' : '/orcamentos/').$id;
        if (!$token) {
            $web->redirect($back,'Link revogado.');
        }
        $url = $web->base.'/acompanhar/'.$token;
        $web->page('Link de compartilhamento',static function () use ($url,$back): void { ?>
            <div class="panel"><label class="field"><span>Link temporário</span><input id="shared-url" class="form-control" value="<?=e($url)?>" readonly></label>
            <div class="actions"><button type="button" class="btn btn-outline-secondary" data-copy="shared-url">Copiar link</button>
            <a class="btn btn-primary" href="<?=e('https://wa.me/?text='.rawurlencode('Olá! Acompanhe seu atendimento pelo ServiFlow: '.$url))?>" target="_blank" rel="noopener noreferrer">Compartilhar no WhatsApp</a>
            <a class="btn btn-outline-secondary" href="<?=e($back)?>">Voltar ao atendimento</a></div>
            <p class="mt-3 mb-0">Guarde o link para compartilhar. Gerar um novo link cancela o anterior.</p></div>
        <?php });
    }

    public function quote(?int $id, ?string $action): void
    {
        $web = $this->web;
        $r = $web->context(true);
        $ops = new Operations($r,$web->actor());
        if (!$id && !$action) {
            $quotes = $r->db->all('SELECT id,customer_name,status,valid_until,total FROM quotations WHERE tenant_id=? ORDER BY id DESC LIMIT 100',[$r->tenant]);
            $web->page('Orçamentos',static function () use ($quotes): void { ?><div class="panel"><?php
                table(['Orçamento','Cliente','Status','Validade','Valor'],array_map(static fn($q)=>[
                    ['/orcamentos/'.$q['id'],'#'.$q['id']],$q['customer_name'],Operations::QUOTE_STATUS[$q['status']],brDate($q['valid_until']),money($q['total']),
                ],$quotes),'Crie seu primeiro orçamento para obter a aprovação do cliente por link.'); ?></div><?php
            },'Gerencie propostas e acompanhe as respostas.','Novo orçamento','/orcamentos/novo');
            return;
        }
        $quote = $id ? $r->get('quotations',$id) : null;
        $items = $this->items($r,$id);
        if (!$web->get()) {
            if (in_array($action,['compartilhar','revogar'],true)) {
                $this->share($r,$id,false,$action==='revogar');
                return;
            }
            if ($action === 'converter') {
                $order = $ops->order([],$id);
                $web->redirect('/ordens/'.$order,'Ordem de serviço vinculada ao orçamento.');
            }
            $saved = $ops->quote($_POST,$id);
            $web->redirect('/orcamentos/'.$saved,'Orçamento salvo.');
        }
        if ($action === 'pdf') {
            Documents::pdf('orcamento-'.$id,capture(static fn()=>quoteDocument($quote,$items)));
            return;
        }
        if ($quote && $action !== 'editar') {
            $responses = $r->db->all('SELECT a.*,d.version snapshot_version FROM quotation_acceptances a
                LEFT JOIN quotation_decision_documents d ON d.tenant_id=a.tenant_id AND d.quotation_id=a.quotation_id AND d.version=a.version
                WHERE a.tenant_id=? AND a.quotation_id=? ORDER BY a.version DESC',[$r->tenant,$id]);
            $web->page('Detalhes do orçamento',static function () use ($quote,$items,$id,$responses): void { ?>
                <div class="panel"><?php quoteDocument($quote,$items); ?></div>
                <div class="panel actions">
                    <a class="btn btn-outline-secondary" href="/orcamentos/<?=e($id)?>/pdf">Baixar PDF</a>
                    <?php if ($quote['status'] !== 'approved'): ?><a class="btn btn-outline-secondary" href="/orcamentos/<?=e($id)?>/editar">Editar proposta</a><?php endif; ?>
                    <?php if (in_array($quote['status'],['draft','pending','approved'],true) && $quote['valid_until'] >= date('Y-m-d')) {
                        form('/orcamentos/'.$id.'/compartilhar'); button('Gerar link'); ?></form><?php }
                    if ($quote['status'] !== 'draft') { form('/orcamentos/'.$id.'/revogar'); button('Revogar link','outline-secondary'); ?></form><?php }
                    if ($quote['status'] === 'approved') { form('/orcamentos/'.$id.'/converter'); button('Abrir ordem de serviço'); ?></form><?php } ?>
                </div>
                <?php if ($responses): ?><div class="panel"><?php section('Respostas do cliente');
                    table(['Versão','Decisão','Nome informado','Data','Observação'],array_map(static fn($row)=>[
                        $row['snapshot_version'] ? ['/orcamentos/'.$id.'/versoes/'.$row['version'].'/pdf','Versão '.$row['version']] : $row['version'],
                        Operations::QUOTE_STATUS[$row['decision']],$row['signer'],brDate($row['created_at']),$row['note'],
                    ],$responses)); ?></div><?php endif;
            });
            return;
        }
        if ($quote && $quote['status'] === 'approved') {
            throw new HttpError(409,'Esta versão já foi aprovada.');
        }
        $customers = $web->options($r,'customers','name','Selecione um cliente');
        $assets = $web->options($r,'customer_assets','kind','Sem equipamento');
        $services = $web->options($r,'service_catalog','name','Item avulso');
        $target = $id ? '/orcamentos/'.$id.'/editar' : '/orcamentos/novo';
        $web->page($id ? 'Editar orçamento' : 'Novo orçamento',static function () use ($quote,$items,$customers,$assets,$services,$target): void { ?>
            <div class="panel"><?php if (count($customers)===1): ?><p class="notice">Para continuar, <a href="/clientes/novo">cadastre um cliente</a>.</p><?php endif;
            form($target); ?><div class="field-grid"><?php
                field('customer_id','Cliente',$quote['customer_id'] ?? '',$customers,true);
                field('asset_id','Equipamento',$quote['asset_id'] ?? '',$assets);
                field('valid_until','Válido até',$quote['valid_until'] ?? date('Y-m-d',strtotime('+7 days')),'date',true);
                field('discount','Desconto em BRL',$quote['discount'] ?? '0','text',true);
            ?></div><?php field('terms','Condições comerciais',$quote['terms'] ?? '','textarea');
                section('Itens');
                $line = static function (int|string $index,array $item) use ($services): void { ?>
                    <div class="quote-line"><?php
                    field("items[$index][service]",'Catálogo',$item['service_id'] ?? '',$services);
                    field("items[$index][description]",'Descrição',$item['description'] ?? '');
                    field("items[$index][quantity]",'Qtd.',$item['quantity'] ?? '1','number');
                    field("items[$index][price]",'Valor unitário',$item['unit_price'] ?? '');
                    ?><button type="button" class="btn btn-outline-secondary" data-remove-item aria-label="Remover item">×</button></div>
                <?php };
                $items = $items ?: [[]]; ?>
                <p class="small">Para usar o preço sugerido, selecione um serviço do catálogo e deixe o valor unitário em branco.</p>
                <div data-items data-next="<?=count($items)?>"><?php foreach ($items as $i=>$item) { $line($i,$item); } ?></div>
                <template id="item-template"><?php $line('__item__',[]); ?></template>
                <div class="actions"><button type="button" class="btn btn-outline-secondary" data-add-item>Adicionar item</button><?php button('Salvar orçamento'); ?></div>
            </form></div>
        <?php });
    }

    public function order(?int $id, ?string $action, ?int $attachment = null): void
    {
        $web = $this->web;
        $r = $web->context(in_array($action,['novo','compartilhar','revogar','cobrar'],true));
        $tech = $web->technician;
        $ops = new Operations($r,$web->actor());
        if (!$id && !$action) {
            $orders = $r->db->all('SELECT o.*,c.name customer_name FROM service_orders o JOIN customers c ON c.tenant_id=o.tenant_id AND c.id=o.customer_id
                WHERE o.tenant_id=?'.($tech ? ' AND o.technician_id=?':'').' ORDER BY o.number DESC LIMIT 100',$tech ? [$r->tenant,$tech] : [$r->tenant]);
            $web->page('Ordens de serviço',static function () use ($orders): void { ?><div class="panel"><?php
                table(['Ordem','Cliente','Status','Agendamento','Valor'],array_map(static fn($o)=>[
                    ['/ordens/'.$o['id'],'OS '.$o['number']],$o['customer_name'],Operations::ORDER_STATUS[$o['status']],brDate($o['scheduled_at']),money($o['total']),
                ],$orders),'Ainda não há ordens de serviço disponíveis.'); ?></div><?php
            },'Acompanhe a agenda e a execução.', 'Nova ordem',$tech ? '' : '/ordens/nova');
            return;
        }
        if (!$id) {
            if (!$web->get()) {
                $web->redirect('/ordens/'.$ops->order($_POST),'Ordem aberta.');
            }
            $customers = $web->options($r,'customers','name','Selecione');
            $assets = $web->options($r,'customer_assets','kind','Sem equipamento');
            $web->page('Abrir ordem de serviço',static function () use ($customers,$assets): void { ?>
                <div class="panel"><?php form('/ordens/nova'); ?><div class="field-grid"><?php
                field('customer_id','Cliente','',$customers,true);
                field('asset_id','Equipamento','',$assets);
                field('total','Valor em BRL','0','text',true);
                ?></div><?php field('problem','Problema informado','','textarea'); button('Abrir OS'); ?></form></div>
            <?php });
            return;
        }
        $order = $r->get('service_orders',$id,false,$tech);
        if (!$web->get()) {
            switch ($action) {
                case 'compartilhar':
                case 'revogar':
                    $this->share($r,$id,true,$action==='revogar');
                    return;
                case 'cobrar':
                    $web->redirect('/financeiro/'.$ops->receive($id,$_POST),'Cobrança registrada.');
                case 'foto':
                    (new Photos($r))->add($id,$tech,$web->actor(),$_FILES['photo'] ?? [],$_POST['stage'] ?? '');
                    $web->redirect('/ordens/'.$id,'Foto adicionada.');
            }
            $ops->updateOrder($id,$_POST,$tech);
            $web->redirect('/ordens/'.$id,'Atendimento atualizado.');
        }
        if ($action === 'foto') {
            $file = $r->db->one('SELECT * FROM service_order_attachments WHERE tenant_id=? AND order_id=? AND id=?',[$r->tenant,$id,$attachment]);
            if (!$file || !is_file($path = Photos::path($file['file_key']))) {
                throw new HttpError(404,'Foto indisponível.');
            }
            header('Content-Type: '.$file['mime']);
            header('Content-Disposition: inline; filename="foto.'.($file['mime']==='image/png' ? 'png':'jpg').'"');
            header('Content-Length: '.filesize($path));
            readfile($path);
            return;
        }
        $items = $this->items($r,$order['quotation_id'] ? (int)$order['quotation_id'] : null);
        $customer = $r->get('customers',(int)$order['customer_id']);
        if ($action === 'pdf') {
            Documents::pdf('ordem-'.$order['number'],capture(fn()=>orderDocument($order,$web->company,$customer['name'],$items,true)));
            return;
        }
        $data = [
            'order'=>$order,'customer'=>$customer,'items'=>$items,'id'=>$id,'tech'=>$tech,'company'=>$web->company,
            'checks'=>$r->db->all('SELECT * FROM service_order_checklists WHERE tenant_id=? AND order_id=? ORDER BY id',[$r->tenant,$id]),
            'photos'=>$r->db->all('SELECT id,stage FROM service_order_attachments WHERE tenant_id=? AND order_id=? ORDER BY id',[$r->tenant,$id]),
            'history'=>$r->db->all('SELECT e.*,u.name FROM order_events e JOIN users u ON u.id=e.actor_id WHERE e.tenant_id=? AND e.order_id=? ORDER BY e.id DESC LIMIT 30',[$r->tenant,$id]),
            'premium'=>(new Plans($r))->effective()['premium'],
            'review'=>$r->db->one('SELECT * FROM reviews WHERE tenant_id=? AND order_id=?',[$r->tenant,$id]),
            'technicians'=>[''=>'Sem técnico'],
            'asset'=>$order['asset_id'] ? $r->get('customer_assets',(int)$order['asset_id']) : null,
        ];
        if (!$tech) {
            foreach ($r->db->all("SELECT u.id,u.name,m.active FROM tenant_memberships m JOIN users u ON u.id=m.user_id
                WHERE m.tenant_id=? AND m.role='technician' AND (m.active=1 OR u.id=?) ORDER BY u.name",[$r->tenant,$order['technician_id']]) as $row) {
                $data['technicians'][$row['id']] = $row['name'].($row['active'] ? '' : ' (inativo)');
            }
        }
        $web->page('Ordem de serviço '.$order['number'],static function () use ($data): void {
            extract($data,EXTR_SKIP);
            require dirname(__DIR__).'/Views/order.php';
        });
    }

    public function portal(string $token, ?string $action): void
    {
        $web = $this->web;
        $portal = new Portal($web->db);
        $link = $portal->link($token);
        $r = new TenantRecords($web->db,(int)$link['tenant_id']);
        $url = '/acompanhar/'.$token;
        if (!$web->get()) {
            (new Accounts($r->db))->limit('portal',$web->ip(),30);
            if ($link['quotation_id']) {
                $portal->decide($token,$_POST);
                $web->redirect($url,'Sua resposta foi registrada.');
            }
            $portal->review($token,$_POST);
            $web->redirect($url,'Avaliação registrada.');
        }
        if ($link['quotation_id']) {
            [$record,$items] = $r->db->transaction(function () use ($r,$link,$portal,$token): array {
                $r->lockCompany();
                $record = $r->get('quotations',(int)$link['quotation_id'],true);
                $current = $portal->link($token,true);
                if ($record['version'] !== $current['version'] || $record['status']==='draft') {
                    throw new HttpError(404,'Link indisponível.');
                }
                return [$record,$this->items($r,(int)$record['id'])];
            });
            if ($action === 'pdf') {
                Documents::pdf('orcamento-'.$record['id'],capture(static fn()=>quoteDocument($record,$items)));
                return;
            }
            $web->render('Seu orçamento',static function () use ($record,$items,$url): void { ?>
                <div class="document"><div class="panel"><?php quoteDocument($record,$items); ?><a class="btn btn-outline-secondary" href="<?=e($url.'/pdf')?>">Baixar orçamento em PDF</a></div>
                <?php if ($record['status']==='pending'): ?><div class="panel"><?php section('Responder ao orçamento');
                form($url); field('signer','Seu nome','','text',true);
                field('decision','Decisão','',[''=>'Selecione','approved'=>'Aprovar','rejected'=>'Recusar','changes'=>'Solicitar alterações'],true);
                field('note','Comentário ou alterações desejadas','','textarea');
                ?><p>Seu nome, a decisão, a data e a versão da proposta serão registrados como aceite eletrônico simples.</p><?php button('Enviar resposta'); ?></form></div><?php endif; ?></div>
            <?php });
            return;
        }
        $order = $r->get('service_orders',(int)$link['order_id']);
        $client = $r->get('customers',(int)$order['customer_id'])['name'];
        $company = $r->db->one('SELECT name FROM tenants WHERE id=?',[$r->tenant])['name'];
        $items = $this->items($r,$order['quotation_id'] ? (int)$order['quotation_id'] : null);
        $review = $r->db->one('SELECT rating FROM reviews WHERE tenant_id=? AND order_id=?',[$r->tenant,$order['id']]);
        if ($action === 'pdf') {
            if ($order['status'] !== 'done' || !$order['customer_report']) {
                throw new HttpError(404,'Relatório indisponível.');
            }
            Documents::pdf('relatorio-'.$order['number'],capture(static fn()=>orderDocument($order,$company,$client,$items,false)));
            return;
        }
        $web->render('Acompanhe o serviço',static function () use ($order,$client,$company,$items,$review,$url): void { ?>
            <div class="document"><div class="panel"><?php orderDocument($order,$company,$client,$items,false);
                if ($order['status']==='done' && $order['customer_report']): ?><a class="btn btn-outline-secondary" href="<?=e($url.'/pdf')?>">Baixar relatório</a><?php endif; ?>
            </div><?php if ($order['status']==='done' && !$review): ?><div class="panel"><?php section('Avalie o atendimento');
                form($url); field('rating','Nota','',[''=>'Selecione','5'=>'5 · Excelente','4'=>'4 · Bom','3'=>'3 · Regular','2'=>'2 · Ruim','1'=>'1 · Muito ruim'],true);
                field('note','Comentário','','textarea'); button('Enviar avaliação'); ?></form></div><?php endif; ?></div>
        <?php });
    }
}
