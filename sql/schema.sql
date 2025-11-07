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
-- Drop the existing carriers table (and any dependencies)
DROP TABLE IF EXISTS carriers CASCADE;

-- Create the new carriers table
CREATE TABLE IF NOT EXISTS carriers (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,          -- Carrier display name (e.g., MTN MoMo)
    code VARCHAR(20) UNIQUE NOT NULL,           -- Short code (MTN, ORANGE)
    merchant_number VARCHAR(20) NOT NULL,          -- Merchant number to receive payments
    api_user VARCHAR(255),                      -- Optional: API credentials if needed
    api_key VARCHAR(255),
    subscription_key VARCHAR(255),
    environment VARCHAR(20) DEFAULT 'sandbox',
    currency VARCHAR(10) DEFAULT 'XAF',
    country VARCHAR(50) DEFAULT 'Cameroon',    -- For multi-country support later
    active BOOLEAN DEFAULT TRUE,               -- If carrier is available
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);



CREATE TABLE IF NOT EXISTS payment_items (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    session_id UUID REFERENCES sessions(id),
    product_name VARCHAR(255),
    quantity INT DEFAULT 1,
    price NUMERIC(12,2),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT now()
);
CREATE TABLE IF NOT EXISTS payments (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    session_id UUID REFERENCES sessions(id),
    merchant_account_id UUID REFERENCES merchant_accounts(id),
    carrier VARCHAR(50) NOT NULL, -- mtn, orange
    amount NUMERIC(12,2) NOT NULL,
    status VARCHAR(50) DEFAULT 'pending', -- pending, successful, failed
    reference_id VARCHAR(255) UNIQUE, -- payment reference from provider
    response_payload JSONB, -- store provider webhook payload
    created_at TIMESTAMP WITH TIME ZONE DEFAULT now(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT now()
);
CREATE TABLE IF NOT EXISTS sessions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    customer_id UUID REFERENCES customers(id),
    merchant_id UUID REFERENCES merchants(id),
    total_amount NUMERIC(12,2) NOT NULL,
    status VARCHAR(50) DEFAULT 'pending', -- pending, completed, failed
    created_at TIMESTAMP WITH TIME ZONE DEFAULT now(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT now()
);
CREATE TABLE IF NOT EXISTS customers (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20) UNIQUE NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT now()
);
CREATE TABLE IF NOT EXISTS merchant_accounts (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    merchant_id UUID REFERENCES merchants(id) ON DELETE CASCADE,
    carrier VARCHAR(50) NOT NULL, -- mtn, orange
    account_reference TEXT NOT NULL, -- sandbox or real wallet ID
    balance NUMERIC(12,2) DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT now(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT now()
);
CREATE TABLE IF NOT EXISTS merchants (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT now(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT now()
);





-- 5) Indexes for faster lookups
CREATE INDEX IF NOT EXISTS idx_transactions_session ON transactions(session_id);
CREATE INDEX IF NOT EXISTS idx_transactions_order ON transactions(order_id);
CREATE INDEX IF NOT EXISTS idx_webhooks_ref ON webhooks(reference_id);
