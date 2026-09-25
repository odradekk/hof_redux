-- #25 首次建队并获得首名真实角色。
-- 角色与编队模块持有角色、持有装备实例、默认战术；账号模块持有队名与建队状态。
-- 显示名称不充当身份标识；内部身份（账号/角色/装备/请求）永不复用。
-- 队名按 NFC 规范化后精确唯一（区分大小写，二进制校对）；角色名允许重名，不设唯一约束。

-- 队名存于账号行：未建队为 NULL（UNIQUE 索引允许多个 NULL），建队事务内一次写入。
ALTER TABLE accounts ADD COLUMN team_name TEXT;
CREATE UNIQUE INDEX idx_accounts_team_name ON accounts(team_name);

-- 玩家持有的角色（S1 仅首次建队创建；招募/解雇由后续票据扩展）。
CREATE TABLE characters (
  id TEXT PRIMARY KEY,
  account_id TEXT NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  job_id TEXT NOT NULL,
  gender TEXT NOT NULL CHECK (gender IN ('male', 'female')),
  level INTEGER NOT NULL CHECK (level >= 1),
  experience INTEGER NOT NULL CHECK (experience >= 0),
  max_hp INTEGER NOT NULL CHECK (max_hp > 0),
  hp INTEGER NOT NULL CHECK (hp >= 0),
  max_sp INTEGER NOT NULL CHECK (max_sp > 0),
  sp INTEGER NOT NULL CHECK (sp >= 0),
  str INTEGER NOT NULL CHECK (str >= 0),
  intelligence INTEGER NOT NULL CHECK (intelligence >= 0),
  dex INTEGER NOT NULL CHECK (dex >= 0),
  spd INTEGER NOT NULL CHECK (spd >= 0),
  luk INTEGER NOT NULL CHECK (luk >= 0),
  unassigned_ap INTEGER NOT NULL DEFAULT 0 CHECK (unassigned_ap >= 0),
  unassigned_sp INTEGER NOT NULL DEFAULT 0 CHECK (unassigned_sp >= 0),
  position TEXT NOT NULL CHECK (position IN ('front', 'back')),
  guard_policy TEXT NOT NULL CHECK (guard_policy IN ('always', 'never')),
  created_at TEXT NOT NULL
) STRICT;
CREATE INDEX idx_characters_account ON characters(account_id);

-- 角色已学技能（S1 为初始技能快照；学习由 S2 开放）。
CREATE TABLE character_skills (
  character_id TEXT NOT NULL REFERENCES characters(id) ON DELETE CASCADE,
  position INTEGER NOT NULL CHECK (position >= 0),
  skill_id TEXT NOT NULL,
  PRIMARY KEY (character_id, position)
) STRICT;

-- 持有装备：每件独立身份（ADR-0002），同款不合并；S1 建队时穿戴，后续换装由 S2 开放。
CREATE TABLE owned_equipment (
  id TEXT PRIMARY KEY,
  account_id TEXT NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
  definition_id TEXT NOT NULL,
  slot TEXT NOT NULL,
  equipped_character_id TEXT REFERENCES characters(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL
) STRICT;
CREATE INDEX idx_equipment_account ON owned_equipment(account_id);
CREATE INDEX idx_equipment_character ON owned_equipment(equipped_character_id);

-- 角色默认战术（有序规则快照；编辑由 S2 开放）。
CREATE TABLE character_tactics (
  character_id TEXT NOT NULL REFERENCES characters(id) ON DELETE CASCADE,
  position INTEGER NOT NULL CHECK (position >= 0),
  skill_id TEXT NOT NULL,
  conditions_json TEXT NOT NULL,
  PRIMARY KEY (character_id, position)
) STRICT;

-- 首次建队请求的幂等身份：绑定账号，只存安全状态及原身份引用，
-- 不存名称明文以外的敏感内容（建队无凭据；名称为玩家公开意向，摘要覆盖规范化名称）。
CREATE TABLE first_party_requests (
  request_key TEXT PRIMARY KEY,
  param_digest TEXT NOT NULL,
  status TEXT NOT NULL CHECK (status IN ('completed')),
  account_id TEXT NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
  character_id TEXT REFERENCES characters(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL
) STRICT;
CREATE INDEX idx_first_party_account ON first_party_requests(account_id);
