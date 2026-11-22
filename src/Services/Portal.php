<?php
declare(strict_types=1);
namespace ServiFlow\Services;

use ServiFlow\Repositories\TenantRecords;
use ServiFlow\Support\{DB,HttpError,Input};

final class Portal
{
    public function __construct(private readonly DB $db) {}

    public function link(string $token, bool $lock = false): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) {
            throw new HttpError(404,'Link indisponível.');
        }
        $link = $this->db->one('SELECT l.* FROM shared_links l JOIN tenants t ON t.id=l.tenant_id
            WHERE l.token_hash=? AND l.revoked_at IS NULL AND l.expires_at>NOW() AND t.blocked=0' . ($lock ? ' FOR UPDATE' : ''),
            [hash('sha256',$token)]) ?? throw new HttpError(404,'Link indisponível.');
        if ($link['order_id'] && !(new Plans(new TenantRecords($this->db,(int)$link['tenant_id'])))->effective()['premium']) {
            throw new HttpError(404,'Link indisponível.');
        }
        return $link;
    }

    public function decide(string $token, array $input): void
    {
        $decision = Input::choice($input['decision'] ?? '', ['approved','rejected','changes']);
        $signer = Input::text($input['signer'] ?? '',160,true);
        $note = Input::text($input['note'] ?? '',1000,$decision === 'changes');
        $this->db->transaction(function () use ($token,$decision,$signer,$note): void {
            $initial = $this->link($token);
            if (!$initial['quotation_id']) {
                throw new HttpError(404,'Link indisponível.');
            }
            $r = new TenantRecords($this->db,(int) $initial['tenant_id']);
            $r->lockCompany();
            $quote = $r->get('quotations',(int) $initial['quotation_id'],true);
            $link = $this->link($token,true);
            if ($quote['status'] !== 'pending' || $quote['version'] !== $link['version'] || $quote['valid_until'] < date('Y-m-d')) {
                throw new HttpError(409,'Este orçamento não está disponível para resposta.');
            }
            $this->db->run('INSERT INTO quotation_acceptances (tenant_id,quotation_id,version,decision,signer,note) VALUES (?,?,?,?,?,?)',
                [$r->tenant,$quote['id'],$quote['version'],$decision,$signer,$note]);
            $items = $this->db->all('SELECT description,quantity,unit_price FROM quotation_items WHERE tenant_id=? AND quotation_id=? ORDER BY id',
                [$r->tenant,$quote['id']]);
            $this->db->run('INSERT INTO quotation_decision_documents (tenant_id,quotation_id,version,snapshot) VALUES (?,?,?,?)',
                [$r->tenant,$quote['id'],$quote['version'],json_encode(['quotation'=>$quote,'items'=>$items],JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            $r->update('quotations',(int) $quote['id'],['status'=>$decision]);
            $r->audit('quotation_'.$decision,null,(int) $quote['id']);
        });
    }

    public function review(string $token, array $input): void
    {
        $rating = Input::int($input['rating'] ?? null,1,5);
        $note = Input::text($input['note'] ?? '',1000);
        $this->db->transaction(function () use ($token,$rating,$note): void {
            $initial = $this->link($token);
            if (!$initial['order_id']) {
                throw new HttpError(404,'Link indisponível.');
            }
            $r = new TenantRecords($this->db,(int) $initial['tenant_id']);
            $r->lockCompany();
            $order = $r->get('service_orders',(int) $initial['order_id'],true);
            $this->link($token,true);
            if ($order['status'] !== 'done' || $this->db->one('SELECT rating FROM reviews WHERE tenant_id=? AND order_id=?', [$r->tenant,$order['id']])) {
                throw new HttpError(409,'Avaliação indisponível.');
            }
            $this->db->run('INSERT INTO reviews (tenant_id,order_id,rating,note) VALUES (?,?,?,?)', [$r->tenant,$order['id'],$rating,$note]);
            $r->audit('order_reviewed',null,(int) $order['id']);
        });
    }
}
