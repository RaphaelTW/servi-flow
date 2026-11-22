# Permissões e operação

| Ação | Proprietário | Administrador da empresa | Técnico | Link público | Plataforma |
| --- | --- | --- | --- | --- | --- |
| Dados comerciais, clientes, equipamentos, catálogo e orçamentos | Sim | Sim | — | Proposta vinculada | — |
| Criar, atribuir e cobrar OS | Sim | Sim | — | — | — |
| Atualizar execução, checklist, relatório e fotos | Todas as OS da empresa | Todas as OS da empresa | OS atribuída | Apenas status, conclusão e avaliação autorizados | — |
| Gerenciar equipe | Administradores e técnicos | Técnicos | — | — | — |
| Exportar JSON da empresa | Sim | — | — | — | — |
| Emitir PDF e consultar financeiro | Sim | Sim | PDF interno da OS atribuída | PDFs associados ao link | — |
| Editar planos, assinaturas, bloquear acesso e tratar solicitações | — | — | — | — | Sim |

Todas as ações privadas confirmam a conta e a empresa no servidor. Atribuição de técnico, anexos e relatórios consultam os mesmos vínculos. A conta administrativa da plataforma é distinta das contas de operação e não tem páginas de clientes ou ordens. Links públicos são aleatórios, ficam armazenados como hash, expiram em até 14 dias e recebem revogação ao gerar outro link. O orçamento também respeita a data de validade e a versão. O link concede acesso a quem o possuir durante a vigência, por isso o contato deve recebê-lo em conversa privada.

## Backup e recuperação

Faça backup diário do banco e de `storage/` fora da raiz web. Guarde o arquivo de configuração protegido separadamente. Para consistência entre SQL e fotos, interrompa gravações durante a cópia ou use um snapshot coordenado de banco e filesystem. Use uma conta dedicada para o backup. Em XAMPP, chame `mysqldump.exe` ou `mariadb-dump` pelo caminho da instalação.

```text
mysqldump -h 127.0.0.1 -P 3306 -u usuario_backup -p --single-transaction --no-tablespaces --default-character-set=utf8mb4 --result-file=serviflow.sql serviflow
mysql -h 127.0.0.1 -P 3306 -u usuario_restauracao -p serviflow_restauracao
mysql> SOURCE /caminho/privado/serviflow.sql
```

No cPanel, exporte com phpMyAdmin quando não houver acesso ao cliente CLI. Proteja cada cópia com controle de acesso e criptografia. Escolha retenção e destino de backup com a organização. Para restaurar, crie um banco novo, carregue o SQL, copie `storage/` para uma instalação isolada e aponte sua configuração ao banco novo. Confira versão de migrações, login, amostras de propostas, valores financeiros e fotos. Só então troque o banco do ambiente ativo, preservando o original até validar a troca. Migrações DDL podem confirmar alterações automaticamente. Em caso de falha na atualização, revise o esquema antes de reexecutar ou restaure a cópia coordenada em outra instalação. Em especial, a migração 004 é um ALTER único. Se a execução parar depois do ALTER e antes do registro, verifique a coluna antes de registrar a migração já aplicada.

## Privacidade e tratamento manual

- Configure o canal de atendimento e publique versões aprovadas dos documentos no painel da plataforma. Os conteúdos iniciais são modelos sujeitos a revisão jurídica.
- O proprietário pode exportar os registros estruturados e os metadados de fotos. Quando o pedido incluir os arquivos, extraia-os no armazenamento privado após conferir o escopo da empresa.
- Para correção ou eliminação, registre o pedido e confirme a identidade e a abrangência pelo canal configurado. Revise obrigações de conservação antes de anonimizar ou eliminar, incluindo aceites, snapshots, arquivos, logs e backups. Execute em uma transação administrativa específica e preserve apenas o mínimo exigido. Confirme a conclusão ao solicitante. O botão do painel encerra o protocolo e não modifica cadastros pessoais.
- Use a manutenção diária para descartar limites temporários, tokens vencidos de senha, cache expirado e links expirados/revogados há 30 dias. Defina retenção dos históricos de auditoria e dos backups com a organização. Registros operacionais permanecem preservados até procedimento autorizado.
- Evite PII, credenciais e URLs com token em chamados e logs. Os eventos de aplicação registram IDs, categoria de erro e local do código. O servidor e o provedor controlam os próprios logs de acesso.
