<?php
declare(strict_types=1);
namespace ServiFlow\Controllers;

use ServiFlow\Integrations\PostalCode;
use ServiFlow\Services\Operations;
use ServiFlow\Support\{HttpError,Input};

final class CatalogController
{
    private const CATALOG = [
        'clientes'=>['customers','Clientes','Cliente','name',[
            'name'=>['Nome ou razão social','text',160,true],'kind'=>['Tipo',['PF'=>'Pessoa física','PJ'=>'Pessoa jurídica'],2,true],
            'document'=>['CPF ou CNPJ','text',20],'phone'=>['Telefone','tel',30],'whatsapp'=>['WhatsApp','tel',30],
            'email'=>['E-mail','email',190],'cep'=>['CEP','text',9],'street'=>['Logradouro','text',160],
            'address_number'=>['Número e complemento','text',25],'district'=>['Bairro','text',100],'city'=>['Cidade','text',100],
            'uf'=>['UF','text',2],'notes'=>['Observações internas','textarea',5000],
        ]],
        'equipamentos'=>['customer_assets','Equipamentos','Equipamento','kind',[
            'customer_id'=>['Cliente','customer',0,true],'kind'=>['Tipo','text',90,true],'brand'=>['Marca','text',90],
            'model'=>['Modelo','text',90],'serial'=>['Número de série','text',120],'location'=>['Local de instalação','text',160],
            'notes'=>['Observações técnicas','textarea',5000],
        ]],
        'servicos'=>['service_catalog','Catálogo de serviços','Serviço','name',[
            'name'=>['Nome','text',160,true],'description'=>['Descrição','textarea',5000],'price'=>['Preço sugerido em BRL','money',0,true],
            'minutes'=>['Duração estimada em minutos','int',0,true],
        ]],
    ];

    public function __construct(private readonly Web $web) {}

    public function postal(string $cep): void
    {
        $r = $this->web->context(true);
        (new \ServiFlow\Services\Accounts($r->db))->limit('cep',(string) $r->tenant,60);
        $result = (new PostalCode($r->db))->lookup($cep);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result,JSON_THROW_ON_ERROR);
    }

    public function show(string $module, ?int $id, ?string $action): void
    {
        $web = $this->web;
        $r = $web->context(true);
        [$table,$plural,$singular,$column,$fields] = self::CATALOG[$module];
        $base = '/'.$module;
        if ($id === null && !$action) {
            $term = Input::text($_GET['q'] ?? '',100);
            $page = Input::int($_GET['pagina'] ?? '1',1,1000000);
            $archive = Input::choice($_GET['arquivo'] ?? '0',['0','1']);
            $rows = $r->list($table,$column,$term,$page,(int) $archive);
            $more = count($rows)>20;
            $web->page($plural,static function () use ($base,$term,$page,$archive,$rows,$more,$column,$module): void { ?>
                <div class="panel">
                <form action="<?=e($base)?>" method="get" class="field-grid">
                    <?php field('q','Buscar',$term); field('arquivo','Situação',$archive,['0'=>'Ativos','1'=>'Arquivados']); ?>
                    <div><?php button('Filtrar','outline-secondary'); ?></div>
                </form></div><div class="panel">
                <?php table(['Registro','Detalhe','Ação'],array_map(static fn($row) => [
                    [$base.'/'.$row['id'],$row[$column]], match($module) {'clientes'=>$row['email'] ?: $row['phone'], 'equipamentos'=>trim($row['brand'].' '.$row['model']), default=>money($row['price'])},
                    [$base.'/'.$row['id'],'Abrir'],
                ],array_slice($rows,0,20)));
                $query = static fn ($next) => $base.'?'.http_build_query(['q'=>$term,'arquivo'=>$archive,'pagina'=>$next]); ?>
                <nav aria-label="Paginação" class="actions mt-3"><?php if ($page>1): ?><a href="<?=e($query($page-1))?>">Anterior</a><?php endif; ?>
                    <span>Página <?=e($page)?></span><?php if ($more): ?><a href="<?=e($query($page+1))?>">Próxima</a><?php endif; ?>
                </nav></div>
            <?php },'', 'Adicionar '.$singular,$base.'/novo');
            return;
        }
        $record = $id ? $r->get($table,$id) : [];
        if (!$web->get()) {
            if ($action === 'arquivo' && $id) {
                $status = (int) Input::choice($_POST['archived'] ?? '', ['0','1']);
                $r->db->transaction(function () use ($r,$web,$table,$id,$status): void {
                    $r->get($table,$id,true);
                    $r->update($table,$id,['archived'=>$status]);
                    $r->audit($status ? $table.'_archived' : $table.'_restored',$web->actor(),$id);
                });
                $web->redirect($base.'/'.$id,$status ? 'Registro arquivado.' : 'Registro reativado.');
            }
            $data = [];
            foreach ($fields as $key=>[$label,$type,$length]) {
                $value = $_POST[$key] ?? '';
                $required = $fields[$key][3] ?? false;
                $data[$key] = match (true) {
                    is_array($type) => Input::choice($value,array_keys($type)),
                    $type === 'customer' => $r->active('customers',Input::int($value))['id'],
                    $type === 'money' => Input::decimal(Input::cents($value)),
                    $type === 'int' => Input::int($value,0,100000),
                    $type === 'email' => $value === '' ? null : Input::email($value),
                    default => Input::text($value,$length,$required),
                };
            }
            if ($module === 'clientes') {
                $data['document'] = $this->document($data['document'],$data['kind']);
                $data['cep'] = str_replace('-','',$data['cep']);
                $data['uf'] = strtoupper($data['uf']);
                if (($data['cep'] && !preg_match('/^\d{8}$/D',$data['cep'])) ||
                    ($data['uf'] && !in_array($data['uf'],explode(' ','AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'),true))) {
                    throw new HttpError(422,'Confira o CEP e a UF.');
                }
            }
            try {
                $saved = $r->db->transaction(function () use ($r,$web,$table,$id,$data): int {
                    if ($id) {
                        $old = $r->get($table,$id,true);
                        if ($table === 'customer_assets' && $old['customer_id'] !== $data['customer_id'] &&
                            ($r->db->one('SELECT id FROM quotations WHERE tenant_id=? AND asset_id=? LIMIT 1',[$r->tenant,$id]) ||
                            $r->db->one('SELECT id FROM service_orders WHERE tenant_id=? AND asset_id=? LIMIT 1',[$r->tenant,$id]))) {
                            throw new HttpError(409,'Equipamentos com histórico precisam manter o cliente vinculado.');
                        }
                        $r->update($table,$id,$data);
                    }
                    $saved = $id ?: $r->insert($table,$data);
                    $r->audit($table.($id ? '_updated' : '_created'),$web->actor(),$saved);
                    return $saved;
                });
            } catch (\PDOException $error) {
                if (($error->errorInfo[1] ?? 0) === 1062) {
                    throw new HttpError(409,'Já existe um cliente com esse documento ou e-mail na empresa.');
                }
                throw $error;
            }
            $web->redirect($base.'/'.$saved,'Registro salvo.');
        }
        $history = [];
        if ($id && $module !== 'servicos') {
            $filter = $module === 'clientes' ? 'customer_id' : 'asset_id';
            $history = $r->db->all("SELECT id,number,status,created_at FROM service_orders WHERE tenant_id=? AND $filter=? ORDER BY id DESC LIMIT 30",[$r->tenant,$id]);
        }
        $options = $module === 'equipamentos' ? $web->options($r,'customers','name','Selecione') : [];
        if ($module === 'equipamentos' && $id && !isset($options[$record['customer_id']])) {
            $options[$record['customer_id']] = $r->get('customers',(int) $record['customer_id'])['name'].' (arquivado)';
        }
        $web->page($id ? ($record[$column] ?? $singular) : 'Novo cadastro · '.$singular,
            static function () use ($base,$id,$fields,$record,$options,$module,$history): void { ?>
            <div class="panel"><?php form($base.'/'.($id ?? 'novo')); ?><div class="field-grid">
            <?php foreach ($fields as $key=>[$label,$type]) {
                $value = $record[$key] ?? ($key === 'kind' && $module==='clientes' ? 'PF' : '');
                field($key,$label,$value,match ($type) {'customer'=>$options,'int'=>'number','money'=>'text',default=>$type},$fields[$key][3] ?? false);
                if ($key === 'document'): ?><div class="mb-3" data-cnpj-link <?=($record['kind'] ?? 'PF')==='PJ' ? '' : 'hidden'?>>
                    <a href="https://solucoes.receita.fazenda.gov.br/Servicos/cnpjreva/" target="_blank" rel="noopener noreferrer">Consultar CNPJ na Receita Federal</a>
                </div><?php endif;
                if ($key === 'cep'): ?><div class="mb-3"><button type="button" class="btn btn-outline-secondary" data-postal>Consultar CEP</button><div role="status" data-postal-status></div></div><?php endif;
            } ?></div><?php button('Salvar'); ?> <a class="btn btn-outline-secondary" href="<?=e($base)?>">Voltar</a></form></div>
            <?php if ($id): ?><div class="panel">
                <form method="post" action="<?=e($base.'/'.$id.'/arquivo')?>" data-confirm="<?=$record['archived'] ? 'Reativar este cadastro?' : 'Arquivar este cadastro?'?>">
                <input type="hidden" name="_csrf" value="<?=e(\ServiFlow\Middleware\Security::token())?>">
                <input type="hidden" name="archived" value="<?=$record['archived'] ? '0':'1'?>"><?php button($record['archived'] ? 'Reativar cadastro' : 'Arquivar cadastro','outline-secondary'); ?></form></div>
            <?php endif;
            if ($id && $module !== 'servicos'): ?><div class="panel"><?php section('Histórico de atendimentos');
                table(['Ordem','Status','Abertura'],array_map(static fn($row)=>[['/ordens/'.$row['id'],'OS '.$row['number']],Operations::ORDER_STATUS[$row['status']],brDate($row['created_at'])],$history)); ?>
            </div><?php endif;
        });
    }

    private function document(string $value, string $kind): ?string
    {
        if ($value === '') {
            return null;
        }
        $value = strtoupper($value);
        if (!preg_match('/^[A-Z0-9.\/-]+$/D',$value)) {
            throw new HttpError(422,'Confira o formato do documento.');
        }
        $digits = str_replace(['.','/','-'],'',$value);
        $length = $kind === 'PF' ? 11 : 14;
        $format = $kind === 'PF' ? '/^\d{11}$/D' : '/^[A-Z0-9]{12}\d{2}$/D';
        if (!preg_match($format,$digits) || count(array_unique(str_split($digits))) === 1) {
            throw new HttpError(422,'Confira o documento informado.');
        }
        for ($end=$length-2;$end<$length;$end++) {
            $sum = 0;
            for ($i=0;$i<$end;$i++) {
                $weight = $kind === 'PF' ? $end+1-$i : (($end-1-$i)%8)+2;
                $sum += (ord($digits[$i])-48) * $weight;
            }
            $digit = $sum%11 < 2 ? 0 : 11-$sum%11;
            if ((int) $digits[$end] !== $digit) {
                throw new HttpError(422,'Confira os dígitos verificadores do documento.');
            }
        }
        return $digits;
    }
}
