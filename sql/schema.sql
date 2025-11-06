-- -------------------------------------------
-- XafPay Backend SQL Schema
-- For PostgreSQL
-- -------------------------------------------

-- 1) Users table (optional for future plugin user management)
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100),
    email VARCHAR(100) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2) Payment sessions table
CREATE TABLE IF NOT EXISTS sessions (
    id SERIAL PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL UNIQUE,
    order_id VARCHAR(64) NOT NULL,
    amount INT NOT NULL,
    currency VARCHAR(5) NOT NULL DEFAULT 'XAF',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3) Transactions table (records every payment request)
CREATE TABLE IF NOT EXISTS transactions (
    id SERIAL PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    order_id VARCHAR(64) NOT NULL,
    provider VARCHAR(20) NOT NULL, -- e.g., 'mtn'
    reference_id VARCHAR(64),      -- MTN request ID
    amount INT NOT NULL,
    currency VARCHAR(5) NOT NULL DEFAULT 'XAF',
    status VARCHAR(20) DEFAULT 'PENDING', -- PENDING, SUCCESSFUL, FAILED
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4) Webhooks (for MTN callbacks)
CREATE TABLE IF NOT EXISTS webhooks (
    id SERIAL PRIMARY KEY,
    reference_id VARCHAR(64) NOT NULL,
    payload JSONB NOT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 5) Indexes for faster lookups
CREATE INDEX IF NOT EXISTS idx_transactions_session ON transactions(session_id);
CREATE INDEX IF NOT EXISTS idx_transactions_order ON transactions(order_id);
CREATE INDEX IF NOT EXISTS idx_webhooks_ref ON webhooks(reference_id);
