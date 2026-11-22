<?php
use ServiFlow\Services\Operations;

function quoteDocument(array $quote, array $items): void { ?>
    <p class="eyebrow"><?=e($quote['company_name'])?></p><h1>Orçamento #<?=e($quote['id'])?></h1>
    <p>Cliente: <?=e($quote['customer_name'])?><?php if ($quote['asset_name']): ?> · <?=e($quote['asset_name'])?><?php endif; ?></p>
    <p>Versão <?=e($quote['version'])?> · Válido até <?=e(brDate($quote['valid_until']))?> · <?=e(Operations::QUOTE_STATUS[$quote['status']])?></p>
    <?php table(['Item','Quantidade','Valor unitário','Total'],array_map(static fn($item) => [
        $item['description'],$item['quantity'],money($item['unit_price']),
        money(\ServiFlow\Support\Input::decimal(\ServiFlow\Support\Input::cents($item['unit_price'])*(int)$item['quantity'])),
    ],$items)); ?>
    <p class="mt-3">Subtotal <?=e(money($quote['subtotal']))?> · Desconto <?=e(money($quote['discount']))?></p>
    <p><strong>Total <?=e(money($quote['total']))?></strong></p>
    <?php if ($quote['terms']): ?><h2>Condições comerciais</h2><p class="preserve"><?=e($quote['terms'])?></p><?php endif;
}

function orderDocument(array $order, string $company, string $client, array $items, bool $internal): void { ?>
    <p class="eyebrow"><?=e($company)?></p><h1>Ordem de serviço <?=e($order['number'])?></h1>
    <p>Cliente: <?=e($client)?> · <?=e(Operations::ORDER_STATUS[$order['status']])?></p>
    <p>Abertura <?=e(brDate($order['created_at']))?> · Agendamento <?=e(brDate($order['scheduled_at']))?> · Previsão <?=e(brDate($order['due_on']))?></p>
    <?php if ($items): table(['Serviço acordado','Quantidade','Valor unitário'],array_map(static fn($row)=>[$row['description'],$row['quantity'],money($row['unit_price'])],$items)); endif; ?>
    <p class="mt-3"><strong>Valor do atendimento <?=e(money($order['total']))?></strong></p>
    <?php if ($internal && $order['problem']): ?><h2>Problema informado</h2><p class="preserve"><?=e($order['problem'])?></p><?php endif; ?>
    <?php if ($internal && $order['diagnosis']): ?><h2>Diagnóstico interno</h2><p class="preserve"><?=e($order['diagnosis'])?></p><?php endif; ?>
    <?php if ($order['customer_report'] && ($internal || $order['status']==='done')): ?><h2>Relatório do atendimento</h2><p class="preserve"><?=e($order['customer_report'])?></p><?php endif;
}
