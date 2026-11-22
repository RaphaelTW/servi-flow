<?php
declare(strict_types=1);
namespace ServiFlow\Services;

use ServiFlow\Repositories\TenantRecords;
use ServiFlow\Support\{HttpError,Input};

final class Subscriptions
{
    public function __construct(private readonly TenantRecords $records) {}

    public function change(int $actor, array $data): void
    {
        $r = $this->records;
        $r->db->transaction(function () use ($r,$actor,$data): void {
            $r->lockCompany();
            if (!FinancialRequest::register($r,$data['request_key'] ?? null,$r->tenant,'subscription')) {
                return;
            }
            $db = $r->db;
            $current = $db->one('SELECT * FROM subscriptions WHERE tenant_id=? FOR UPDATE',[$r->tenant]);
            $plan = $db->one('SELECT * FROM plans WHERE id=?',[Input::int($data['plan_id'] ?? '')])
                ?? throw new HttpError(422,'Plano inválido.');
            $status = Input::choice($data['status'] ?? '',['active','cancelled']);
            $ends = empty($data['ends_on']) ? null : Input::date($data['ends_on']);
            $received = Input::cents($data['amount_received'] ?? '0');
            $billed = Input::cents($data['amount_billed'] ?? '0');
            $paid = $plan['code']!=='free';
            $wasPaid = (new Plans($r))->effective()['code']!=='free';
            if (($paid && $status==='active' && !$ends) || (!$paid && ($ends || $received>0 || $billed>0))) {
                throw new HttpError(422,'Assinatura paga ativa requer vencimento. Plano Free não tem vencimento, faturamento ou pagamento.');
            }
            if ($paid && !$wasPaid && $status==='active' && $ends>=date('Y-m-d') && $received===0) {
                throw new HttpError(422,'Confirme o valor recebido para ativar uma assinatura paga.');
            }
            $db->run('UPDATE subscriptions SET plan_id=?,starts_on=?,ends_on=?,status=? WHERE tenant_id=?',
                [$plan['id'],$current['plan_id']===$plan['id'] ? $current['starts_on'] : date('Y-m-d'),$ends,$status,$r->tenant]);
            $db->run('INSERT INTO subscription_events (tenant_id,actor_id,old_plan_id,new_plan_id,status,amount_billed,amount_received,ends_on)
                VALUES (?,?,?,?,?,?,?,?)',[$r->tenant,$actor,$current['plan_id'],$plan['id'],$status,Input::decimal($billed),Input::decimal($received),$ends]);
            if ($paid && $status==='active' && $received>0 &&
                !$db->one("SELECT id FROM audit_logs WHERE tenant_id=? AND event='paid_conversion' LIMIT 1",[$r->tenant])) {
                $r->audit('paid_conversion',$actor,$r->tenant);
            }
            $r->audit('subscription_updated',$actor,$r->tenant);
        });
    }
}
