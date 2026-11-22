<?php
use ServiFlow\Services\Operations;
?>
<div class="split">
<div>
<div class="panel">
    <?php section('Execução'); ?>
    <p><?=e($customer['name'])?><?php if ($asset): ?> · <?=e(trim($asset['kind'].' '.$asset['brand'].' '.$asset['model']))?><?php endif; ?> · <?=e(money($order['total']))?></p>
    <?php if ($order['quotation_id'] && !$tech): ?><p><a href="/orcamentos/<?=e($order['quotation_id'])?>">Abrir orçamento aprovado</a></p><?php endif; ?>
    <?php if ($items): table(['Item acordado','Quantidade','Preço'],array_map(static fn($row)=>[$row['description'],$row['quantity'],money($row['unit_price'])],$items)); endif; ?>
    <?php form('/ordens/'.$id); ?>
    <div class="field-grid mt-3"><?php field('status','Status',$order['status'],Operations::ORDER_STATUS,true);
    if (!$tech) {
        field('priority','Prioridade',$order['priority'],['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente']);
        field('technician_id','Técnico responsável',$order['technician_id'],$technicians);
        field('scheduled_at','Agendamento',str_replace(' ','T',substr($order['scheduled_at'] ?? '',0,16)),'datetime-local');
        field('due_on','Previsão de conclusão',$order['due_on'],'date');
    } ?></div>
    <?php if (!$tech): field('problem','Problema informado',$order['problem'],'textarea');
    elseif ($order['problem']): ?><p class="preserve"><?=e($order['problem'])?></p><?php endif;
    field('diagnosis','Diagnóstico interno',$order['diagnosis'],'textarea');
    field('customer_report','Relatório visível ao cliente no portal',$order['customer_report'],'textarea');
    if ($checks): ?><fieldset class="mb-3"><legend class="fs-6 fw-bold">Checklist</legend>
        <?php foreach ($checks as $row): ?><label class="d-block"><input type="checkbox" name="checked[]" value="<?=e($row['id'])?>" <?=$row['checked']?'checked':''?>> <?=e($row['label'])?></label><?php endforeach; ?>
    </fieldset><?php endif;
    field('checklist','Adicionar item ao checklist'); button('Salvar atendimento'); ?></form>
</div>
<div class="panel"><?php section('Fotos do atendimento'); ?>
    <div class="file-list mb-3"><?php foreach ($photos as $photo): $src = '/ordens/'.$id.'/foto/'.$photo['id']; ?>
        <a href="<?=e($src)?>"><img class="attachment" src="<?=e($src)?>" alt="<?=e($photo['stage']==='before' ? 'Antes do serviço' : 'Depois do serviço')?>"></a><?php endforeach; ?></div>
    <?php if (extension_loaded('gd')): form('/ordens/'.$id.'/foto',true);
        field('stage','Etapa','before',['before'=>'Antes','after'=>'Depois']); ?>
        <label class="field"><span>Imagem JPEG ou PNG de até 5 MB</span><input type="file" class="form-control" name="photo" accept="image/jpeg,image/png" required></label>
        <?php button('Enviar foto','outline-secondary'); ?></form>
    <?php else: ?><p class="mb-0">Ative a extensão PHP GD para enviar fotos.</p><?php endif; ?>
</div>
</div>
<div>
    <div class="panel"><?php section('Documentos e compartilhamento'); ?>
        <div class="actions"><a class="btn btn-outline-secondary" href="/ordens/<?=e($id)?>/pdf">Baixar PDF interno</a>
        <?php if (!$tech && $premium) { form('/ordens/'.$id.'/compartilhar'); button('Gerar link do portal'); ?></form><?php }
        if (!$tech) { form('/ordens/'.$id.'/revogar'); button('Revogar link','outline-secondary'); ?></form><?php } ?></div>
    </div>
    <?php if (!$tech && $order['status']!=='cancelled' && \ServiFlow\Support\Input::cents($order['total'])>0): ?><div class="panel"><?php section('Cobrança'); form('/ordens/'.$id.'/cobrar');
        field('due_on','Vencimento',date('Y-m-d'),'date',true); field('notes','Observações','','textarea'); button('Abrir cobrança'); ?></form></div><?php endif; ?>
    <?php if ($review): ?><div class="panel"><?php section('Avaliação do cliente'); ?><p><?=e($review['rating'])?> de 5 · <?=e(brDate($review['created_at']))?></p><p class="preserve"><?=e($review['note'])?></p></div><?php endif; ?>
    <div class="panel"><?php section('Histórico'); table(['Data','Responsável','Status'],array_map(static fn($row)=>[
        brDate($row['created_at']),$row['name'],Operations::ORDER_STATUS[$row['new_status']],
    ],$history)); ?></div>
</div></div>
