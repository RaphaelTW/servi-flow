<section class="hero">
    <div class="hero-inner">
        <div class="hero-copy">
            <p class="eyebrow"><span></span> Gestão para assistência técnica e suporte de TI</p>
            <h1>Do orçamento ao <em>serviço concluído.</em></h1>
            <p class="hero-description">Envie propostas pelo WhatsApp, receba aprovações por link e acompanhe cada atendimento em um só lugar.</p>
            <div class="hero-actions"><a class="btn btn-primary btn-lg" href="/cadastro">Crie seu primeiro orçamento gratuitamente</a><a class="btn btn-outline-secondary btn-lg" href="#fluxo">Veja como funciona</a></div>
            <p class="hero-detail">Sem cartão de crédito · Até 5 ordens de serviço por mês no plano Free</p>
        </div>
        <div class="flow-preview" aria-label="Exemplo ilustrativo do fluxo">
            <div class="preview-head"><div class="logo-mark">S</div><div><strong>Atendimento técnico</strong><small>Exemplo do fluxo de trabalho</small></div></div>
            <div class="preview-row"><span class="step-icon">▤</span><div><strong>Orçamento enviado</strong><small>O cliente recebe o link pelo WhatsApp</small></div><span class="preview-tag">Proposta</span></div>
            <div class="preview-row"><span class="step-icon verified">✓</span><div><strong>Cliente aprovou</strong><small>A resposta fica registrada na proposta</small></div><span class="preview-tag green">Aprovado</span></div>
            <div class="preview-row"><span class="step-icon">⌁</span><div><strong>Ordem de serviço aberta</strong><small>O técnico recebe os itens acordados</small></div><span class="preview-tag">Execução</span></div>
        </div>
    </div>
</section>
<section id="fluxo" class="landing-section">
    <p class="eyebrow">Fluxo de trabalho</p><h2>Encontre tudo sobre o atendimento em um só lugar.</h2>
    <p class="section-intro">Consulte o que foi combinado, o que já foi executado e quanto falta receber sem procurar em conversas e planilhas.</p>
    <div class="feature-grid">
        <article><span class="step-icon">▤</span><h3>Monte a proposta</h3><p>Use seu catálogo, ajuste valores e envie uma proposta com validade e condições comerciais.</p></article>
        <article><span class="step-icon verified">✓</span><h3>Receba a decisão</h3><p>Por um link temporário, o cliente aprova, recusa ou pede ajustes sem criar uma conta.</p></article>
        <article><span class="step-icon">⌁</span><h3>Execute o serviço</h3><p>Abra a OS com o valor aprovado, acompanhe a execução e registre os recebimentos.</p></article>
    </div>
</section>
<section id="funcionalidades" class="landing-section feature-band">
    <p class="eyebrow">Funcionalidades</p><h2>O histórico acompanha cada equipamento.</h2>
    <div class="feature-grid">
        <article><h3>Clientes e equipamentos</h3><p>Consulte contatos, números de série e atendimentos anteriores antes de abrir uma nova OS.</p></article>
        <article><h3>Ordens de serviço</h3><p>Organize a agenda, atribua técnicos e registre diagnósticos com checklist e relatório.</p></article>
        <article><h3>Cobranças e relatórios</h3><p>Acompanhe saldos e registre pagamentos quando confirmados. Exporte relatórios nos planos pagos.</p></article>
    </div>
</section>
<section id="planos" class="landing-section">
    <p class="eyebrow">Planos</p><h2>Escolha a capacidade para sua operação.</h2>
    <div class="plan-grid"><?php foreach ($plans as $plan): ?>
        <article class="plan<?=$plan['code']==='pro' ? ' featured' : ''?>">
            <h3><?=e($plan['name'])?></h3><p class="price"><?=e(money($plan['price']))?><small>/mês</small></p>
            <ul><li>Até <?=e($plan['member_limit'])?> usuário(s)</li><li><?=e($plan['order_limit'])?> novas OS por mês</li><li><?=e($plan['storage_mb'])?> MB para imagens</li><li>Orçamentos com aprovação por link</li><?php if ($plan['premium']): ?><li>Portal de atendimento e relatórios</li><?php endif; ?></ul>
            <a class="btn <?=$plan['code']==='pro'?'btn-primary':'btn-outline-secondary'?>" href="<?=$plan['price'] === '0.00' ? '/cadastro' : '/#suporte'?>"><?=$plan['price'] === '0.00' ? 'Criar conta' : 'Consultar assinatura'?></a>
        </article>
    <?php endforeach; ?></div>
</section>
<section id="suporte" class="landing-section support-section">
    <div><p class="eyebrow">Ajuda</p><h2>Perguntas frequentes</h2>
    <?php if ($support): ?><a href="mailto:<?=e($support)?>">Falar com o suporte</a><?php else: ?><p>Usuários podem encaminhar solicitações pela área da empresa.</p><?php endif; ?></div>
    <div><details><summary>Meu cliente precisa se cadastrar?</summary><p>O link temporário permite consultar a proposta e responder pelo navegador.</p></details>
    <details><summary>Como recebo por Pix?</summary><p>Cadastre a chave Pix da sua empresa para copiá-la ao cobrar. Registre o pagamento após confirmar o crédito.</p></details>
    <details><summary>Como funciona a assinatura paga?</summary><p>O suporte informa as condições e confirma manualmente o pagamento antes de ativar o plano.</p></details></div>
</section>
