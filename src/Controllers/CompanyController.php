<?php
declare(strict_types=1);
namespace ServiFlow\Controllers;

use ServiFlow\Repositories\TenantRecords;
use ServiFlow\Services\{Accounts,Photos,Plans};
use ServiFlow\Support\{HttpError,Input};

final class CompanyController
{
    public function __construct(private readonly Web $web) {}

    public function show(string $action): void
    {
        $w = $this->web;
        $r = $w->context($action !== 'logo' || !$w->get());
        $company = $r->db->one('SELECT * FROM tenants WHERE id=?',[$r->tenant]);
        $membership = $r->db->one('SELECT role FROM tenant_memberships WHERE tenant_id=? AND user_id=?',[$r->tenant,$w->actor()]);
        $owner = $membership['role']==='owner';
        if ($action === 'exportar' && $w->get() && $owner) {
            $this->export($r);
            return;
        }
        if ($action === 'logo' && $w->get()) {
            if (!$company['logo'] || !is_file($path = Photos::path($company['logo']))) {
                throw new HttpError(404,'Logo indisponível.');
            }
            header('Content-Type: image/png');
            readfile($path);
            return;
        }
        if (!$w->get()) {
            if ($action === 'perfil') {
                $values = [];
                foreach (['name'=>160,'email'=>190,'phone'=>30,'document'=>20,'address'=>255,'pix'=>140] as $key=>$length) {
                    $values[$key] = Input::text($_POST[$key] ?? '',$length,$key==='name');
                }
                if ($values['email'] !== '') {
                    $values['email'] = Input::email($values['email']);
                }
                $r->db->transaction(function () use ($r,$w,$values): void {
                    $r->lockCompany();
                    $r->db->run('UPDATE tenants SET name=?,email=?,phone=?,document=?,address=?,pix=? WHERE id=?',[...array_values($values),$r->tenant]);
                    $r->audit('company_updated',$w->actor(),$r->tenant);
                });
                $w->redirect('/empresa','Dados da empresa atualizados.');
            }
            if ($action === 'logo') {
                $this->logo($r,$_FILES['logo'] ?? []);
                $w->redirect('/empresa','Logo atualizado.');
            }
            if ($action === 'equipe') {
                if (isset($_POST['member'])) {
                    $id = Input::int($_POST['member']);
                    $active = (int) Input::choice($_POST['active'] ?? '',['0','1']);
                    $r->db->transaction(function () use ($r,$w,$id,$active,$owner): void {
                        $r->lockCompany();
                        $roles = $owner ? "role IN ('admin','technician')" : "role='technician'";
                        $member = $r->db->one("SELECT * FROM tenant_memberships WHERE tenant_id=? AND user_id=? AND $roles FOR UPDATE",[$r->tenant,$id]);
                        if (!$member) {
                            throw new HttpError(404,'Usuário da equipe não encontrado.');
                        }
                        if ($active && !$member['active'] && $r->db->run('SELECT COUNT(*) FROM tenant_memberships WHERE tenant_id=? AND active=1',[$r->tenant])->fetchColumn() >= (new Plans($r))->effective()['member_limit']) {
                            throw new HttpError(409,'Limite de usuários do plano atingido.');
                        }
                        $r->db->run('UPDATE tenant_memberships SET active=? WHERE tenant_id=? AND user_id=?',[$active,$r->tenant,$id]);
                        $r->db->run('UPDATE users SET session_version=session_version+1 WHERE id=?',[$id]);
                        $r->audit($active ? 'member_enabled':'member_disabled',$w->actor(),$id);
                    });
                } else {
                    (new Accounts($r->db))->addMember($r,$_POST,$w->actor(),$owner);
                }
                $w->redirect('/empresa','Equipe atualizada.');
            }
            if ($action === 'privacidade') {
                $detail = Input::text($_POST['detail'] ?? '',1000,true);
                (new Accounts($r->db))->limit('privacy-request',(string)$w->actor(),8);
                $r->db->transaction(function () use ($r,$w,$detail): void {
                    $r->db->run('INSERT INTO privacy_requests (tenant_id,actor_id,detail) VALUES (?,?,?)',[$r->tenant,$w->actor(),$detail]);
                    $r->audit('privacy_request_created',$w->actor(),$r->db->id());
                });
                $w->redirect('/empresa','Solicitação encaminhada à administração da plataforma.');
            }
            throw new HttpError(403,'Seu perfil não permite esta ação.');
        }
        if ($action !== 'perfil') {
            throw new HttpError(404,'Página não encontrada.');
        }
        $plan = (new Plans($r))->effective();
        $subscription = $r->db->one('SELECT s.*,p.name FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.tenant_id=?',[$r->tenant]);
        $team = $r->db->all('SELECT u.id,u.name,u.email,m.role,m.active FROM tenant_memberships m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=? ORDER BY u.id',[$r->tenant]);
        $requests = $r->db->all('SELECT id,created_at,status FROM privacy_requests WHERE tenant_id=? ORDER BY id DESC LIMIT 20',[$r->tenant]);
        $w->page('Configurações da empresa',static function () use ($company,$owner,$plan,$subscription,$team,$requests): void { ?>
            <div class="split">
                <div><div class="panel"><?php section('Dados comerciais');
                    if ($company['logo']): ?><img class="attachment mb-3" src="/empresa/logo" alt="Logo da empresa"><?php endif;
                    form('/empresa'); field('name','Nome da empresa',$company['name'],'text',true);
                    field('email','E-mail comercial',$company['email'],'email'); field('phone','Telefone',$company['phone'],'tel');
                    field('document','Documento comercial',$company['document']); field('address','Endereço',$company['address']);
                    field('pix','Chave Pix comercial',$company['pix']); button('Salvar empresa'); ?></form>
                    <?php if (extension_loaded('gd')): ?><hr><?php form('/empresa/logo',true); ?>
                        <label class="field"><span>Logo em PNG ou JPEG de até 2 MB</span><input type="file" name="logo" accept="image/png,image/jpeg" class="form-control" required></label>
                        <?php button('Atualizar logo','outline-secondary'); ?></form><?php endif; ?>
                </div><div class="panel"><?php section('Privacidade e suporte');
                    form('/empresa/privacidade'); field('detail','Descreva sua solicitação, inclusive pedidos de eliminação','','textarea',true);
                    button('Enviar solicitação','outline-secondary'); ?></form>
                    <?php if ($requests): table(['Protocolo','Data','Status'],array_map(static fn($row)=>[$row['id'],brDate($row['created_at']),$row['status']==='open'?'Aberta':'Encerrada'],$requests)); endif; ?>
                    <?php if ($owner): ?><p class="mt-3 mb-0"><a href="/empresa/exportar">Exportar dados da empresa em JSON</a></p><?php endif; ?>
                </div></div>
                <div><div class="panel"><?php section('Plano em uso'); ?>
                    <p><strong><?=e($plan['name'])?></strong> · <?=e(money($plan['price']))?> por mês</p>
                    <p><?=e($plan['member_limit'])?> usuário(s) · <?=e($plan['order_limit'])?> novas OS por mês · <?=e($plan['storage_mb'])?> MB para fotos</p>
                    <?php if ($subscription['ends_on']): ?><p>Assinatura <?=e($subscription['name'])?> · vencimento <?=e(brDate($subscription['ends_on']))?></p><?php endif; ?>
                    <a href="/#planos">Comparar planos</a>
                </div><div class="panel"><?php section('Equipe');
                    $roles = ['owner'=>'Proprietário','admin'=>'Administrador','technician'=>'Técnico'];
                    table(['Nome','E-mail','Perfil','Acesso'],array_map(static fn($u)=>[$u['name'],$u['email'],$roles[$u['role']],$u['active']?'Ativo':'Inativo'],$team));
                    foreach ($team as $member): if ($member['role']==='owner' || (!$owner && $member['role']!=='technician')) continue; ?>
                    <div class="mt-2"><?php form('/empresa/equipe'); ?>
                        <input type="hidden" name="member" value="<?=e($member['id'])?>"><input type="hidden" name="active" value="<?=$member['active']?'0':'1'?>">
                        <?php button(($member['active']?'Desativar ':'Reativar ').$member['name'],'outline-secondary'); ?></form></div>
                    <?php endforeach;
                    if ($plan['member_limit']>1): ?><hr><?php form('/empresa/equipe');
                        field('name','Nome','','text',true); field('email','E-mail','','email',true); field('password','Senha inicial','','password',true);
                        field('role','Perfil','technician',$owner ? ['technician'=>'Técnico','admin'=>'Administrador'] : ['technician'=>'Técnico']);
                        button('Adicionar colaborador'); ?></form>
                    <?php endif; ?>
                </div></div>
            </div>
        <?php },'Gerencie dados comerciais, equipe e assinatura.');
    }

    private function logo(TenantRecords $r, array $file): void
    {
        if (!extension_loaded('gd')) {
            throw new HttpError(503,'O envio de logo requer a extensão GD.');
        }
        if (($file['error'] ?? null)!==UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) ||
            !is_uploaded_file($file['tmp_name']) || ($file['size'] ?? 0)>2*1024*1024) {
            throw new HttpError(422,'Envie um logo JPEG ou PNG de até 2 MB.');
        }
        $temp = $file['tmp_name'];
        $info = @getimagesize($temp);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temp);
        if (!$info || $info['mime']!==$mime || !in_array($mime,['image/png','image/jpeg'],true) || $info[0]*$info[1]>4000000) {
            throw new HttpError(422,'Use uma imagem válida de até 4 megapixels.');
        }
        $image = $mime==='image/png' ? @imagecreatefrompng($temp) : @imagecreatefromjpeg($temp);
        if (!$image) {
            throw new HttpError(422,'A imagem está danificada.');
        }
        $width = min(512,$info[0]);
        $image = imagescale($image,$width,max(1,(int)round($info[1]*$width/$info[0])));
        $path = Photos::path($key = bin2hex(random_bytes(32)));
        try {
            if (!$image || !imagesavealpha($image,true) || !imagepng($image,$path)) {
                throw new HttpError(503,'Não foi possível gravar o logo.');
            }
            $old = $r->db->transaction(function () use ($r,$key): ?string {
                $previous = $r->lockCompany()['logo'];
                $r->db->run('UPDATE tenants SET logo=? WHERE id=?',[$key,$r->tenant]);
                $r->audit('company_logo_updated',$this->web->actor(),$r->tenant);
                return $previous;
            });
        } catch (\Throwable $error) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $error;
        }
        if ($old && is_file($oldPath = Photos::path($old))) {
            unlink($oldPath);
        }
    }

    private function export(TenantRecords $r): void
    {
        $tables = ['customers','customer_assets','service_catalog','quotations','quotation_items','quotation_acceptances','quotation_decision_documents',
            'service_orders','service_order_checklists','order_events','reviews','receivables','payment_records','privacy_requests',
            'audit_logs','subscriptions','subscription_events'];
        $r->audit('company_exported',$this->web->actor(),$r->tenant);
        $data = $r->db->transaction(function () use ($r,$tables): array {
            $data = ['empresa'=>$r->db->one('SELECT id,name,email,phone,document,address,pix,created_at FROM tenants WHERE id=?',[$r->tenant]),
                'equipe'=>$r->db->all('SELECT u.id,u.name,u.email,m.role,m.active FROM tenant_memberships m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=?',[$r->tenant]),
                'fotos'=>$r->db->all('SELECT id,order_id,stage,mime,bytes FROM service_order_attachments WHERE tenant_id=?',[$r->tenant])];
            foreach ($tables as $table) {
                $data[$table] = $r->db->all("SELECT * FROM $table WHERE tenant_id=? LIMIT 20001",[$r->tenant]);
                if (count($data[$table])>20000) {
                    throw new HttpError(422,'A exportação excede o limite. Solicite uma cópia ao suporte.');
                }
            }
            return $data;
        });
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="serviflow-empresa.json"');
        echo json_encode($data,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
