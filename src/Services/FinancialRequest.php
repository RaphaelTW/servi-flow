<?php
declare(strict_types=1);
namespace ServiFlow\Services;

use ServiFlow\Repositories\TenantRecords;
use ServiFlow\Support\HttpError;

final class FinancialRequest
{
    public static function register(TenantRecords $r, mixed $key, int $resource, string $kind): bool
    {
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D',$key)) {
            throw new HttpError(422,'Atualize a página para reenviar o formulário financeiro.');
        }
        $prior = $r->db->one('SELECT resource_id,kind FROM financial_requests WHERE tenant_id=? AND request_key=? FOR UPDATE',[$r->tenant,$key]);
        if ($prior) {
            if ($prior['kind']!==$kind || (int)$prior['resource_id']!==$resource) {
                throw new HttpError(409,'O identificador do formulário já está associado a outro registro.');
            }
            return false;
        }
        $r->db->run('INSERT INTO financial_requests (tenant_id,request_key,resource_id,kind) VALUES (?,?,?,?)',
            [$r->tenant,$key,$resource,$kind]);
        return true;
    }
}
