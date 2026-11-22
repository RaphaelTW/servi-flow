'use strict';
const root = document.documentElement;
try { root.dataset.bsTheme = localStorage.getItem('serviflow-theme') === 'dark' ? 'dark' : 'light'; } catch {}
document.querySelector('.theme-toggle')?.addEventListener('click', () => {
    root.dataset.bsTheme = root.dataset.bsTheme === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('serviflow-theme', root.dataset.bsTheme); } catch {}
});
const menu = document.querySelector('.nav-toggle');
if (menu) {
    const small = matchMedia('(max-width: 760px)');
    const sync = () => menu.setAttribute('aria-expanded', String(small.matches ? document.body.classList.contains('menu-open') : !document.body.classList.contains('nav-hidden')));
    sync();
    small.addEventListener('change', sync);
    menu.addEventListener('click', () => {
        document.body.classList.toggle(small.matches ? 'menu-open' : 'nav-hidden');
        sync();
    });
}
document.addEventListener('click', async (event) => {
    const copy = event.target.closest('[data-copy]');
    if (copy) {
        try {
            await navigator.clipboard.writeText(document.getElementById(copy.dataset.copy).value);
            copy.textContent = 'Copiado';
        } catch { document.getElementById(copy.dataset.copy).select(); }
    }
    if (event.target.closest('[data-remove-item]')) event.target.closest('.quote-line').remove();
});
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (form.dataset.confirmed) return;
        event.preventDefault();
        const send = () => {
            form.dataset.confirmed = '1';
            form.requestSubmit();
        };
        if (window.Swal) {
            Swal.fire({title: form.dataset.confirm,showCancelButton:true,confirmButtonText:'Confirmar',cancelButtonText:'Voltar',heightAuto:false}).then((response) => {
                if (response.isConfirmed) send();
            });
        } else if (window.confirm(form.dataset.confirm)) send();
    });
});
const flash = document.querySelector('[data-flash]');
if (flash && window.toastr) {
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    toastr.options = {escapeHtml:true,closeButton:true,timeOut:5000,showDuration:reduce ? 0 : 150,hideDuration:reduce ? 0 : 150};
    toastr.success(flash.textContent);
    flash.hidden = true;
}
window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-submitting]').forEach((button) => {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        delete button.dataset.submitting;
    });
});
document.addEventListener('submit', (event) => {
    const button = event.submitter;
    if (event.defaultPrevented || event.target.method !== 'post' || !button?.classList.contains('btn')) return;
    button.dataset.submitting = '1';
    button.setAttribute('aria-busy','true');
    setTimeout(() => { button.disabled = true; }, 0);
});
const items = document.querySelector('[data-items]');
const template = document.querySelector('#item-template');
if (items && template) {
    let next = Number(items.dataset.next);
    document.querySelector('[data-add-item]').addEventListener('click', () => {
        if (items.children.length >= 30) return;
        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll('[name]').forEach((field) => { field.name = field.name.replace('__item__', String(next)); });
        next += 1;
        items.append(fragment);
        items.lastElementChild.querySelector('select, input').focus();
    });
}
const postal = document.querySelector('[data-postal]');
if (postal) {
    const input = postal.form.elements.cep;
    const status = document.querySelector('[data-postal-status]');
    const cnpjLink = document.querySelector('[data-cnpj-link]');
    if (cnpjLink) {
        const kind = postal.form.elements.kind;
        kind.addEventListener('change',()=>{ cnpjLink.hidden = kind.value !== 'PJ'; });
    }
    let pending;
    const municipalities = document.querySelector('#municipios');
    const uf = input.form.elements.uf;
    let catalogue;
    const suggestions = async () => {
        const state = uf.value.trim().toUpperCase();
        if (!/^[A-Z]{2}$/.test(state)) { municipalities.replaceChildren(); return; }
        try {
            catalogue ??= fetch('/assets/municipios-ibge.json').then((response) => {
                if (!response.ok) throw Error();
                return response.json();
            });
            const cities = (await catalogue)[state] ?? [];
            if (uf.value.trim().toUpperCase() !== state) return;
            municipalities.replaceChildren(...cities.map((city) => {
                const option = document.createElement('option');
                option.value = city;
                return option;
            }));
        } catch { catalogue = undefined; }
    };
    uf.addEventListener('change',suggestions);
    input.form.elements.city.addEventListener('focus',suggestions);
    const lookup = async () => {
        const cep = input.value.trim();
        if (!/^\d{5}-?\d{3}$/.test(cep)) { status.textContent = 'Preencha oito dígitos para consultar.'; return; }
        pending?.abort();
        const request = new AbortController();
        pending = request;
        status.textContent = 'Consultando CEP…';
        try {
            const response = await fetch('/cep/' + encodeURIComponent(cep), {signal:request.signal});
            if (!response.ok) throw Error();
            const data = await response.json();
            if (input.value.trim() !== cep) return;
            Object.entries(data).forEach(([key,value]) => {
                const field = input.form.elements.namedItem(key);
                if (field && !field.value) field.value = value;
            });
            suggestions();
            status.textContent = 'CEP consultado. Confira o endereço.';
        } catch (error) {
            if (error.name !== 'AbortError') status.textContent = 'Consulta indisponível. Preencha o endereço manualmente.';
        }
    };
    postal.addEventListener('click', lookup);
    input.addEventListener('change', () => { if (/^\d{5}-?\d{3}$/.test(input.value.trim())) lookup(); });
}
window.addEventListener('DOMContentLoaded', () => {
    const graph = document.querySelector('[data-chart]');
    if (graph && window.Chart) {
        const values = JSON.parse(graph.dataset.chart);
        const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
        new Chart(graph, {type:'bar', data:{labels:values.map((row)=>row.label),datasets:[{label:'Recebido em reais',data:values.map((row)=>row.value),backgroundColor:'#00816b',borderRadius:5}]},options:{animation:!reduce,responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});
    }
});
