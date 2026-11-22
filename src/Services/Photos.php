<?php
declare(strict_types=1);
namespace ServiFlow\Services;

use ServiFlow\Repositories\TenantRecords;
use ServiFlow\Support\{HttpError,Input};

final class Photos
{
    public function __construct(private readonly TenantRecords $records) {}

    public static function path(string $key): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$key)) {
            throw new \LogicException('Chave de armazenamento inválida.');
        }
        return dirname(__DIR__,2).'/storage/'.$key;
    }

    public function add(int $order, ?int $technician, int $actor, array $upload, mixed $stage): void
    {
        $r = $this->records;
        $stage = Input::choice($stage,['before','after']);
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) ||
            !is_uploaded_file($upload['tmp_name']) || ($upload['size'] ?? 0) < 1 || $upload['size'] > 5*1024*1024) {
            throw new HttpError(422,'Envie uma foto JPEG ou PNG de até 5 MB.');
        }
        $temp = $upload['tmp_name'];
        $info = @getimagesize($temp);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temp);
        if (!$info || $info['mime'] !== $mime || !in_array($mime,['image/jpeg','image/png'],true) ||
            $info[0] < 1 || $info[1] < 1 || $info[0]*$info[1] > 8000000) {
            throw new HttpError(422,'Use JPEG ou PNG válido com até 8 megapixels.');
        }
        if (!extension_loaded('gd')) {
            throw new HttpError(503,'O envio de imagens requer a extensão GD.');
        }
        $image = $mime === 'image/jpeg' ? @imagecreatefromjpeg($temp) : @imagecreatefrompng($temp);
        if (!$image) {
            throw new HttpError(422,'A imagem está danificada.');
        }
        $path = self::path($key = bin2hex(random_bytes(32)));
        try {
            $r->db->transaction(function () use ($r,$order,$technician,$actor,$stage,$key,$path,$mime,$image): void {
                $r->lockCompany();
                $r->get('service_orders',$order,true,$technician);
                $plan = (new Plans($r))->effective();
                $used = (int) $r->db->run('SELECT COALESCE(SUM(bytes),0) FROM service_order_attachments WHERE tenant_id=?',[$r->tenant])->fetchColumn();
                $count = (int) $r->db->run('SELECT COUNT(*) FROM service_order_attachments WHERE tenant_id=? AND order_id=?',[$r->tenant,$order])->fetchColumn();
                if ($count >= 20 || $used >= $plan['storage_mb']*1024*1024) {
                    throw new HttpError(409,'Limite de fotos ou armazenamento atingido.');
                }
                imagesavealpha($image,true);
                $written = $mime === 'image/jpeg' ? imagejpeg($image,$path,85) : imagepng($image,$path,6);
                $bytes = $written ? filesize($path) : false;
                if (!$bytes) {
                    throw new HttpError(503,'Não foi possível gravar a foto.');
                }
                if ($used+$bytes > $plan['storage_mb']*1024*1024 || $bytes > 8*1024*1024) {
                    throw new HttpError(409,'A foto excede o armazenamento disponível.');
                }
                $r->db->run('INSERT INTO service_order_attachments (tenant_id,order_id,stage,file_key,mime,bytes) VALUES (?,?,?,?,?,?)',
                    [$r->tenant,$order,$stage,$key,$mime,$bytes]);
                $r->audit('photo_uploaded',$actor,$order);
            });
        } catch (\Throwable $error) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $error;
        }
    }
}
