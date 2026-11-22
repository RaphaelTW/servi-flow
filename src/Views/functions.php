<?php
function e(mixed $value): string {
    return htmlspecialchars((string) $value,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
}
function money(mixed $amount): string {
    if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D',(string)$amount,$parts)) {
        throw new LogicException('Valor monetário inválido.');
    }
    $integer = preg_replace('/\B(?=(\d{3})+$)/','.', $parts[2]);
    return ($parts[1] ? '−' : '') . 'R$ ' . $integer . ',' . str_pad($parts[3] ?? '',2,'0');
}
function brDate(?string $date): string {
    return $date ? (new DateTimeImmutable($date))->format(strlen($date)>10 ? 'd/m/Y H:i' : 'd/m/Y') : '—';
}
function form(string $action, bool $upload = false): void { ?>
    <form method="post" action="<?=e($action)?>"<?=$upload ? ' enctype="multipart/form-data"' : ''?>>
    <input type="hidden" name="_csrf" value="<?=e(ServiFlow\Middleware\Security::token())?>">
<?php }
function field(string $name, string $label, mixed $value = '', string|array $type = 'text', bool $required = false): void { ?>
    <label class="field"><span><?=e($label)?></span>
    <?php if (is_array($type)): ?>
        <select class="form-select" name="<?=e($name)?>" <?=$required ? 'required' : ''?>>
        <?php foreach ($type as $key=>$option): ?>
            <option value="<?=e($key)?>" <?= (string)$key === (string)$value ? 'selected' : ''?>><?=e($option)?></option>
        <?php endforeach; ?>
        </select>
    <?php elseif ($type === 'textarea'): ?>
        <textarea class="form-control" rows="3" name="<?=e($name)?>" <?=$required ? 'required' : ''?>><?=e($value)?></textarea>
    <?php else: ?>
        <input class="form-control" type="<?=e($type)?>" name="<?=e($name)?>" value="<?=e($value)?>" <?=$required ? 'required' : ''?> <?=$type === 'password' ? 'minlength="12" maxlength="72"' : ''?> <?=$name === 'city' ? 'list="municipios"' : ''?>>
        <?php if ($name === 'city'): ?><datalist id="municipios"></datalist><?php endif; ?>
    <?php endif; ?>
    </label>
<?php }
function button(string $label, string $variant = 'primary'): void { ?>
    <button type="submit" class="btn btn-<?=e($variant)?>"><?=e($label)?></button>
<?php }
function section(string $title, string $label = '', string $url = ''): void { ?>
    <div class="section-head"><h2><?=e($title)?></h2><?php if ($url): ?><a class="btn btn-outline-secondary" href="<?=e($url)?>"><?=e($label)?></a><?php endif; ?></div>
<?php }
function table(array $headers, array $rows, string $empty = 'Nenhum registro por aqui.'): void {
    if (!$rows) { ?><p class="empty"><?=e($empty)?></p><?php return; } ?>
    <div class="table-responsive"><table class="table align-middle"><thead><tr>
    <?php foreach ($headers as $header): ?><th scope="col"><?=e($header)?></th><?php endforeach; ?>
    </tr></thead><tbody><?php foreach ($rows as $row): ?><tr><?php foreach ($row as $cell): ?><td><?=is_array($cell) ? '<a href="'. e($cell[0]) .'">'. e($cell[1]) .'</a>' : e($cell)?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
<?php }
function capture(callable $body): string {
    ob_start();
    $body();
    return ob_get_clean();
}
