# Arquitetura e entregas

O workspace original tinha apenas `.editorconfig`, preservado. O código fica fora da raiz web e usa Composer PSR-4. Apache ou o servidor local entrega `public/`. O roteador em `public/index.php` limita métodos e formatos de parâmetro. Controllers chamam serviços para as regras transacionais e reutilizam templates PHP. `TenantRecords` limita as tabelas aceitas e escopa operações com o ID obtido no contexto de sessão. Consultas específicas incluem esse recorte e juntam as entidades de empresa pelas chaves compostas.

## Dados

- `001_core.sql` cria empresas, contas, equipe, planos, assinaturas, auditoria, limites temporários, clientes, equipamentos e catálogo.
- `002_operations.sql` cria propostas versionadas, itens, decisões, OS, checklist, fotos, links, avaliações, recebimentos, cache e documentos legais.
- `003_document_snapshots.sql` preserva cópias de propostas nas decisões e registra chaves de reenvio financeiro.
- `004_subscription_billing.sql` separa o valor faturado do valor recebido nas alterações de assinatura.

Os serviços usam InnoDB e valores DECIMAL. Cálculo de itens, desconto e pagamentos usa centavos inteiros. A transação de emissão de OS bloqueia a linha da empresa para serializar a quota mensal e a numeração. Aprovação por link revalida versão e estado com bloqueios de linha. A conversão preserva os itens pelo vínculo com o orçamento aprovado, protegido contra edição. Os registros de decisão preservam a versão e os dados do documento sem coletar IP ou agente do navegador.

Arquivamento mantém vínculos históricos. Planos são configuráveis e podem restringir novos usos. Na expiração, o proprietário permanece com acesso a registros internos. A equipe acima da quota perde acesso, novos usos aplicam a quota Free, os relatórios operacionais e o portal de OS ficam indisponíveis. O plano Free mantém orçamento e link de aprovação para cumprir a proposta principal. Os níveis pagos são diferenciados por capacidade e armazenam histórico de mudança. Os limites iniciais pagos foram definidos como 100, 300 e 1.000 OS mensais e 250, 1.000 e 5.000 MB para Profissional, Equipe e Empresa.

## Revisão por fase

| Fase | Arquivos centrais | Resultado ou critério |
| --- | --- | --- |
| Planejamento | `docs/`, `.env.example` | Inspeção, escolha do monólito modular, separação pública e privada e levantamento de compatibilidade |
| Fundação | `bootstrap.php`, `src/Middleware/`, `src/Repositories/`, `001_core.sql` | Migrações executadas e cadastro, login e isolamento verificados em MariaDB |
| Jornada | `CatalogController`, `WorkflowController`, `Operations`, `Portal`, `002_operations.sql` | Cadastro, aceite, conversão, atribuição, checklist, PDF, fotos e portal cobertos pela suíte |
| Operação | `OfficeController`, `CompanyController`, `PlatformController`, `Subscriptions`, `landing.php` | Controle de planos, indicadores, financeiro, landing e onboarding; pagamentos, relatórios e expiração cobertos |
| Qualidade e lançamento | `tests/integration.php`, `bin/check.php`, `docs/prelaunch.md` | Conjunto de testes reexecutável, verificação de dependências e procedimentos para homologação |

## Backlog priorizado

1. Homologar no cPanel de destino com HTTPS, SMTP, extensões, limites, sanitização dos logs de acesso e um teste de restauração. A conta de hospedagem e um domínio público não foram disponibilizados para esta implementação.
2. Revisar os modelos legais com a pessoa responsável pelo tratamento, definir retenção e instalar o canal de atendimento. O protocolo para eliminação é tratado manualmente e precisa seguir o procedimento em operações.
3. Testar com prestadores reais e acompanhar cadastro de primeiro cliente, geração do primeiro link, primeiro aceite, primeira OS concluída e conversão paga. O indicador de envio registra a geração do link. Compartilhamento ou entrega no WhatsApp exige ação externa do usuário.
4. Evoluir equipe com convites, troca de empresa por conta e política de reaceite quando a versão legal exigir. O proprietário cadastra administradores e técnicos. Administradores podem cadastrar técnicos. Cada conta opera em uma empresa.
5. Ampliar filtros/paginação de orçamentos, OS e cobrança conforme o volume. As telas consultam os 100 orçamentos/OS mais recentes e até 200 cobranças. O relatório tem limites explícitos por formato.
6. Avaliar preenchimento automático por CNPJ após verificação de termos e suporte ao formato alfanumérico no provedor. Hoje a consulta abre o site oficial e o CNPJ digitado é validado localmente.
7. Adicionar uma integração de cobrança quando houver um provedor escolhido. O serviço `Subscriptions` centraliza a regra atual sem dependência de gateway. O painel registra valores faturados e recebidos manualmente. Não há emissão fiscal, cobrança recorrente automática ou conciliação bancária.

O console de plataforma mostra MRR aos preços atuais de catálogo, incluindo o período de tolerância. Ajustes no preço mudam a estimativa. O faturamento e recebimento somam os lançamentos registrados no mês, por data de lançamento.
