CREATE TABLE IF NOT EXISTS quotations (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT NOT NULL, customer_id BIGINT NOT NULL, asset_id BIGINT NULL,
 customer_name VARCHAR(160) NOT NULL, company_name VARCHAR(160) NOT NULL, asset_name VARCHAR(220) NOT NULL DEFAULT '',
 status ENUM('draft','pending','approved','rejected','changes') NOT NULL DEFAULT 'draft', version INT NOT NULL DEFAULT 1,
 valid_until DATE NOT NULL, terms TEXT NOT NULL, subtotal DECIMAL(12,2) NOT NULL, discount DECIMAL(12,2) NOT NULL, total DECIMAL(12,2) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (tenant_id,id), INDEX (tenant_id,status),
 FOREIGN KEY (tenant_id,customer_id) REFERENCES customers(tenant_id,id),
 FOREIGN KEY (tenant_id,customer_id,asset_id) REFERENCES customer_assets(tenant_id,customer_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS quotation_items (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT NOT NULL, quotation_id BIGINT NOT NULL, service_id BIGINT NULL,
 description VARCHAR(220) NOT NULL, quantity INT NOT NULL, unit_price DECIMAL(12,2) NOT NULL,
 FOREIGN KEY (tenant_id,quotation_id) REFERENCES quotations(tenant_id,id),
 FOREIGN KEY (tenant_id,service_id) REFERENCES service_catalog(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS quotation_acceptances (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT NOT NULL, quotation_id BIGINT NOT NULL, version INT NOT NULL,
 decision VARCHAR(16) NOT NULL, signer VARCHAR(160) NOT NULL, note VARCHAR(1000) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (tenant_id,quotation_id,version),
 FOREIGN KEY (tenant_id,quotation_id) REFERENCES quotations(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS service_orders (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT NOT NULL, number INT NOT NULL, quotation_id BIGINT NULL, customer_id BIGINT NOT NULL,
 asset_id BIGINT NULL, technician_id BIGINT NULL, priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
 status ENUM('open','scheduled','progress','waiting','done','cancelled') NOT NULL DEFAULT 'open',
 scheduled_at DATETIME NULL, due_on DATE NULL, problem TEXT NOT NULL, diagnosis TEXT NOT NULL, customer_report TEXT NOT NULL,
 total DECIMAL(12,2) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE (tenant_id,id), UNIQUE (tenant_id,number), UNIQUE (tenant_id,quotation_id), INDEX (tenant_id,created_at),
 INDEX (tenant_id,technician_id,scheduled_at),
 FOREIGN KEY (tenant_id,quotation_id) REFERENCES quotations(tenant_id,id),
 FOREIGN KEY (tenant_id,customer_id) REFERENCES customers(tenant_id,id),
 FOREIGN KEY (tenant_id,customer_id,asset_id) REFERENCES customer_assets(tenant_id,customer_id,id),
 FOREIGN KEY (tenant_id,technician_id) REFERENCES tenant_memberships(tenant_id,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS service_order_checklists (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT NOT NULL, order_id BIGINT NOT NULL, label VARCHAR(180) NOT NULL,
 checked TINYINT NOT NULL DEFAULT 0, FOREIGN KEY (tenant_id,order_id) REFERENCES service_orders(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS service_order_attachments (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT NOT NULL, order_id BIGINT NOT NULL, stage ENUM('before','after') NOT NULL,
 file_key CHAR(64) NOT NULL UNIQUE, mime VARCHAR(30) NOT NULL, bytes INT NOT NULL,
 FOREIGN KEY (tenant_id,order_id) REFERENCES service_orders(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS order_events (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT NOT NULL, order_id BIGINT NOT NULL, actor_id BIGINT NOT NULL,
 old_status VARCHAR(16) NULL, new_status VARCHAR(16) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (tenant_id,order_id) REFERENCES service_orders(tenant_id,id), FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS shared_links (
 token_hash CHAR(64) PRIMARY KEY, tenant_id BIGINT NOT NULL, quotation_id BIGINT NULL, order_id BIGINT NULL, version INT NULL,
 expires_at DATETIME NOT NULL, revoked_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 CHECK ((quotation_id IS NULL) <> (order_id IS NULL)),
 FOREIGN KEY (tenant_id,quotation_id) REFERENCES quotations(tenant_id,id),
 FOREIGN KEY (tenant_id,order_id) REFERENCES service_orders(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS reviews (
 tenant_id BIGINT NOT NULL, order_id BIGINT NOT NULL, rating TINYINT NOT NULL, note VARCHAR(1000) NOT NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, CHECK (rating BETWEEN 1 AND 5),
 PRIMARY KEY (tenant_id,order_id), FOREIGN KEY (tenant_id,order_id) REFERENCES service_orders(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS receivables (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT NOT NULL, order_id BIGINT NOT NULL,
 amount DECIMAL(12,2) NOT NULL, due_on DATE NOT NULL, notes VARCHAR(500) NOT NULL,
 UNIQUE (tenant_id,id), UNIQUE (tenant_id,order_id), INDEX (tenant_id,due_on),
 FOREIGN KEY (tenant_id,order_id) REFERENCES service_orders(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS payment_records (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT NOT NULL, receivable_id BIGINT NOT NULL,
 amount DECIMAL(12,2) NOT NULL, method ENUM('pix','cash','card','transfer','other') NOT NULL, paid_on DATE NOT NULL, notes VARCHAR(500) NOT NULL,
 FOREIGN KEY (tenant_id,receivable_id) REFERENCES receivables(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS external_api_cache (
 cache_key VARCHAR(64) PRIMARY KEY, payload TEXT NOT NULL, expires_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS legal_documents (
 kind ENUM('terms','privacy') NOT NULL, version INT NOT NULL, content TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (kind,version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO legal_documents (kind,version,content) VALUES
 ('terms',1,'Modelo sujeito a revisão jurídica. O ServiFlow permite à empresa organizar serviços, propostas e recebimentos. O aceite em link registra uma manifestação simples. Consulte o suporte para esclarecer condições de uso e assinatura.'),
 ('privacy',1,'Modelo sujeito a revisão jurídica. Tratamos dados de conta para acesso e suporte. Cada empresa registra dados operacionais de seus clientes. Para solicitar acesso, correção ou eliminação, utilize o canal de suporte ou o formulário na área da empresa.');
CREATE TABLE IF NOT EXISTS legal_acceptances (
 user_id BIGINT NOT NULL, kind ENUM('terms','privacy') NOT NULL, version INT NOT NULL, accepted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (user_id,kind,version), FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (kind,version) REFERENCES legal_documents(kind,version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS privacy_requests (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT NOT NULL, actor_id BIGINT NOT NULL, detail VARCHAR(1000) NOT NULL,
 status ENUM('open','closed') NOT NULL DEFAULT 'open', created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (tenant_id) REFERENCES tenants(id), FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
