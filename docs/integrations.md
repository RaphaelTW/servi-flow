# Integrações e licenças

## Fontes de dados

- **ViaCEP** consulta um CEP por ação no formulário, inclusive ao sair do campo com oito dígitos válidos. O servidor aplica a quota por empresa, usa destino HTTPS fixo, sem redirecionamento, com timeout, limite de resposta e cache de sete dias. O formulário aceita digitação manual quando a consulta falha. O site do serviço descreve uso gratuito e alerta para bloqueio em uso massivo. A implantação precisa verificar os termos e políticas que estiverem vigentes. [Documentação ViaCEP](https://viacep.com.br/)
- **IBGE** fornece o catálogo usado para sugerir municípios por UF. O JSON local foi gerado em 22/11/2026 a partir do endpoint de municípios, com 5.571 entradas em 27 UFs, agrupadas pelo prefixo oficial do ID. O navegador utiliza apenas o arquivo local. A interface aceita digitação manual. [Dados do IBGE](https://servicodados.ibge.gov.br/api/v1/localidades/municipios?orderBy=nome)
- **CNPJ** tem consulta opcional no site oficial, aberta pelo usuário a partir do cadastro de pessoa jurídica. A aplicação valida localmente os dígitos verificadores e mantém o preenchimento manual. A BrasilAPI foi avaliada para CNPJ, CEP, feriados, DDD e bancos. A licença do software não estabelece por si só as condições comerciais dos provedores de dados e do serviço hospedado. [Consulta oficial](https://solucoes.receita.fazenda.gov.br/Servicos/cnpjreva/) · [Projeto BrasilAPI](https://github.com/BrasilAPI/BrasilAPI) · [Cálculo do CNPJ](https://www.gov.br/receitafederal/pt-br/centrais-de-conteudo/publicacoes/documentos-tecnicos/cnpj/manual-dv-cnpj.pdf)
- **WhatsApp** usa um link `wa.me` aberto por iniciativa do usuário, sem conta de API. A aplicação registra a geração do link da proposta, e o usuário escolhe a conversa no WhatsApp.
- **SMTP** é opcional e serve à recuperação de senha. Configure `SMTP_HOST`, `SMTP_PORT`, `SMTP_FROM` e, quando necessário, `SMTP_USER` e `SMTP_PASSWORD` no ambiente privado. A porta 465 usa TLS implícito. Outras portas usam STARTTLS. Valide entrega, expiração e uso único em homologação.

## Inventário

As versões PHP resolvidas estão em `composer.lock`. Use `composer licenses` para regenerar o relatório. Mantenha avisos e licenças junto às dependências nas distribuições aplicáveis.

| Dependência | Versão da entrega | Licença |
| --- | --- | --- |
| Bootstrap | 5.3.9 | MIT |
| Chart.js | 4.5.1 | MIT |
| SweetAlert2 | 11.22.4 | MIT |
| Toastr | 2.1.4 | MIT |
| jQuery | 3.7.1 | MIT |
| Dompdf, PHP Font Lib e PHP SVG Lib | 3.1.6, 1.0.2 e 1.0.2 | LGPL, conforme arquivo de cada biblioteca |
| PHPMailer | 7.2.0 | LGPL-2.1-only |
| PHP dotenv | 5.8.0 | BSD-3-Clause |
| PHP Option | 1.10.0 | Apache-2.0 |
| Graham Campbell Result Type | 1.2.0 | MIT |
| Masterminds HTML5 | 2.12.0 | MIT |
| Sabberworm PHP CSS Parser | 9.5.0 | MIT |
| Symfony polyfills ctype, mbstring e php80 | 1.37.0, 1.43.3 e 1.43.0 | MIT |

Arquivos estáticos e seus avisos acompanham `public/assets/libs/`. O script `bin/assets.php` copia o CSS do Bootstrap e sua licença do diretório de dependências. XLSX utiliza ZIP e XMLWriter da instalação do PHP. Pix copia a chave comercial da empresa. A geração de QR Code ficou fora deste MVP.
