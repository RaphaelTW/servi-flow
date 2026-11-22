<?php
declare(strict_types=1);
namespace ServiFlow\Repositories;

use ServiFlow\Support\{DB,HttpError};

final class TenantRecords
{
    private const TABLES = [
        'customers','customer_assets','service_catalog','quotations','service_orders','receivables',
    ];

    public function __construct(public readonly DB $db, public readonly int $tenant) {}

    private function table(string $table): string
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \LogicException('Tabela não cadastrada.');
        }
        return $table;
    }

    public function get(string $table, int $id, bool $lock = false, ?int $technician = null): array
    {
        $table = $this->table($table);
        $params = [$this->tenant, $id];
        $sql = "SELECT * FROM $table WHERE tenant_id=? AND id=?";
        if ($technician !== null) {
            $sql .= ' AND technician_id=?';
            $params[] = $technician;
        }
        return $this->db->one($sql . ($lock ? ' FOR UPDATE' : ''), $params) ??
            throw new HttpError(404, 'Registro não encontrado.');
    }

    public function active(string $table, int $id): array
    {
        $record = $this->get($table, $id);
        if ($record['archived']) {
            throw new HttpError(422, 'Selecione um registro ativo.');
        }
        return $record;
    }

    public function insert(string $table, array $data): int
    {
        $table = $this->table($table);
        $data = ['tenant_id' => $this->tenant] + $data;
        $columns = implode(',', array_keys($data));
        $placeholders = implode(',', array_fill(0, count($data), '?'));
        $this->db->run("INSERT INTO $table ($columns) VALUES ($placeholders)", array_values($data));
        return $this->db->id();
    }

    public function update(string $table, int $id, array $data): void
    {
        $table = $this->table($table);
        $assign = implode(',', array_map(static fn ($column) => "$column=?", array_keys($data)));
        $this->db->run("UPDATE $table SET $assign WHERE tenant_id=? AND id=?", [...array_values($data), $this->tenant, $id]);
    }

    public function list(string $table, string $column, string $search = '', int $page = 1, int $archived = 0): array
    {
        $table = $this->table($table);
        if (!in_array($column, ['name','kind'], true)) {
            throw new \LogicException('Coluna não cadastrada.');
        }
        $offset = ($page - 1) * 20;
        return $this->db->all("SELECT * FROM $table WHERE tenant_id=? AND archived=? AND $column LIKE ? ORDER BY $column,id LIMIT 21 OFFSET $offset",
            [$this->tenant, $archived, '%' . $search . '%']);
    }

    public function options(string $table, string $label): array
    {
        $table = $this->table($table);
        if (!in_array($label, ['name','kind'], true)) {
            throw new \LogicException('Coluna não cadastrada.');
        }
        return $this->db->all("SELECT * FROM $table WHERE tenant_id=? AND archived=0 ORDER BY $label,id", [$this->tenant]);
    }

    public function lockCompany(): array
    {
        return $this->db->one('SELECT * FROM tenants WHERE id=? FOR UPDATE', [$this->tenant])
            ?? throw new HttpError(404, 'Empresa não encontrada.');
    }

    public function audit(string $event, ?int $actor, ?int $resource = null): void
    {
        $this->db->run('INSERT INTO audit_logs (tenant_id,actor_id,event,resource_id) VALUES (?,?,?,?)', [$this->tenant,$actor,$event,$resource]);
    }
}
