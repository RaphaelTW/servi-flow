<?php
declare(strict_types=1);
namespace ServiFlow\Middleware;

use ServiFlow\Repositories\TenantRecords;
use ServiFlow\Support\{DB,HttpError};

final class Security
{
    public static function start(string $base, bool $private): void
    {
        $secure = str_starts_with($base, 'https://');
        if (($_ENV['APP_ENV'] ?? 'production') !== 'local' && (!$secure || empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
            throw new HttpError(503, 'O ServiFlow precisa de uma conexão HTTPS.');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '28800');
        session_name('serviflow_session');
        session_set_cookie_params(['lifetime'=>28800,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
        session_start();
        if (isset($_SESSION['auth_at']) && time() - $_SESSION['auth_at'] > 28800) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
        header('Referrer-Policy: strict-origin');
        header('X-Content-Type-Options: nosniff');
        if ($private) {
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex, nofollow');
        } else {
            header('Cache-Control: no-cache');
        }
        if ($secure && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $token = $_POST['_csrf'] ?? null;
            if (!is_string($token) || !hash_equals(self::token(), $token)) {
                throw new HttpError(403, 'Sessão do formulário inválida. Atualize a página e tente novamente.');
            }
            if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== $base) {
                throw new HttpError(403, 'Origem da solicitação inválida.');
            }
        }
    }

    public static function token(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function signIn(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION = ['user'=>(int) $user['id'], 'session_version'=>(int) $user['session_version'], 'auth_at'=>time()];
    }

    public static function signOut(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function identity(DB $db): ?array
    {
        if (!isset($_SESSION['user'])) {
            return null;
        }
        $user = $db->one('SELECT id,name,email,platform_admin,session_version FROM users WHERE id=?', [$_SESSION['user']]);
        if (!$user || (int) $user['session_version'] !== $_SESSION['session_version']) {
            self::signOut();
            return null;
        }
        return $user;
    }

    public static function membership(DB $db, array $user): array
    {
        $membership = $db->one('SELECT m.*,t.name,t.blocked,t.logo FROM tenant_memberships m JOIN tenants t ON t.id=m.tenant_id WHERE m.user_id=? AND m.active=1 ORDER BY m.tenant_id LIMIT 1', [$user['id']]);
        if ($user['platform_admin'] || !$membership || $membership['blocked']) {
            throw new HttpError(403, 'Acesso à empresa indisponível.');
        }
        $records = new TenantRecords($db, (int) $membership['tenant_id']);
        if ($membership['role'] !== 'owner') {
            $plan = (new \ServiFlow\Services\Plans($records))->effective();
            $rank = $db->run("SELECT COUNT(*) FROM tenant_memberships WHERE tenant_id=? AND active=1
                AND (role='owner' OR user_id<=?)", [$records->tenant,$user['id']])->fetchColumn();
            if ($rank > $plan['member_limit']) {
                throw new HttpError(403, 'Sua empresa excedeu o limite de usuários do plano.');
            }
        }
        return $membership;
    }
}
