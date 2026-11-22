# Checklist de pré-lançamento

Na verificação local, as quatro migrações foram aplicadas em MariaDB 10.4.32. O Apache do XAMPP serviu a landing, login e sitemap e devolveu 403 ou 404 nas URLs de arquivos privados. Um dump SQL do banco de teste foi restaurado em um esquema separado, consultando migrações, propostas, pagamentos e metadados de fotos. A restauração dos arquivos privados e a entrega de e-mail ainda precisam ser exercitadas no ambiente de destino.

- [ ] Instalar uma versão PHP atualizada e validar GD, PDO MySQL, curl, mbstring, DOM, fileinfo e OpenSSL. Confirmar ZIP/XMLWriter se oferecer XLSX.
- [ ] Confirmar HTTPS, redirecionamentos, CSP, cookies Secure/HttpOnly/SameSite, página de erro e aplicação isolada em seu domínio.
- [ ] Confirmar que arquivos privados não estão expostos e que a hospedagem consegue proteger logs com tokens.
- [ ] Rodar migrações, `bin/check.php`, suíte com duas empresas e auditoria do Composer. Repetir no servidor de homologação.
- [ ] Revisar uso de CPU, memória e disco para upload, relatório PDF e exportação.
- [ ] Configurar SMTP, testar entrega, expiração e uso único do link de recuperação.
- [ ] Criar superadministrador, testar separação de perfis e validar bloqueio, downgrade, vencimento e tolerância na hospedagem.
- [ ] Configurar suporte comercial, fluxo manual de aprovação e conciliação dos valores faturados e recebidos.
- [ ] Publicar política e termos revisados e instalar procedimento para solicitações de titulares.
- [ ] Agendar manutenção e backup. Restaurar banco e armazenamento em ambiente isolado e conferir amostras.
- [ ] Conduzir piloto, medir o fluxo de ativação e registrar problemas encontrados antes da abertura comercial.
