<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?=e($title)?> · ServiFlow</title>
    <meta name="description" content="Organize clientes, envie orçamentos pelo WhatsApp e acompanhe cada ordem de serviço.">
    <link rel="icon" href="/assets/favicon.png" type="image/png">
    <?php if ($public): ?>
        <link rel="canonical" href="<?=e($canonical)?>">
        <meta property="og:title" content="<?=e($title)?> · ServiFlow">
        <meta property="og:type" content="website">
        <meta property="og:locale" content="pt_BR">
        <meta property="og:url" content="<?=e($canonical)?>">
        <meta property="og:description" content="Do primeiro orçamento ao serviço concluído.">
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/vendor/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/libs/sweetalert2.min.css">
    <link rel="stylesheet" href="/assets/libs/toastr.min.css">
    <link rel="stylesheet" href="/assets/app.css">
    <script defer src="/assets/libs/jquery.min.js"></script>
    <script defer src="/assets/libs/sweetalert2.min.js"></script>
    <script defer src="/assets/libs/toastr.min.js"></script>
    <?php if ($title === 'Visão geral'): ?><script defer src="/assets/libs/chart.umd.js"></script><?php endif; ?>
    <script defer src="/assets/app.js"></script>
</head>
<body class="<?=$nav ? 'workspace' : 'site'?>">
<a class="visually-hidden-focusable skip" href="#conteudo">Ir para o conteúdo</a>
<?php if ($nav): ?>
    <aside class="sidebar" id="app-nav">
        <a class="brand" href="<?=e(array_key_first($nav))?>"><span class="logo-mark">S</span>ServiFlow<span class="brand-dot">.</span></a>
        <nav aria-label="Menu principal"><?php foreach ($nav as $url=>$item): ?>
            <a class="nav-item" href="<?=e($url)?>" <?=$path === $url || ($url !== '/painel' && str_starts_with($path,$url.'/')) ? 'aria-current="page"' : ''?>><span aria-hidden="true"><?=e($item[0])?></span><?=e($item[1])?></a>
        <?php endforeach; ?></nav>
        <div class="sidebar-foot"><?php if ($companyLogo): ?><img class="company-logo" src="/empresa/logo" alt=""><?php endif; ?><span>Área de trabalho</span><strong><?=e($company)?></strong></div>
    </aside>
<?php endif; ?>
<div class="shell">
<header class="topbar">
    <?php if ($nav): ?>
        <button type="button" class="btn btn-outline-secondary nav-toggle" aria-expanded="true" aria-controls="app-nav">☰ <span>Menu</span></button>
        <span class="top-context"><?=e($company)?></span>
    <?php else: ?>
        <a class="brand" href="/"><span class="logo-mark">S</span>ServiFlow<span class="brand-dot">.</span></a>
        <?php if ($public): ?><nav aria-label="Navegação pública" class="public-nav"><a href="/#funcionalidades">Funcionalidades</a><a href="/#planos">Planos</a></nav><?php endif; ?>
    <?php endif; ?>
    <div class="top-actions">
        <button type="button" class="btn btn-outline-secondary theme-toggle" aria-label="Alternar tema">◐</button>
        <?php if ($user && $nav): ?>
            <span class="user-name"><?=e($user['name'])?></span>
            <?php form('/sair'); button('Sair','outline-secondary'); ?></form>
        <?php elseif ($public): ?><a class="btn btn-outline-secondary" href="<?=$user ? '/painel' : '/entrar'?>"><?=$user ? 'Abrir painel' : 'Entrar'?></a><?php endif; ?>
    </div>
</header>
<main id="conteudo" class="<?= $nav ? 'main-content' : 'public-content' ?>">
    <?php if ($flash): ?><div class="notice" role="status" data-flash><?=e($flash)?></div><?php endif; ?>
    <?=$content?>
</main>
<?php if (!$nav && $public): ?><footer class="site-footer"><a class="brand" href="/">ServiFlow<span class="brand-dot">.</span></a><a href="/privacidade">Privacidade</a><a href="/termos">Termos de uso</a><a href="/#suporte">Suporte</a></footer><?php endif; ?>
</div></body></html>
