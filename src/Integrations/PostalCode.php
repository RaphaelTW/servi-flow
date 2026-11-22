<?php
declare(strict_types=1);
namespace ServiFlow\Integrations;

use ServiFlow\Support\{DB,HttpError};

final class PostalCode
{
    public function __construct(private readonly DB $db) {}

    public function lookup(string $cep): array
    {
        if (!preg_match('/^\d{5}-?\d{3}$/D',$cep)) {
            throw new HttpError(422,'Informe um CEP de oito dígitos.');
        }
        $cep = str_replace('-','',$cep);
        $key = 'viacep:' . $cep;
        $cached = $this->db->one('SELECT payload FROM external_api_cache WHERE cache_key=? AND expires_at>NOW()', [$key]);
        if ($cached) {
            return json_decode($cached['payload'],true,16,JSON_THROW_ON_ERROR);
        }
        $request = curl_init("https://viacep.com.br/ws/$cep/json/");
        $body = '';
        curl_setopt_array($request, [
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT_MS=>1200,CURLOPT_TIMEOUT_MS=>3000,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Accept: application/json'],
            CURLOPT_WRITEFUNCTION=>static function ($handle,string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 8192) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($request);
        $status = curl_getinfo($request,CURLINFO_RESPONSE_CODE);
        $data = $ok && $status === 200 ? json_decode($body,true) : null;
        if (!is_array($data) || !empty($data['erro']) || !isset($data['localidade'],$data['uf'])) {
            throw new HttpError(422,'CEP indisponível. Preencha o endereço manualmente.');
        }
        $result = ['street'=>$data['logradouro'] ?? '', 'district'=>$data['bairro'] ?? '', 'city'=>$data['localidade'],'uf'=>$data['uf']];
        $this->db->run('INSERT INTO external_api_cache VALUES (?,?,DATE_ADD(NOW(),INTERVAL 7 DAY)) ON DUPLICATE KEY UPDATE payload=VALUES(payload),expires_at=VALUES(expires_at)',
            [$key,json_encode($result,JSON_THROW_ON_ERROR)]);
        return $result;
    }
}
