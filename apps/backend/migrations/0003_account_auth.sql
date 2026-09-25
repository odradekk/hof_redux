-- #24 注册、登录与初始账号资产。
-- 账号模块持有稳定内部身份、登录凭据、恢复码代次及建队状态；
-- 资产模块持有钱包与体力；请求幂等记录与业务流水同事务提交。
-- 显示名称不充当身份标识；内部身份永不复用。

CREATE TABLE accounts (
  id TEXT PRIMARY KEY,
  login_name TEXT NOT NULL,
  login_key TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  recovery_hash TEXT NOT NULL,
  recovery_generation INTEGER NOT NULL DEFAULT 1 CHECK (recovery_generation >= 1),
  team_completed INTEGER NOT NULL DEFAULT 0 CHECK (team_completed IN (0, 1)),
  created_at TEXT NOT NULL,
  deleted_at TEXT
) STRICT;

CREATE TABLE account_assets (
  account_id TEXT PRIMARY KEY REFERENCES accounts(id) ON DELETE CASCADE,
  money INTEGER NOT NULL CHECK (money >= 0),
  stamina INTEGER NOT NULL CHECK (stamina >= 0),
  stamina_updated_at TEXT NOT NULL
) STRICT;

CREATE TABLE sessions (
  id TEXT PRIMARY KEY,
  token_hash TEXT NOT NULL UNIQUE,
  account_id TEXT NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
  scope TEXT NOT NULL DEFAULT 'player' CHECK (scope IN ('player')),
  created_at TEXT NOT NULL,
  last_activity_at TEXT NOT NULL
) STRICT;
CREATE INDEX idx_sessions_account ON sessions(account_id);

-- 未登录注册入口的请求幂等身份：只存安全状态及原身份引用，
-- 不存密码、恢复码或会话令牌明文（账号契约事务与认证并发）。
CREATE TABLE register_requests (
  request_key TEXT PRIMARY KEY,
  param_digest TEXT NOT NULL,
  status TEXT NOT NULL CHECK (status IN ('completed')),
  account_id TEXT REFERENCES accounts(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL
) STRICT;

-- 资产收支与初始发放的业务流水：与业务变化同一事务提交。
CREATE TABLE game_change_records (
  id TEXT PRIMARY KEY,
  account_id TEXT NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
  kind TEXT NOT NULL,
  money_delta INTEGER NOT NULL,
  stamina_delta INTEGER NOT NULL,
  reason TEXT NOT NULL,
  request_key TEXT,
  created_at TEXT NOT NULL
) STRICT;
CREATE INDEX idx_change_account ON game_change_records(account_id);

-- 应用级元数据：恢复代次（灾难恢复时递增，旧会话/旧请求失效）。
CREATE TABLE app_meta (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
) STRICT;
INSERT INTO app_meta (key, value) VALUES ('recovery_epoch', '1');
