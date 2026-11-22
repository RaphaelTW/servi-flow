ALTER TABLE subscription_events ADD COLUMN amount_billed DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER status;
