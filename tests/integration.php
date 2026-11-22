<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use ServiFlow\Integrations\PostalCode;
use ServiFlow\Services\Accounts;
use ServiFlow\Support\{DB,HttpError,Input};

if (!str_ends_with($_ENV['DB_NAME'] ?? '', '_test')) {
    fwrite(STDERR,"Use um banco exclusivo cujo nome termine em _test.\n");
    exit(1);
}
$db = new DB();
$origin = $_ENV['TEST_URL'] ?? 'http://127.0.0.1:8081';
$checks = 0;
function assertTest(bool $value, string $message): void {
    global $checks;
    if (!$value) {
        throw new RuntimeException($message);
    }
    $checks++;
    echo "OK $message\n";
}

final class Browser
{
    private CurlHandle $curl;
    public function __construct(private readonly string $origin)
    {
        $this->curl = curl_init();
        curl_setopt_array($this->curl,[CURLOPT_COOKIEFILE=>'',CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,
            CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>30]);
    }

    public function request(string $path, ?array $data = null, int $status = 200, ?string $origin = null): array
    {
        curl_setopt_array($this->curl,[CURLOPT_URL=>$this->origin.$path,CURLOPT_HTTPGET=>$data===null,
            CURLOPT_HTTPHEADER=>['Origin: '.($origin ?? $this->origin)]]);
        if ($data !== null) {
            $multipart = (bool)array_filter($data,static fn($value)=>$value instanceof CURLFile);
            curl_setopt($this->curl,CURLOPT_POST,true);
            curl_setopt($this->curl,CURLOPT_POSTFIELDS,$multipart ? $data : http_build_query($data));
        } else {
            curl_setopt($this->curl,CURLOPT_HTTPGET,true);
        }
        $response = curl_exec($this->curl);
        $code = curl_getinfo($this->curl,CURLINFO_RESPONSE_CODE);
        $size = curl_getinfo($this->curl,CURLINFO_HEADER_SIZE);
        if ($response===false || $code!==$status) {
            throw new RuntimeException("$path esperado HTTP $status, obtido $code ".curl_error($this->curl). ' '.substr(strip_tags(substr((string)$response,$size)),0,1550));
        }
        return [substr($response,0,$size),substr($response,$size)];
    }

    public function post(string $path, array $data, int $status = 303, ?string $formPath = null): array
    {
        [, $body] = $this->request($formPath ?? $path);
        $csrf = $this->extractCsrf($body,$formPath ?? $path);
        if (preg_match('/name="request_key" value="([a-f0-9]{64})"/',$body,$match)) {
            $data += ['request_key'=>$match[1]];
        }
        return $this->request($path,['_csrf'=>$csrf]+$data,$status);
    }

    public function csrf(string $path): string
    {
        [, $body] = $this->request($path);
        return $this->extractCsrf($body,$path);
    }

    private function extractCsrf(string $body, string $path): string
    {
        if (!preg_match('/name="_csrf" value="([a-f0-9]{64})"/',$body,$matches)) {
            throw new RuntimeException("Token ausente em $path.");
        }
        return $matches[1];
    }
}

function location(string $headers): string {
    if (!preg_match('/^Location: (\/[^\r\n]*)/mi',$headers,$match)) {
        throw new RuntimeException('Redirecionamento ausente.');
    }
    return $match[1];
}

$suffix = bin2hex(random_bytes(5));
$password = bin2hex(random_bytes(12));
$alpha = new Browser($origin);
$beta = new Browser($origin);
$guest = new Browser($origin);
$super = new Browser($origin);
$technician = new Browser($origin);
$docs = array_column((new Accounts($db))->terms(),'version','kind');
foreach (['alpha'=>$alpha,'beta'=>$beta] as $label=>$browser) {
    $browser->post('/cadastro',['name'=>"Usuário $label",'company'=>"Empresa $label $suffix",'email'=>"$label-$suffix@example.test",'password'=>$password]+$docs);
    $browser->post('/sair',[],303,'/painel');
    $browser->post('/entrar',['email'=>"$label-$suffix@example.test",'password'=>$password]);
    $browser->request('/painel');
}
assertTest(true,'Cadastro, login, logout e dashboard para dois tenants');
$wrong = $guest->csrf('/cadastro');
$guest->request('/cadastro',['_csrf'=>'inválido'],403);
$guest->request('/cadastro',['_csrf'=>$wrong],403,'https://invalid.example');
assertTest(true,'CSRF e Origin verificados no servidor');

[$headers] = $alpha->post('/clientes/novo',['name'=>'<script>alert(1)</script> Cliente A '.$suffix,'kind'=>'PF','email'=>"pessoa-$suffix@example.test"]);
$customer = location($headers);
$alpha->post('/clientes/novo',['name'=>'Duplicado','kind'=>'PF','email'=>"pessoa-$suffix@example.test"],409);
[$headers] = $beta->post('/clientes/novo',['name'=>'Cliente B '.$suffix,'kind'=>'PJ']);
$other = location($headers);
[, $customerHtml] = $alpha->request($customer);
assertTest(str_contains($customerHtml,'&lt;script&gt;') && !str_contains($customerHtml,'<script>alert(1)</script>'),'Dados escapados nas páginas de cadastro');
$beta->request($customer,null,404);
$beta->request($customer,['_csrf'=>$beta->csrf($other),'name'=>'Alteração cruzada','kind'=>'PF'],404);
[, $export] = $beta->request('/empresa/exportar');
assertTest(!str_contains($export,'Cliente A '.$suffix) && str_contains($export,'Cliente B '.$suffix),'Isolamento para leitura, edição e exportação');
$id = (int)basename($customer);
[$headers] = $alpha->post('/equipamentos/novo',['customer_id'=>$id,'kind'=>'Notebook','brand'=>'Marca de teste','model'=>'Modelo de teste']);
$asset = (int)basename(location($headers));
[$headers] = $alpha->post('/servicos/novo',['name'=>'Manutenção','price'=>'0,10','minutes'=>'60']);
$service = (int)basename(location($headers));
$date = date('Y-m-d',strtotime('+2 days'));
$input = ['customer_id'=>$id,'asset_id'=>$asset,'valid_until'=>$date,'discount'=>'0,01','terms'=>'Prazo acordado',
    'items'=>[['service'=>$service,'quantity'=>3,'price'=>''],['description'=>'Peça','quantity'=>1,'price'=>'100,00']]];
[$headers] = $alpha->post('/orcamentos/novo',$input);
$quote = location($headers);
[, $quoteHtml] = $alpha->request($quote);
assertTest(str_contains($quoteHtml,'R$ 100,29'),'Subtotal e desconto calculados em centavos');
$beta->request($quote.'/pdf',null,404);
$beta->post('/orcamentos/novo',$input,404);
[, $pdf] = $alpha->request($quote.'/pdf');
assertTest(str_starts_with($pdf,'%PDF-'),'PDF de orçamento gerado pelo fluxo interno');

$share = static function (Browser $browser,string $record): string {
    [, $body] = $browser->post($record.'/compartilhar',[],200,$record);
    if (!preg_match('~/acompanhar/[a-f0-9]{64}~',$body,$m)) {
        throw new RuntimeException('Link não retornado.');
    }
    return $m[0];
};
$first = $share($alpha,$quote);
$alpha->post($quote.'/editar',$input);
$guest->request($first,null,404);
$link = $share($alpha,$quote);
$guest->request('/acompanhar/'.str_repeat('0',64),null,404);
$guest->request($link,['_csrf'=>'inválido','decision'=>'approved','signer'=>'Visitante'],403);
$guest->post($link,['decision'=>'approved','signer'=>'Cliente A','tenant_id'=>999999,'total'=>'0']);
$beta->request($quote.'/versoes/2/pdf',null,404);
[, $snapshotPdf] = $alpha->request($quote.'/versoes/2/pdf');
assertTest(str_starts_with($snapshotPdf,'%PDF-'),'Cópia da versão aprovada disponível somente na empresa');
$guest->request($link,['_csrf'=>$guest->csrf('/entrar'),'decision'=>'approved','signer'=>'Reenvio'],409);
[$headers] = $alpha->post($quote.'/converter',[],303,$quote);
$order = location($headers);
[$repeat] = $alpha->post($quote.'/converter',[],303,$quote);
assertTest(location($repeat)===$order,'Link aleatório, versão revogada, aceite único e conversão idempotente');
$beta->request($order,null,404);
$beta->request($order,['_csrf'=>$beta->csrf('/painel'),'status'=>'done'],404);

for ($i=0;$i<4;$i++) {
    $alpha->post('/ordens/nova',['customer_id'=>$id,'total'=>'1,00']);
}
$alpha->post('/ordens/nova',['customer_id'=>$id,'total'=>'1,00'],409);
$alpha->post('/empresa/equipe',['name'=>'Extra','email'=>"extra-$suffix@example.test",'password'=>$password,'role'=>'technician'],409,'/empresa');
assertTest(true,'Free limita ordens mensais e usuários no servidor');

$actor = (new Accounts($db))->createUser('Admin de teste',"platform-$suffix@example.test",Input::password($password),true);
$super->post('/entrar',['email'=>"platform-$suffix@example.test",'password'=>$password]);
$super->request('/plataforma');
$alpha->request('/plataforma',null,403);
$super->request('/clientes',null,403);
$tenant = (int)$db->one('SELECT id FROM tenants WHERE name=?',["Empresa alpha $suffix"])['id'];
$plan = $db->one("SELECT id FROM plans WHERE code='pro'")['id'];
$platform = '/plataforma/empresa/'.$tenant;
$super->post($platform,['plan_id'=>$plan,'ends_on'=>date('Y-m-d',strtotime('+30 days')),'status'=>'active','amount_received'=>'59,90']);
$alpha->post('/empresa/equipe',['name'=>'Técnico','email'=>"tech-$suffix@example.test",'password'=>$password,'role'=>'technician'],303,'/empresa');
$techId = (int)$db->one('SELECT id FROM users WHERE email=?',["tech-$suffix@example.test"])['id'];
$technician->post('/entrar',['email'=>"tech-$suffix@example.test",'password'=>$password]);
$technician->request($order,null,404);
$technician->request('/clientes',null,403);
$alpha->post($order,['status'=>'progress','priority'=>'high','technician_id'=>$techId,'diagnosis'=>'Interno '.$suffix,'checklist'=>'Teste funcional']);
$technician->post($order,['status'=>'done','diagnosis'=>'Interno '.$suffix,'customer_report'=>'Serviço realizado']);
$public = $share($alpha,$order);
[, $page] = $guest->request($public);
assertTest(str_contains($page,'Serviço realizado') && !str_contains($page,'Interno '.$suffix),'Técnico restrito à OS atribuída e portal sem diagnóstico interno');
[, $pdf] = $guest->request($public.'/pdf');
assertTest(str_starts_with($pdf,'%PDF-'),'PDF público disponível após conclusão');
$guest->post($public,['rating'=>5,'note'=>'Atendimento concluído']);
$guest->request($public,['_csrf'=>$guest->csrf('/entrar'),'rating'=>'5'],409);

$tmp = tempnam(sys_get_temp_dir(),'sf_');
try {
    file_put_contents($tmp,'<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    $alpha->post($order.'/foto',['stage'=>'before','photo'=>new CURLFile($tmp,'image/png','foto.png')],422,$order);
    file_put_contents($tmp,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg=='));
    if (extension_loaded('gd')) {
        imagepng(imagecreatetruecolor(2,2),$tmp);
        $alpha->post($order.'/foto',['stage'=>'after','photo'=>new CURLFile($tmp,'image/png','foto.png')],303,$order);
        $file = $db->one('SELECT id FROM service_order_attachments WHERE tenant_id=? AND order_id=?',[$tenant,(int)basename($order)]);
        [, $image] = $alpha->request($order.'/foto/'.$file['id']);
        assertTest(str_starts_with($image,"\x89PNG"),'Imagem reprocessada e servida a usuário autorizado');
        $beta->request($order.'/foto/'.$file['id'],null,404);
    }
} finally {
    unlink($tmp);
}
assertTest(true,'Upload disfarçado rejeitado');
[$headers] = $alpha->post($order.'/cobrar',['due_on'=>date('Y-m-d')],303,$order);
$bill = location($headers);
$requestKey = bin2hex(random_bytes(32));
$payment = ['amount'=>'50,29','paid_on'=>date('Y-m-d'),'method'=>'pix','request_key'=>$requestKey];
$alpha->post($bill,$payment);
$alpha->post($bill,$payment);
$alpha->post($bill,['amount'=>'50,01','paid_on'=>date('Y-m-d'),'method'=>'cash'],422);
$alpha->post($bill,['amount'=>'50,00','paid_on'=>date('Y-m-d'),'method'=>'cash']);
[, $page] = $alpha->request($bill);
assertTest(str_contains($page,'Quitada'),'Recebimentos parciais e bloqueio de valor acima do saldo');

[, $csv] = $alpha->request('/relatorios?formato=csv&tipo=recebimentos');
assertTest(str_contains($csv,'50,29') && !str_contains($csv,'Cliente B '.$suffix),'CSV com recebimentos do tenant');
[, $pdf] = $alpha->request('/relatorios?formato=pdf');
assertTest(str_starts_with($pdf,'%PDF-'),'PDF de relatório gerado');
$beta->request('/relatorios?formato=csv',null,403);
if (extension_loaded('zip')) {
    [, $xlsx] = $alpha->request('/relatorios?formato=xlsx&tipo=recebimentos');
    $tmp = tempnam(sys_get_temp_dir(),'sf_test_');
    file_put_contents($tmp,$xlsx);
    $zip = new ZipArchive();
    try {
        $opened = $zip->open($tmp)===true;
        $sheet = $opened ? $zip->getFromName('xl/worksheets/sheet1.xml') : false;
        if ($opened) {
            $zip->close();
        }
        assertTest(is_string($sheet) && str_contains($sheet,'50,29') && !str_contains($sheet,'Cliente B '.$suffix),'Pacote XLSX válido e isolado');
    } finally {
        unlink($tmp);
    }
}
$super->post($platform,['plan_id'=>$plan,'ends_on'=>date('Y-m-d',strtotime('-10 days')),'status'=>'active','amount_received'=>'0']);
$guest->request($public,null,404);
$technician->request('/ordens',null,403);
[, $page] = $alpha->request('/relatorios');
assertTest(str_contains($page,'disponíveis nos planos pagos'),'Expiração após tolerância retira acesso premium e mantém dados');
$alpha->request($order);
$alpha->post($quote.'/revogar',[],303,$quote);
$guest->request($link,null,404);
assertTest(true,'Revogação de link após aceite');
$team = $db->one("SELECT id FROM plans WHERE code='team'")['id'];
$super->post($platform,['plan_id'=>$team,'ends_on'=>date('Y-m-d',strtotime('+30 days')),'status'=>'active','amount_received'=>'99,90']);
$companyAdmin = new Browser($origin);
$adminEmail = "company-admin-$suffix@example.test";
$alpha->post('/empresa/equipe',['name'=>'Administrador da empresa','email'=>$adminEmail,'password'=>$password,'role'=>'admin'],303,'/empresa');
$companyAdmin->post('/entrar',['email'=>$adminEmail,'password'=>$password]);
$companyAdmin->post('/empresa/equipe',['name'=>'Admin não autorizado','email'=>"forbidden-$suffix@example.test",'password'=>$password,'role'=>'admin'],422,'/empresa');
$companyAdmin->post('/empresa/equipe',['member'=>$techId,'active'=>'0'],303,'/empresa');
$companyAdmin->post('/empresa/equipe',['name'=>'Segundo técnico','email'=>"second-tech-$suffix@example.test",'password'=>$password,'role'=>'technician'],303,'/empresa');
$companyAdmin->request('/empresa/exportar',null,404);
assertTest(true,'Administrador da empresa gerencia técnicos sem elevar privilégios ou exportar dados da empresa');
$db->run('INSERT INTO external_api_cache VALUES (?,?,DATE_ADD(NOW(),INTERVAL 1 DAY)) ON DUPLICATE KEY UPDATE payload=VALUES(payload),expires_at=VALUES(expires_at)',
    ['viacep:01001000','{"street":"Praça da Sé","district":"Sé","city":"São Paulo","uf":"SP"}']);
[, $cep] = $alpha->request('/cep/01001000');
assertTest(str_contains($cep,'street'),'Consulta de CEP a partir do cache');
[, $postalFailure] = $alpha->request('/cep/99999999',null,422);
assertTest(str_contains($postalFailure,'Preencha o endereço manualmente'),'CEP sem resultado preserva o preenchimento manual');
try {
    (new PostalCode($db))->lookup('https://127.0.0.1');
    throw new RuntimeException('CEP inválido aceito.');
} catch (HttpError $error) {
    assertTest($error->status===422,'Integração rejeita destino arbitrário e preserva cadastro manual');
}
echo "$checks verificações concluídas; dados gerados em $suffix.\n";
