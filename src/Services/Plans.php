<?php
declare(strict_types=1);
namespace ServiFlow\Services;
use ServiFlow\Repositories\TenantRecords;
use ServiFlow\Support\HttpError;

final class Plans
{
    public function __construct(private readonly TenantRecords $records) {}

    public function effective(): array
    {
        $db = $this->records->db;
        return $db->one("SELECT p.* FROM subscriptions s JOIN plans p ON p.id=s.plan_id
            WHERE s.tenant_id=? AND s.status='active' AND (s.ends_on IS NULL OR DATE_ADD(s.ends_on,INTERVAL p.grace_days DAY)>=CURRENT_DATE)", [$this->records->tenant])
            ?? $db->one("SELECT * FROM plans WHERE code='free'");
    }

    public function requirePremium(): void
    {
        if (!$this->effective()['premium']) {
            throw new HttpError(403, 'Este recurso requer um plano pago ativo.');
        }
    }
}
