<?php
declare(strict_types=1);
namespace ServiFlow\Controllers;

use ServiFlow\Services\{Documents,Operations,Plans,SpreadsheetExport};
use ServiFlow\Support\{HttpError,Input};

final class OfficeController
{
    public function __construct(private readonly Web $web) {}

    public function dashboard(): void
    {
        $w = $this->web;
        $r = $w->context();
        $scope = $w->technician ? [$r->tenant,$w->technician] : [$r->tenant];
        $filter = $w->technician ? ' AND technician_id=?' : '';
        $metrics = [];
        foreach (['open'=>'OS abertas','progress'=>'Em andamento','done'=>'Serviços concluídos','scheduled'=>'OS agendadas'] as $status=>$label) {
            $metrics[$label] = $r->db->run('SELECT COUNT(*) FROM service_orders WHERE status=? AND tenant_id=?'.$filter,[$status,...$scope])->fetchColumn();
        }
        $chart = [];
        $onboarding = false;
        if (!$w->technician) {
            $metrics['Clientes ativos'] = $r->db->run('SELECT COUNT(*) FROM customers WHERE tenant_id=? AND archived=0',[$r->tenant])->fetchColumn();
            foreach (['pending'=>'Orçamentos pendentes','approved'=>'Orçamentos aprovados'] as $status=>$label) {
                $metrics[$label] = $r->db->run('SELECT COUNT(*) FROM quotations WHERE tenant_id=? AND status=?',[$r->tenant,$status])->fetchColumn();
            }
            $onboarding = !$r->db->one("SELECT id FROM audit_logs WHERE tenant_id=? AND event='quotation_shared' LIMIT 1",[$r->tenant]);
            $paid = $r->db->run('SELECT COALESCE(SUM(amount),0.00) FROM payment_records WHERE tenant_id=?',[$r->tenant])->fetchColumn();
            $balance = $r->db->run('SELECT COALESCE((SELECT SUM(amount) FROM receivables WHERE tenant_id=?),0.00)-CAST(? AS DECIMAL(30,2))',[$r->tenant,$paid])->fetchColumn();
            $metrics['A receber'] = money((string)$balance);
            $metrics['Recebimentos registrados'] = money($paid);
            $monthly = array_column($r->db->all("SELECT DATE_FORMAT(paid_on,'%Y-%m') month,SUM(amount) total FROM payment_records
                WHERE tenant_id=? AND paid_on>=? GROUP BY month",[$r->tenant,date('Y-m-01',strtotime('-5 months',strtotime(date('Y-m-01'))))]),'total','month');
            for ($ago=5;$monthly && $ago>=0;$ago--) {
                $month = strtotime("-$ago months",strtotime(date('Y-m-01')));
                $chart[] = ['label'=>date('m/Y',$month),'value'=>$monthly[date('Y-m',$month)] ?? '0.00'];
            }
        }
        $agenda = $r->db->all("SELECT id,number,status,scheduled_at FROM service_orders WHERE tenant_id=? AND scheduled_at>=NOW()
            AND status NOT IN ('done','cancelled')".$filter.' ORDER BY scheduled_at LIMIT 10',$scope);
        $w->page('Visão geral',static function () use ($metrics,$agenda,$chart,$onboarding): void { ?>
            <?php if ($onboarding): ?><div class="panel"><?php section('Seu primeiro orçamento'); ?>
                <ol class="mb-0"><li><a href="/empresa">Confira os dados comerciais</a> que identificam sua empresa.</li>
                <li><a href="/clientes/novo">Cadastre um cliente</a> para vincular à proposta.</li>
                <li><a href="/orcamentos/novo">Crie o orçamento</a> e gere o primeiro link de aprovação.</li></ol>
            </div><?php endif; ?>
            <div class="metric-grid"><?php foreach ($metrics as $label=>$value): ?><div class="metric"><span><?=e($label)?></span><strong><?=e($value)?></strong></div><?php endforeach; ?></div>
            <div class="split"><div class="panel"><?php section('Próximos atendimentos','Abrir ordens','/ordens');
                table(['Ordem','Agendamento','Status'],array_map(static fn($o)=>[['/ordens/'.$o['id'],'OS '.$o['number']],brDate($o['scheduled_at']),Operations::ORDER_STATUS[$o['status']]],$agenda),'Nenhum atendimento agendado.'); ?></div>
            <?php if (isset($metrics['Recebimentos registrados'])): ?><div class="panel"><?php section('Recebimentos por mês');
            if ($chart): ?><div class="chart-box"><canvas role="img" aria-label="Recebimentos em reais nos últimos seis meses" data-chart="<?=e(json_encode($chart,JSON_THROW_ON_ERROR))?>"></canvas></div>
            <details class="mt-3"><summary>Ver valores</summary><?php table(['Mês','Valor'],array_map(static fn($row)=>[$row['label'],money($row['value'])],$chart)); ?></details>
            <?php else: ?><p class="empty">Nenhum recebimento registrado nos últimos seis meses.</p><?php endif; ?>
            </div><?php endif; ?></div>
        <?php },'Acompanhe os atendimentos e as pendências da sua empresa.', $w->technician ? '' : 'Novo orçamento',$w->technician ? '' : '/orcamentos/novo');
    }

    public function finance(?int $id): void
    {
        $w = $this->web;
        $r = $w->context(true);
        if ($id && !$w->get()) {
            (new Operations($r,$w->actor()))->pay($id,$_POST);
            $w->redirect('/financeiro/'.$id,'Recebimento registrado.');
        }
        $pix = $r->db->one('SELECT pix FROM tenants WHERE id=?',[$r->tenant])['pix'];
        $methods = ['pix'=>'Pix','cash'=>'Dinheiro','card'=>'Cartão','transfer'=>'Transferência','other'=>'Outro'];
        if (!$id) {
            $rows = $r->db->all('SELECT r.*,o.number,c.name,
                COALESCE((SELECT SUM(p.amount) FROM payment_records p WHERE p.tenant_id=r.tenant_id AND p.receivable_id=r.id),0.00) received
                FROM receivables r JOIN service_orders o ON o.tenant_id=r.tenant_id AND o.id=r.order_id
                JOIN customers c ON c.tenant_id=o.tenant_id AND c.id=o.customer_id WHERE r.tenant_id=? ORDER BY r.due_on,r.id LIMIT 200',[$r->tenant]);
            $w->page('Financeiro',static function () use ($rows,$pix): void { ?>
                <div class="panel"><?php self::pix($pix);
                table(['Ordem','Cliente','Vencimento','Valor','Recebido','Situação'],array_map(static fn($row)=>[
                    ['/financeiro/'.$row['id'],'OS '.$row['number']],$row['name'],brDate($row['due_on']),money($row['amount']),money($row['received']),self::balanceStatus($row),
                ],$rows),'Para registrar uma cobrança, abra uma ordem de serviço com valor positivo.'); ?></div>
            <?php },'Controle valores a receber e confirme pagamentos manualmente.');
            return;
        }
        $record = $r->get('receivables',$id);
        $payments = $r->db->all('SELECT * FROM payment_records WHERE tenant_id=? AND receivable_id=? ORDER BY paid_on DESC,id DESC',[$r->tenant,$id]);
        $paid = array_sum(array_map(static fn($row)=>Input::cents($row['amount']),$payments));
        $saldo = Input::cents($record['amount'])-$paid;
        $w->page('Cobrança',static function () use ($id,$pix,$methods,$record,$payments,$saldo,$paid): void { ?>
            <div class="panel">
                <p><a href="/ordens/<?=e($record['order_id'])?>">Ver ordem de serviço</a></p>
                <p>Vencimento <?=e(brDate($record['due_on']))?> · <?=e(self::balanceStatus($record+['received'=>Input::decimal($paid)]))?></p>
                <p>Valor <?=e(money($record['amount']))?> · Saldo <?=e(money(Input::decimal($saldo)))?></p>
                <?php if ($record['notes']): ?><p class="preserve"><?=e($record['notes'])?></p><?php endif; self::pix($pix); ?>
            </div><div class="split"><div class="panel"><?php section('Recebimentos registrados');
            table(['Data','Forma','Valor','Observações'],array_map(static fn($row)=>[brDate($row['paid_on']),$methods[$row['method']],money($row['amount']),$row['notes']],$payments)); ?></div>
            <?php if ($saldo>0): ?><div class="panel"><?php section('Registrar recebimento'); form('/financeiro/'.$id);
                ?><input type="hidden" name="request_key" value="<?=e(bin2hex(random_bytes(32)))?>"><?php
                field('amount','Valor recebido',Input::decimal($saldo),'text',true);
                field('paid_on','Data do recebimento',date('Y-m-d'),'date',true); field('method','Forma de pagamento','pix',$methods);
                field('notes','Observações','','textarea'); button('Confirmar recebimento'); ?></form></div><?php endif; ?></div>
        <?php });
    }

    private static function pix(string $pix): void
    {
        if ($pix): ?><div class="actions mb-3"><label class="field mb-0"><span>Chave Pix comercial</span><input class="form-control" id="pix" value="<?=e($pix)?>" readonly></label>
            <button class="btn btn-outline-secondary mt-4" type="button" data-copy="pix">Copiar chave Pix</button></div><?php endif;
    }

    private static function balanceStatus(array $row): string
    {
        if (Input::cents($row['received']) >= Input::cents($row['amount'])) {
            return 'Quitada';
        }
        if ($row['due_on'] < date('Y-m-d')) {
            return 'Vencida';
        }
        return Input::cents($row['received']) > 0 ? 'Parcial' : 'Aberta';
    }

    public function reports(): void
    {
        $w = $this->web;
        $r = $w->context(true);
        if (!(new Plans($r))->effective()['premium']) {
            if (!empty($_GET['formato'])) {
                throw new HttpError(403,'A exportação requer um plano pago ativo.');
            }
            $w->page('Relatórios',static function (): void { ?><p class="notice">Os relatórios detalhados e as exportações operacionais estão disponíveis nos planos pagos. <a href="/#planos">Ver planos</a>.</p><?php });
            return;
        }
        $kind = Input::choice($_GET['tipo'] ?? 'ordens',['ordens','recebimentos']);
        $start = Input::date($_GET['inicio'] ?? date('Y-m-01'));
        $end = Input::date($_GET['fim'] ?? date('Y-m-d'));
        if ($start>$end || (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days > 366) {
            throw new HttpError(422,'Selecione um período válido de até 367 dias.');
        }
        $status = Input::choice($_GET['status'] ?? '',['',...array_keys(Operations::ORDER_STATUS)]);
        $sql = $kind==='ordens'
            ? 'SELECT o.number,c.name,COALESCE(u.name,\'\') technician,o.status,o.created_at day,o.total amount FROM service_orders o'
            : 'SELECT o.number,c.name,COALESCE(u.name,\'\') technician,o.status,p.paid_on day,p.amount FROM service_orders o JOIN receivables r ON r.tenant_id=o.tenant_id AND r.order_id=o.id JOIN payment_records p ON p.tenant_id=r.tenant_id AND p.receivable_id=r.id';
        $date = $kind==='ordens' ? 'o.created_at' : 'p.paid_on';
        $sql .= ' JOIN customers c ON c.tenant_id=o.tenant_id AND c.id=o.customer_id LEFT JOIN users u ON u.id=o.technician_id';
        $sql .= " WHERE o.tenant_id=? AND $date>=? AND $date<DATE_ADD(?,INTERVAL 1 DAY)";
        $params = [$r->tenant,$start,$end];
        $filters = [];
        foreach (['cliente'=>'o.customer_id','tecnico'=>'o.technician_id','servico'=>null] as $name=>$column) {
            $filters[$name] = empty($_GET[$name]) ? '' : Input::int($_GET[$name]);
            if ($filters[$name]) {
                $sql .= $column ? " AND $column=?" : ' AND EXISTS (SELECT 1 FROM quotation_items qi WHERE qi.tenant_id=o.tenant_id AND qi.quotation_id=o.quotation_id AND qi.service_id=?)';
                $params[] = $filters[$name];
            }
        }
        if ($status !== '') {
            $sql .= ' AND o.status=?';
            $params[] = $status;
        }
        $format = Input::choice($_GET['formato'] ?? '',['','csv','pdf','xlsx']);
        $limit = match ($format) {'pdf'=>300,'csv','xlsx'=>10000,default=>200};
        $rows = $r->db->all($sql." ORDER BY $date DESC,o.id DESC LIMIT ".($limit+1),$params);
        $overflow = count($rows)>$limit;
        if ($overflow && $format !== '') {
            throw new HttpError(422,'A exportação excede o limite. Restrinja os filtros e tente novamente.');
        }
        $headers = ['OS','Cliente','Técnico','Status','Data','Valor'];
        $data = array_map(static fn($row)=>[$row['number'],$row['name'],$row['technician'],Operations::ORDER_STATUS[$row['status']],brDate($row['day']),money($row['amount'])],array_slice($rows,0,$limit));
        if ($format==='csv') {
            Documents::csv('relatorio-'.$kind,$headers,$data);
            return;
        }
        if ($format==='xlsx') {
            SpreadsheetExport::send('relatorio-'.$kind,$headers,$data);
            return;
        }
        if ($format==='pdf') {
            Documents::pdf('relatorio-'.$kind,'<h1>'.e(ucfirst($kind)).'</h1><p>'.e(brDate($start).' a '.brDate($end)).'</p>'.capture(static fn()=>table($headers,$data)));
            return;
        }
        $customers = $w->options($r,'customers','name','Todos');
        $services = $w->options($r,'service_catalog','name','Todos');
        $techs = [''=>'Todos'] + array_column($r->db->all("SELECT u.id,u.name FROM tenant_memberships m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=? AND m.role='technician'",[$r->tenant]),'name','id');
        $w->page('Relatórios',static function () use ($kind,$start,$end,$status,$filters,$headers,$data,$customers,$services,$techs,$overflow): void { ?>
            <div class="panel"><form method="get" action="/relatorios"><div class="field-grid"><?php
            field('tipo','Tipo',$kind,['ordens'=>'Ordens de serviço','recebimentos'=>'Recebimentos']);
            field('inicio','Início',$start,'date',true); field('fim','Fim',$end,'date',true);
            field('status','Status da OS',$status,[''=>'Todos']+Operations::ORDER_STATUS);
            field('cliente','Cliente',$filters['cliente'],$customers); field('tecnico','Técnico',$filters['tecnico'],$techs);
            field('servico','Serviço de orçamento',$filters['servico'],$services); ?></div>
            <div class="actions"><?php button('Filtrar'); ?><button class="btn btn-outline-secondary" name="formato" value="csv">Exportar CSV</button><button class="btn btn-outline-secondary" name="formato" value="pdf">Exportar PDF</button>
            <?php if (SpreadsheetExport::available()): ?><button class="btn btn-outline-secondary" name="formato" value="xlsx">Exportar XLSX</button><?php endif; ?></div></form></div>
            <div class="panel"><?php if ($overflow): ?><p>Exibindo os 200 registros mais recentes. Use os filtros ou exporte o relatório.</p><?php endif; table($headers,$data); ?></div>
        <?php });
    }
}
