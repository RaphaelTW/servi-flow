<?php
declare(strict_types=1);
namespace ServiFlow\Controllers;

use PHPMailer\PHPMailer\PHPMailer;
use ServiFlow\Middleware\Security;
use ServiFlow\Services\Accounts;
use ServiFlow\Support\{HttpError,Input};

final class AccountController
{
    private readonly Accounts $accounts;
    public function __construct(private readonly Web $web) { $this->accounts = new Accounts($web->db); }

    public function landing(): void
    {
        $plans = $this->web->db->all('SELECT * FROM plans ORDER BY price,id');
        $support = filter_var($_ENV['SUPPORT_EMAIL'] ?? '',FILTER_VALIDATE_EMAIL);
        $this->web->render('Gestão para assistência técnica', static function () use ($plans,$support) {
            require dirname(__DIR__).'/Views/landing.php';
        },true);
    }

    public function legal(string $kind): void
    {
        $labels = ['terms'=>'Termos de uso','privacy'=>'Política de privacidade'];
        $doc = $this->web->db->one('SELECT * FROM legal_documents WHERE kind=? ORDER BY version DESC LIMIT 1',[$kind]);
        $this->web->render($labels[$kind],static function () use ($doc,$labels,$kind): void { ?>
            <div class="document"><div class="panel"><h1><?=e($labels[$kind])?></h1><p>Versão <?=e($doc['version'])?></p><p class="preserve"><?=e($doc['content'])?></p></div></div>
        <?php },true);
    }

    public function access(bool $register): void
    {
        $web = $this->web;
        if ($web->user) {
            $web->redirect($web->user['platform_admin'] ? '/plataforma' : '/painel');
        }
        $target = $register ? '/cadastro' : '/entrar';
        $title = $register ? 'Crie sua conta' : 'Entre no ServiFlow';
        $input = [];
        $error = '';
        if (!$web->get()) {
            $input = $_POST;
            try {
                if ($register) {
                    $this->accounts->limit('register',$web->ip(),8);
                    $id = $this->accounts->register($input);
                    $user = $web->db->one('SELECT * FROM users WHERE id=?',[$id]);
                } else {
                    $user = $this->accounts->login($input,$web->ip());
                }
                Security::signIn($user);
                $web->redirect($user['platform_admin'] ? '/plataforma' : ($register ? '/empresa' : '/painel'),
                    $register ? 'Conta criada. Confira os dados da empresa e cadastre seu primeiro cliente.' : '');
            } catch (HttpError $failure) {
                http_response_code($failure->status);
                $error = $failure->getMessage();
            }
        }
        $docs = $register ? $this->accounts->terms() : [];
        $smtp = !empty($_ENV['SMTP_HOST']) && !empty($_ENV['SMTP_FROM']);
        $web->render($title,static function () use ($error,$target,$title,$register,$docs,$input,$smtp): void { ?>
            <div class="auth"><div class="panel"><h1><?=e($title)?></h1>
            <?php if ($error): ?><p class="notice" role="alert"><?=e($error)?></p><?php endif; ?>
            <?php form($target);
            $value = static fn ($key) => is_string($input[$key] ?? null) ? $input[$key] : '';
            if ($register) { field('name','Seu nome',$value('name'),'text',true); field('company','Nome da empresa',$value('company'),'text',true); }
            field('email','E-mail',$value('email'),'email',true);
            field('password','Senha','', 'password',true);
            if ($register): ?><p class="small">Use pelo menos 12 caracteres.</p>
                <?php foreach ($docs as $doc): $terms = $doc['kind'] === 'terms'; ?>
                    <div class="mb-2"><label><input type="checkbox" name="<?=e($doc['kind'])?>" value="<?=e($doc['version'])?>" required>
                    Li e aceito a versão <?=e($doc['version'])?></label> · <a href="<?=$terms ? '/termos' : '/privacidade'?>" target="_blank" rel="noopener"><?=$terms ? 'Termos de uso' : 'Política de privacidade'?></a></div>
                <?php endforeach;
            endif;
            button($register ? 'Criar conta gratuita' : 'Entrar'); ?></form>
            <p class="mt-3 mb-0"><?php if ($register): ?>Já usa o ServiFlow? <a href="/entrar">Entrar</a><?php else: ?><a href="/cadastro">Criar uma conta</a><?php if ($smtp): ?> · <a href="/recuperar">Esqueci minha senha</a><?php endif; endif; ?></p>
            </div></div>
        <?php });
    }

    public function logout(): void
    {
        Security::signOut();
        $this->web->redirect('/');
    }

    public function recover(?string $token): void
    {
        $web = $this->web;
        $host = $_ENV['SMTP_HOST'] ?? '';
        $from = $_ENV['SMTP_FROM'] ?? '';
        if (!$token && (!$host || !filter_var($from,FILTER_VALIDATE_EMAIL))) {
            throw new HttpError(404,'A recuperação por e-mail está indisponível.');
        }
        if (!$web->get()) {
            $this->accounts->limit('recovery',$web->ip(),12);
            if ($token) {
                $hash = Input::password($_POST['password'] ?? '');
                $web->db->transaction(function () use ($web,$hash,$token): void {
                    $key = hash('sha256',$token);
                    $reset = $web->db->one('SELECT user_id FROM password_resets WHERE token_hash=? AND expires_at>NOW()',[$key]);
                    if ($reset) {
                        $web->db->one('SELECT id FROM users WHERE id=? FOR UPDATE',[$reset['user_id']]);
                        $reset = $web->db->one('SELECT user_id FROM password_resets WHERE token_hash=? AND expires_at>NOW() FOR UPDATE',[$key]);
                    }
                    if (!$reset) {
                        throw new HttpError(404,'Link expirado ou inválido.');
                    }
                    $web->db->run('UPDATE users SET password=?,session_version=session_version+1 WHERE id=?',[$hash,$reset['user_id']]);
                    $web->db->run('DELETE FROM password_resets WHERE user_id=?',[$reset['user_id']]);
                });
                Security::signOut();
                $web->redirect('/entrar','Senha redefinida.');
            }
            $email = Input::email($_POST['email'] ?? '');
            $this->accounts->limit('recovery-email',$email,4);
            $user = $web->db->one('SELECT id FROM users WHERE email=?',[$email]);
            if ($user) {
                $key = bin2hex(random_bytes(32));
                $web->db->transaction(function () use ($web,$user,$key): void {
                    $web->db->one('SELECT id FROM users WHERE id=? FOR UPDATE',[$user['id']]);
                    $web->db->run('DELETE FROM password_resets WHERE user_id=?',[$user['id']]);
                    $web->db->run('INSERT INTO password_resets VALUES (?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))',[hash('sha256',$key),$user['id']]);
                });
                try {
                    $mailer = new PHPMailer(true);
                    $mailer->isSMTP();
                    $mailer->Host = $host;
                    $mailer->Port = (int) ($_ENV['SMTP_PORT'] ?? 587);
                    $mailer->Timeout = 5;
                    $mailer->SMTPSecure = $mailer->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                    $mailer->Username = $_ENV['SMTP_USER'] ?? '';
                    $mailer->Password = $_ENV['SMTP_PASSWORD'] ?? '';
                    $mailer->SMTPAuth = $mailer->Username !== '';
                    $mailer->CharSet = 'UTF-8';
                    $mailer->setFrom($from,'ServiFlow');
                    $mailer->addAddress($email);
                    $mailer->Subject = 'Redefinição de senha do ServiFlow';
                    $mailer->Body = "Para escolher uma nova senha, abra em até 30 minutos: ".$web->base.'/recuperar/'.$key;
                    $mailer->send();
                } catch (\Throwable) {
                    error_log('{"event":"password_reset_delivery_failed"}');
                }
            }
            $web->redirect('/recuperar','Se o endereço estiver cadastrado, você receberá as instruções de recuperação.');
        }
        $title = $token ? 'Escolha uma nova senha' : 'Recupere o acesso';
        $web->render($title,static function () use ($token,$title): void { ?>
            <div class="auth"><div class="panel"><h1><?=e($title)?></h1>
            <?php form($token ? '/recuperar/'.$token : '/recuperar');
            if ($token) { field('password','Nova senha','','password',true); } else { field('email','E-mail','','email',true); }
            button($token ? 'Salvar senha' : 'Enviar link'); ?></form>
            </div></div>
        <?php });
    }
}
