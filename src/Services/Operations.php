<?php
declare(strict_types=1);
namespace ServiFlow\Services;

use ServiFlow\Repositories\TenantRecords;
use ServiFlow\Support\{HttpError,Input};

final class Operations
{
    public const ORDER_STATUS = [
        'open'=>'Aberta','scheduled'=>'Agendada','progress'=>'Em andamento','waiting'=>'Aguardando cliente',
        'done'=>'Concluída','cancelled'=>'Cancelada',
    ];
    public const QUOTE_STATUS = [
        'draft'=>'Rascunho','pending'=>'Aguardando cliente','approved'=>'Aprovado','rejected'=>'Recusado','changes'=>'Alterações solicitadas',
    ];

    public function __construct(private readonly TenantRecords $records, private readonly int $actor) {}

    private function relations(array $input): array
    {
        $customer = $this->records->active('customers', Input::int($input['customer_id'] ?? null));
        $asset = empty($input['asset_id']) ? null : $this->records->active('customer_assets', Input::int($input['asset_id']));
        if ($asset && $asset['customer_id'] !== $customer['id']) {
            throw new HttpError(422, 'O equipamento precisa pertencer ao cliente selecionado.');
        }
        return [$customer, $asset];
    }

    public function quote(array $input, ?int $id = null): int
    {
        $r = $this->records;
        $db = $r->db;
        return $db->transaction(function () use ($r,$db,$input,$id): int {
            $company = $r->lockCompany();
            $previous = $id ? $r->get('quotations', $id, true) : null;
            if ($previous && $previous['status'] === 'approved') {
                throw new HttpError(409, 'O orçamento aprovado precisa ser preservado.');
            }
            [$customer,$asset] = $this->relations($input);
            $valid = Input::date($input['valid_until'] ?? '');
            if ($valid < date('Y-m-d')) {
                throw new HttpError(422, 'A validade deve ser atual ou futura.');
            }
            $terms = Input::text($input['terms'] ?? '', 5000);
            $discount = Input::cents($input['discount'] ?? '0');
            $lines = $input['items'] ?? null;
            if (!is_array($lines) || count($lines) > 30) {
                throw new HttpError(422, 'Inclua até 30 itens.');
            }
            $subtotal = 0;
            $items = [];
            foreach ($lines as $line) {
                if (!is_array($line)) {
                    throw new HttpError(422, 'Item inválido.');
                }
                $label = Input::text($line['description'] ?? '', 220);
                $catalog = empty($line['service']) ? null : $r->active('service_catalog', Input::int($line['service']));
                if (!$catalog && $label === '') {
                    continue;
                }
                $quantity = Input::int($line['quantity'] ?? '', 1, 1000);
                $price = Input::cents(($line['price'] ?? '') !== '' ? $line['price'] : ($catalog['price'] ?? ''));
                $subtotal += $price * $quantity;
                $items[] = [$catalog['id'] ?? null, $label !== '' ? $label : $catalog['name'], $quantity, Input::decimal($price)];
            }
            if (!$items || $subtotal < $discount || $subtotal > 999999999999) {
                throw new HttpError(422, 'Confira os itens, o subtotal e o desconto.');
            }
            $data = [
                'customer_id'=>$customer['id'],'asset_id'=>$asset['id'] ?? null,'company_name'=>$company['name'],
                'customer_name'=>$customer['name'],'asset_name'=>$asset ? mb_substr(trim($asset['kind'].' '.$asset['brand'].' '.$asset['model']),0,220) : '',
                'valid_until'=>$valid,'terms'=>$terms,'subtotal'=>Input::decimal($subtotal),'discount'=>Input::decimal($discount),
                'total'=>Input::decimal($subtotal-$discount),'status'=>'draft',
            ];
            if ($previous) {
                $r->update('quotations',$id,$data + ['version'=>(int) $previous['version']+1]);
                $db->run('DELETE FROM quotation_items WHERE tenant_id=? AND quotation_id=?', [$r->tenant,$id]);
                $db->run('UPDATE shared_links SET revoked_at=NOW() WHERE tenant_id=? AND quotation_id=? AND revoked_at IS NULL', [$r->tenant,$id]);
            } else {
                $id = $r->insert('quotations',$data);
            }
            foreach ($items as $item) {
                $db->run('INSERT INTO quotation_items (tenant_id,quotation_id,service_id,description,quantity,unit_price) VALUES (?,?,?,?,?,?)', [$r->tenant,$id,...$item]);
            }
            $r->audit('quotation_saved',$this->actor,$id);
            return $id;
        });
    }

    public function order(array $input, ?int $quotation = null): int
    {
        $r = $this->records;
        $db = $r->db;
        return $db->transaction(function () use ($r,$db,$input,$quotation): int {
            $company = $r->lockCompany();
            $plan = (new Plans($r))->effective();
            if ($quotation) {
                $quote = $r->get('quotations',$quotation,true);
                if ($quote['status'] !== 'approved') {
                    throw new HttpError(409, 'A conversão requer um orçamento aprovado.');
                }
                $existing = $db->one('SELECT id FROM service_orders WHERE tenant_id=? AND quotation_id=?', [$r->tenant,$quotation]);
                if ($existing) {
                    return (int) $existing['id'];
                }
                $customerId = $quote['customer_id'];
                $assetId = $quote['asset_id'];
                $total = $quote['total'];
            } else {
                [$customer,$asset] = $this->relations($input);
                $customerId = $customer['id'];
                $assetId = $asset['id'] ?? null;
                $total = Input::decimal(Input::cents($input['total'] ?? '0'));
            }
            $count = $db->run('SELECT COUNT(*) FROM service_orders WHERE tenant_id=? AND created_at>=? AND created_at<?',
                [$r->tenant,date('Y-m-01'),date('Y-m-01', strtotime('first day of next month'))])->fetchColumn();
            if ($count >= $plan['order_limit']) {
                throw new HttpError(409, 'A empresa atingiu o limite mensal de novas ordens do plano.');
            }
            $number = (int) $company['order_sequence'] + 1;
            $db->run('UPDATE tenants SET order_sequence=? WHERE id=?', [$number,$r->tenant]);
            $id = $r->insert('service_orders', [
                'number'=>$number,'quotation_id'=>$quotation,'customer_id'=>$customerId,'asset_id'=>$assetId,'total'=>$total,
                'problem'=>Input::text($input['problem'] ?? '',5000),'diagnosis'=>'','customer_report'=>'',
            ]);
            $this->orderEvent($id,null,'open');
            return $id;
        });
    }

    public function updateOrder(int $id, array $input, ?int $technician): void
    {
        $r = $this->records;
        $db = $r->db;
        $db->transaction(function () use ($r,$db,$id,$input,$technician): void {
            $previous = $r->get('service_orders',$id,true,$technician);
            $status = Input::choice($input['status'] ?? '',array_keys(self::ORDER_STATUS));
            $data = [
                'status'=>$status,'diagnosis'=>Input::text($input['diagnosis'] ?? '',5000),
                'customer_report'=>Input::text($input['customer_report'] ?? '',5000),
            ];
            if ($technician === null) {
                $assigned = empty($input['technician_id']) ? null : Input::int($input['technician_id']);
                if ($assigned && !$db->one("SELECT user_id FROM tenant_memberships WHERE tenant_id=? AND user_id=? AND role='technician' AND active=1", [$r->tenant,$assigned])) {
                    throw new HttpError(422, 'Selecione um técnico ativo da empresa.');
                }
                $data += [
                    'technician_id'=>$assigned,'scheduled_at'=>empty($input['scheduled_at']) ? null : Input::date($input['scheduled_at'],true),
                    'due_on'=>empty($input['due_on']) ? null : Input::date($input['due_on']),
                    'priority'=>Input::choice($input['priority'] ?? '',['low','normal','high','urgent']),
                    'problem'=>Input::text($input['problem'] ?? '',5000),
                ];
            }
            $r->update('service_orders',$id,$data);
            $checks = $db->all('SELECT id FROM service_order_checklists WHERE tenant_id=? AND order_id=?', [$r->tenant,$id]);
            $checked = is_array($input['checked'] ?? null) ? $input['checked'] : [];
            foreach ($checks as $check) {
                $db->run('UPDATE service_order_checklists SET checked=? WHERE tenant_id=? AND order_id=? AND id=?',
                    [(int) in_array((string) $check['id'],$checked,true),$r->tenant,$id,$check['id']]);
            }
            $new = Input::text($input['checklist'] ?? '',180);
            if ($new !== '') {
                if (count($checks) >= 50) {
                    throw new HttpError(409, 'O checklist atingiu 50 itens.');
                }
                $db->run('INSERT INTO service_order_checklists (tenant_id,order_id,label) VALUES (?,?,?)', [$r->tenant,$id,$new]);
            }
            $this->orderEvent($id,$previous['status'],$status);
            if ($status === 'done' && $previous['status'] !== 'done') {
                $r->audit('order_completed',$this->actor,$id);
            }
        });
    }

    private function orderEvent(int $id, ?string $old, string $new): void
    {
        $this->records->db->run('INSERT INTO order_events (tenant_id,order_id,actor_id,old_status,new_status) VALUES (?,?,?,?,?)',
            [$this->records->tenant,$id,$this->actor,$old,$new]);
    }

    public function share(int $id, bool $order, bool $revoke): ?string
    {
        $r = $this->records;
        $db = $r->db;
        return $db->transaction(function () use ($r,$db,$id,$order,$revoke): ?string {
            $r->lockCompany();
            if ($order && !$revoke) {
                (new Plans($r))->requirePremium();
            }
            $resource = $r->get($order ? 'service_orders' : 'quotations',$id,true);
            $column = $order ? 'order_id' : 'quotation_id';
            $db->run("UPDATE shared_links SET revoked_at=NOW() WHERE tenant_id=? AND $column=? AND revoked_at IS NULL", [$r->tenant,$id]);
            if ($revoke) {
                $r->audit('link_revoked',$this->actor,$id);
                return null;
            }
            $expiry = date('Y-m-d H:i:s', strtotime('+14 days'));
            if (!$order) {
                if ($resource['valid_until'] < date('Y-m-d') || in_array($resource['status'],['rejected','changes'],true)) {
                    throw new HttpError(409, 'Revise a proposta e a validade antes de compartilhar.');
                }
                $expiry = min($expiry, $resource['valid_until'].' 23:59:59');
                if ($resource['status'] === 'draft') {
                    $r->update('quotations',$id,['status'=>'pending']);
                }
            }
            $token = bin2hex(random_bytes(32));
            $db->run('INSERT INTO shared_links (token_hash,tenant_id,quotation_id,order_id,version,expires_at) VALUES (?,?,?,?,?,?)',
                [hash('sha256',$token),$r->tenant,$order ? null : $id,$order ? $id : null,$resource['version'] ?? null,$expiry]);
            $r->audit($order ? 'order_shared' : 'quotation_shared',$this->actor,$id);
            return $token;
        });
    }

    public function receive(int $order, array $input): int
    {
        $r = $this->records;
        return $r->db->transaction(function () use ($r,$order,$input): int {
            $record = $r->get('service_orders',$order,true);
            $existing = $r->db->one('SELECT id FROM receivables WHERE tenant_id=? AND order_id=?', [$r->tenant,$order]);
            if ($existing) {
                return (int) $existing['id'];
            }
            if ($record['status'] === 'cancelled' || Input::cents($record['total']) === 0) {
                throw new HttpError(409, 'A cobrança requer uma OS válida com valor positivo.');
            }
            $id = $r->insert('receivables', [
                'order_id'=>$order,'amount'=>$record['total'],'due_on'=>Input::date($input['due_on'] ?? ''),
                'notes'=>Input::text($input['notes'] ?? '',500),
            ]);
            $r->audit('receivable_created',$this->actor,$id);
            return $id;
        });
    }

    public function pay(int $id, array $input): void
    {
        $r = $this->records;
        $r->db->transaction(function () use ($r,$id,$input): void {
            $r->lockCompany();
            $record = $r->get('receivables',$id,true);
            if (!FinancialRequest::register($r,$input['request_key'] ?? null,$id,'receivable')) {
                return;
            }
            $paid = $r->db->run('SELECT COALESCE(SUM(amount),0.00) FROM payment_records WHERE tenant_id=? AND receivable_id=?', [$r->tenant,$id])->fetchColumn();
            $cents = Input::cents($input['amount'] ?? '');
            $date = Input::date($input['paid_on'] ?? '');
            if ($cents === 0 || $cents > Input::cents($record['amount']) - Input::cents($paid) || $date > date('Y-m-d')) {
                throw new HttpError(422, 'Informe um valor dentro do saldo e uma data atual ou passada.');
            }
            $r->db->run('INSERT INTO payment_records (tenant_id,receivable_id,amount,method,paid_on,notes) VALUES (?,?,?,?,?,?)',
                [$r->tenant,$id,Input::decimal($cents),Input::choice($input['method'] ?? '',['pix','cash','card','transfer','other']),
                    $date,Input::text($input['notes'] ?? '',500)]);
            $r->audit('payment_recorded',$this->actor,$id);
        });
    }
}
