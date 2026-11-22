CREATE TABLE IF NOT EXISTS quotation_decision_documents (
 tenant_id BIGINT NOT NULL, quotation_id BIGINT NOT NULL, version INT NOT NULL, snapshot MEDIUMTEXT NOT NULL,
 PRIMARY KEY (tenant_id,quotation_id,version),
 FOREIGN KEY (tenant_id,quotation_id,version) REFERENCES quotation_acceptances(tenant_id,quotation_id,version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS financial_requests (
 tenant_id BIGINT NOT NULL, request_key CHAR(64) NOT NULL, resource_id BIGINT NOT NULL,
 kind ENUM('receivable','subscription') NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (tenant_id,request_key), FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
