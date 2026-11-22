# ServiFlow

Micro-SaaS em português do Brasil para empresas de manutenção e suporte de TI. O fluxo vai do cadastro de cliente e equipamento ao orçamento com aprovação por link, ordem de serviço, relatório e recebimento manual em BRL.

## Executar no Windows com XAMPP

1. Instale PHP 8.3 ou superior para uso contínuo e mantenha-o atualizado. O projeto aceita PHP 8.2 e foi testado com o PHP 8.2.12 e o MariaDB 10.4.32 do XAMPP disponível neste ambiente. Use uma instalação atualizada de MySQL 8 ou MariaDB para homologação.
2. No `php.ini` indicado por `php --ini`, habilite `pdo_mysql`, `mbstring`, `curl`, `dom`, `fileinfo`, `openssl` e `gd`. Habilite `zip` e `xmlwriter` para XLSX. Reinicie o Apache se editar o arquivo usado pelo módulo web. Confirme com `php -m`.
3. Inicie o MariaDB pelo XAMPP. No banco, crie o esquema `serviflow` com UTF-8 e um usuário dedicado com senha. Use credenciais com DDL para a migração e uma conta restrita a SELECT, INSERT, UPDATE e DELETE para a aplicação.
4. Na raiz do projeto, execute:

   ```powershell
   composer install
   Copy-Item .env.example .env
   ```

   Preencha `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASSWORD` em `.env`. Use as credenciais de migração em variáveis de ambiente da sessão administrativa ou configure-as temporariamente no arquivo privado e troque-as pela conta da aplicação em seguida.

   ```powershell
   php bin/migrate.php
   php bin/check.php
   php -S 127.0.0.1:8080 -t public
   ```

5. Acesse [http://127.0.0.1:8080](http://127.0.0.1:8080) e crie uma conta para entrar no plano Free. O cadastro direciona aos dados comerciais. Cadastre o primeiro cliente e crie um orçamento para obter o link de aprovação.

Se o Composer selecionar um descompactador antigo no Windows, habilite ZIP no PHP CLI e rode `composer install` novamente. Neste ambiente, o executável 7-Zip distribuído com Lua não conseguiu extrair as dependências, e a extensão ZIP do PHP resolveu a instalação. No XAMPP atual, também é possível executar os comandos CLI com `php -d extension=gd -d extension=zip` enquanto as extensões não estiverem habilitadas no arquivo de configuração.

Para usar Apache local, aponte o `DocumentRoot` de um VirtualHost ao caminho absoluto de `public`, mantenha o projeto fora de `htdocs`, permita `.htaccess` com `AllowOverride All` nesse diretório e habilite `mod_rewrite`. Defina `APP_URL` com a origem exata, sem subpasta, e use `APP_ENV=local` somente em desenvolvimento com acesso restrito.

## Superadministrador

Crie uma conta separada com `bin/admin.php`, que recebe a senha por entrada padrão. Em PowerShell, use um prompt protegido:

```powershell
$securePassword = Read-Host 'Senha do superadministrador' -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
try {
    [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) |
        php bin/admin.php 'Nome do administrador' 'admin@dominio.example'
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    $securePassword.Dispose()
}
```

Em um terminal Bash, use `read -rsp 'Senha: ' serviflow_admin_password; printf '%s\n' "$serviflow_admin_password" | php bin/admin.php 'Nome' 'admin@dominio.example'; unset serviflow_admin_password`. Entre pela página normal de login. A conta de plataforma é encaminhada para `/plataforma`.

## Funcionalidades

| Área | Disponível |
| --- | --- |
| Conta e empresa | Cadastro, login, logout, primeira configuração, logo, dados comerciais, equipe com cotas e recuperação de senha com SMTP |
| Clientes e catálogo | Busca, paginação, arquivamento e reativação, prevenção de duplicações por documento/e-mail, ViaCEP, sugestões do IBGE e histórico por cliente/equipamento |
| Orçamento | Itens do catálogo e avulsos, totais em centavos, desconto, validade, versão, PDF, WhatsApp, resposta pública, histórico de decisões e conversão idempotente |
| Ordem de serviço | Número por empresa, agenda, prioridade, atribuição, diagnóstico, checklist, fotos privadas, histórico e PDF |
| Portal | Link revogável, status e relatório publicado após conclusão, PDF para o cliente e avaliação |
| Financeiro | Cobranças, status por saldo e vencimento, Pix copiável, pagamentos parciais confirmados manualmente e prevenção de duplicação por reenvio |
| Relatórios | Filtros por período, cliente, técnico, status e serviço, exportações CSV, PDF e XLSX quando as extensões estiverem disponíveis |
| Plataforma | Planos, assinatura manual, tolerância, bloqueios, auditoria, estimativa de MRR, valores faturados e recebidos, vencimentos e indicadores de ativação |
| Privacidade | Modelos versionados, registro no cadastro, exportação JSON da empresa e protocolo para solicitação de eliminação e suporte |

## Testes

Crie um banco separado com nome terminado em `_test`. Em dois terminais, use as mesmas credenciais de teste. O primeiro prepara e serve o banco de testes:

```powershell
$env:DB_NAME='serviflow_test'
$env:APP_ENV='local'
$env:APP_URL='http://127.0.0.1:8081'
php bin/migrate.php
php -S 127.0.0.1:8081 -t public
```

No segundo:

```powershell
$env:DB_NAME='serviflow_test'
$env:TEST_URL='http://127.0.0.1:8081'
composer test
composer audit --locked
```

A suíte grava empresas independentes com identificadores aleatórios no banco exclusivo de teste. Para testar fotos válidas e XLSX, use GD e ZIP nos dois processos PHP. O limitador de cadastro pode devolver 429 após várias rodadas consecutivas do mesmo IP no mesmo banco. Aguarde a janela de 15 minutos ou use outro banco de teste novo. Fotos de teste ficam no armazenamento privado. Use uma cópia separada do projeto se precisar isolar também o diretório de arquivos.

## Documentação

- [Arquitetura, critérios e backlog](docs/architecture.md)
- [Instalação no HostGator/cPanel](docs/deploy.md)
- [Permissões, backup, recuperação e privacidade](docs/operations.md)
- [Integrações e licenças](docs/integrations.md)
- [Checklist de pré-lançamento](docs/prelaunch.md)
