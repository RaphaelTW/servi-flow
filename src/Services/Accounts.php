<?php
declare(strict_types=1);
namespace ServiFlow\Services;

use ServiFlow\Repositories\TenantRecords;
use ServiFlow\Support\{DB,HttpError,Input};

final class Accounts
{
    public function __construct(private readonly DB $db) {}

    public function terms(): array
    {
        return $this->db->all('SELECT d.* FROM legal_documents d JOIN (SELECT kind,MAX(version) version FROM legal_documents GROUP BY kind) latest USING(kind,version)');
    }

    public function register(array $input): int
    {
        $name = Input::text($input['name'] ?? '', 160, true);
        $company = Input::text($input['company'] ?? '', 160, true);
        $email = Input::email($input['email'] ?? '');
        $hash = Input::password($input['password'] ?? '');
        return $this->db->transaction(function () use ($input,$name,$company,$email,$hash): int {
            $documents = $this->terms();
            foreach ($documents as $doc) {
                if (($input[$doc['kind']] ?? '') !== (string) $doc['version']) {
                    throw new HttpError(422, 'Leia e aceite as versões atuais dos documentos.');
                }
            }
            $user = $this->createUser($name,$email,$hash);
            $this->db->run('INSERT INTO tenants (name,email) VALUES (?,?)', [$company,$email]);
            $tenant = $this->db->id();
            $this->db->run("INSERT INTO tenant_memberships (tenant_id,user_id,role) VALUES (?,?,'owner')", [$tenant,$user]);
            $this->db->run("INSERT INTO subscriptions (tenant_id,plan_id,starts_on,status) SELECT ?,id,CURRENT_DATE,'active' FROM plans WHERE code='free'", [$tenant]);
            foreach ($documents as $doc) {
                $this->db->run('INSERT INTO legal_acceptances (user_id,kind,version) VALUES (?,?,?)', [$user,$doc['kind'],$doc['version']]);
            }
            (new TenantRecords($this->db, $tenant))->audit('company_registered', $user, $tenant);
            return $user;
        });
    }

    public function createUser(string $name, string $email, string $hash, bool $platform = false): int
    {
        try {
            $this->db->run('INSERT INTO users (name,email,password,platform_admin) VALUES (?,?,?,?)', [$name,$email,$hash,(int) $platform]);
        } catch (\PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new HttpError(409, 'Já existe uma conta com esse endereço de e-mail.');
            }
            throw $error;
        }
        return $this->db->id();
    }

    public function limit(string $operation, string $key, int $threshold): void
    {
        $bucket = hash('sha256', $operation . '|' . $key);
        $this->db->transaction(function () use ($bucket,$threshold): void {
            $this->db->run('INSERT INTO login_limits VALUES (?,1,DATE_ADD(NOW(),INTERVAL 15 MINUTE)) ON DUPLICATE KEY UPDATE
                attempts=IF(expires_at<=NOW(),1,attempts+1),expires_at=IF(expires_at<=NOW(),DATE_ADD(NOW(),INTERVAL 15 MINUTE),expires_at)', [$bucket]);
            $attempts = (int) $this->db->one('SELECT attempts FROM login_limits WHERE bucket=?', [$bucket])['attempts'];
            if ($attempts > $threshold) {
                throw new HttpError(429, 'Muitas tentativas. Tente novamente em até 15 minutos.');
            }
        });
    }

    public function login(array $input, string $ip): array
    {
        $this->limit('login-ip', $ip, 40);
        $email = Input::email($input['email'] ?? '');
        $this->limit('login-account', $email, 12);
        $user = $this->db->one('SELECT * FROM users WHERE email=?', [$email]);
        $password = $input['password'] ?? '';
        if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0") ||
            !password_verify($password, $user['password'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.') || !$user) {
            throw new HttpError(401, 'E-mail ou senha inválidos.');
        }
        return $user;
    }

    public function addMember(TenantRecords $records, array $input, int $actor, bool $owner): void
    {
        $name = Input::text($input['name'] ?? '', 160, true);
        $email = Input::email($input['email'] ?? '');
        $hash = Input::password($input['password'] ?? '');
        $role = Input::choice($input['role'] ?? '', $owner ? ['admin','technician'] : ['technician']);
        $this->db->transaction(function () use ($records,$name,$email,$hash,$role,$actor): void {
            $records->lockCompany();
            $plan = (new Plans($records))->effective();
            $count = $this->db->run('SELECT COUNT(*) FROM tenant_memberships WHERE tenant_id=? AND active=1', [$records->tenant])->fetchColumn();
            if ($count >= $plan['member_limit']) {
                throw new HttpError(409, 'Limite de usuários ativos do plano atingido.');
            }
            $user = $this->createUser($name,$email,$hash);
            $this->db->run('INSERT INTO tenant_memberships (tenant_id,user_id,role) VALUES (?,?,?)', [$records->tenant,$user,$role]);
            $records->audit('member_created', $actor, $user);
        });
    }
}
