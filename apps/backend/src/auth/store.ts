import type { DatabaseSync } from "node:sqlite";
import { ACCOUNT_CONTRACT } from "@hof/shared";
import { newId, sha256Hex } from "./tokens.js";

export interface AccountRow {
  id: string;
  login_name: string;
  login_key: string;
  password_hash: string;
  recovery_hash: string;
  recovery_generation: number;
  team_completed: number;
  created_at: string;
  deleted_at: string | null;
}

export interface SessionRow {
  id: string;
  token_hash: string;
  account_id: string;
  scope: string;
  created_at: string;
  last_activity_at: string;
}

const INITIAL_MONEY = Number(ACCOUNT_CONTRACT.initialMoney);
const INITIAL_STAMINA = ACCOUNT_CONTRACT.initialStamina;

export function getRecoveryEpoch(db: DatabaseSync): number {
  const row = db.prepare("SELECT value FROM app_meta WHERE key = 'recovery_epoch'").get() as unknown as
    | { value: string }
    | undefined;
  return Number(row?.value ?? "1");
}

export function getAccountByKey(db: DatabaseSync, loginKey: string): AccountRow | undefined {
  return db.prepare("SELECT * FROM accounts WHERE login_key = ?").get(loginKey) as unknown as AccountRow | undefined;
}

export function getAccountWithAssets(
  db: DatabaseSync,
  accountId: string,
): (AccountRow & { money: number; stamina: number }) | undefined {
  const row = db
    .prepare(
      `SELECT a.*, w.money AS money, w.stamina AS stamina
       FROM accounts a JOIN account_assets w ON w.account_id = a.id
       WHERE a.id = ?`,
    )
    .get(accountId) as unknown as ((AccountRow & { money: number; stamina: number }) | undefined);
  return row;
}

function withTransaction<T>(db: DatabaseSync, fn: () => T): T {
  db.exec("BEGIN IMMEDIATE");
  try {
    const result = fn();
    db.exec("COMMIT");
    return result;
  } catch (err) {
    try {
      db.exec("ROLLBACK");
    } catch {
      // 回滚失败时保留原错误。
    }
    throw err;
  }
}

export interface RegisterResult {
  accountId: string;
  loginName: string;
  createdAt: string;
  replayed: boolean;
}

/**
 * 注册事务：容量、名称争用、账号、资产、幂等记录、流水一次提交。
 * 密码哈希与随机量在事务外生成；事务内复核请求身份与名称占用。
 * 幂等摘要仅覆盖 login_key（密码/恢复码永不进入幂等缓存，见账号契约）。
 */
export function registerAccount(
  db: DatabaseSync,
  opts: {
    loginName: string;
    passwordHash: string;
    recoveryCode: string;
    requestId: string;
    maxUsers: number;
    releaseId: string;
  },
): RegisterResult {
  const loginKey = opts.loginName.toLowerCase();
  const requestKey = `register:${opts.requestId}`;
  const paramDigest = sha256Hex(`register\n${loginKey}`);
  const recoveryHash = sha256Hex(opts.recoveryCode);
  const now = new Date().toISOString();

  return withTransaction(db, () => {
    // 同一请求身份重复提交：参数一致返回原结果，换参数拒绝。
    const existing = db.prepare("SELECT * FROM register_requests WHERE request_key = ?").get(requestKey) as unknown as
      | { param_digest: string; account_id: string }
      | undefined;
    if (existing) {
      if (existing.param_digest !== paramDigest) {
        const err = new Error("同一请求身份不得更换参数");
        (err as NodeJS.ErrnoException).code = "REQUEST_CONFLICT";
        throw err;
      }
      const account = db.prepare("SELECT * FROM accounts WHERE id = ?").get(existing.account_id) as unknown as
        | AccountRow
        | undefined;
      if (!account) {
        const err = new Error("原操作记录异常");
        (err as NodeJS.ErrnoException).code = "REQUEST_CONFLICT";
        throw err;
      }
      return { accountId: account.id, loginName: account.login_name, createdAt: account.created_at, replayed: true };
    }

    // 注册容量：计入已创建且未删除的玩家账号（管理员独立空间，不计入）。
    const count = (db.prepare("SELECT COUNT(*) AS n FROM accounts WHERE deleted_at IS NULL").get() as unknown as { n: number }).n;
    if (count >= opts.maxUsers) {
      const err = new Error("注册容量已满");
      (err as NodeJS.ErrnoException).code = "CAPACITY_FULL";
      throw err;
    }

    const accountId = newId();
    try {
      db.prepare(
        `INSERT INTO accounts (id, login_name, login_key, password_hash, recovery_hash, recovery_generation, team_completed, created_at, deleted_at)
         VALUES (?, ?, ?, ?, ?, 1, 0, ?, NULL)`,
      ).run(accountId, opts.loginName, loginKey, opts.passwordHash, recoveryHash, now);
    } catch (err) {
      if (isUniqueViolation(err)) {
        const conflict = new Error("登录名已被占用");
        (conflict as NodeJS.ErrnoException).code = "LOGIN_TAKEN";
        throw conflict;
      }
      throw err;
    }

    db.prepare(`INSERT INTO account_assets (account_id, money, stamina, stamina_updated_at) VALUES (?, ?, ?, ?)`).run(
      accountId,
      INITIAL_MONEY,
      INITIAL_STAMINA,
      now,
    );
    db.prepare(`INSERT INTO register_requests (request_key, param_digest, status, account_id, created_at) VALUES (?, ?, 'completed', ?, ?)`).run(
      requestKey,
      paramDigest,
      accountId,
      now,
    );
    db.prepare(
      `INSERT INTO game_change_records (id, account_id, kind, money_delta, stamina_delta, reason, request_key, created_at)
       VALUES (?, ?, 'register_grant', ?, ?, ?, ?, ?)`,
    ).run(newId(), accountId, INITIAL_MONEY, INITIAL_STAMINA, "注册初始发放 10,000 金钱与 100 体力", requestKey, now);

    return { accountId, loginName: opts.loginName, createdAt: now, replayed: false };
  });
}

/** 登录：事务内复核身份后签发会话（密码校验在事务外完成）。 */
export function createSession(db: DatabaseSync, accountId: string, token: string): { sessionId: string; createdAt: string } {
  const tokenHash = sha256Hex(token);
  const now = new Date().toISOString();
  return withTransaction(db, () => {
    const account = db.prepare("SELECT id FROM accounts WHERE id = ? AND deleted_at IS NULL").get(accountId) as unknown as
      | { id: string }
      | undefined;
    if (!account) {
      const err = new Error("账号不存在");
      (err as NodeJS.ErrnoException).code = "INVALID_CREDENTIALS";
      throw err;
    }
    const sessionId = newId();
    db.prepare(
      `INSERT INTO sessions (id, token_hash, account_id, scope, created_at, last_activity_at) VALUES (?, ?, ?, 'player', ?, ?)`,
    ).run(sessionId, tokenHash, accountId, now, now);
    return { sessionId, createdAt: now };
  });
}

export type SessionCheck = { ok: true; session: SessionRow } | { ok: false; reason: "missing" | "expired" };

/** 会话校验：闲置 7 天、绝对 30 天任一到达即失效（含等于边界）。 */
export function checkSession(db: DatabaseSync, token: string, nowMs: number): SessionCheck {
  const tokenHash = sha256Hex(token);
  const row = db.prepare("SELECT * FROM sessions WHERE token_hash = ?").get(tokenHash) as unknown as SessionRow | undefined;
  if (!row) return { ok: false, reason: "missing" };
  const created = Date.parse(row.created_at);
  const activity = Date.parse(row.last_activity_at);
  if (
    nowMs - activity >= ACCOUNT_CONTRACT.sessionIdleMs ||
    nowMs - created >= ACCOUNT_CONTRACT.sessionAbsoluteMs
  ) {
    // 惰性清理过期会话（短事务，不影响已受理业务）。
    try {
      db.prepare("DELETE FROM sessions WHERE id = ?").run(row.id);
    } catch {
      // 清理失败不改变“已失效”的判定。
    }
    return { ok: false, reason: "expired" };
  }
  return { ok: true, session: row };
}

/** 退出当前会话：幂等，无记录时仍返回成功（重复执行无额外作用）。 */
export function revokeSession(db: DatabaseSync, token: string): void {
  const tokenHash = sha256Hex(token);
  db.prepare("DELETE FROM sessions WHERE token_hash = ?").run(tokenHash);
}

function isUniqueViolation(err: unknown): boolean {
  const message = err instanceof Error ? err.message : String(err);
  const code = (err as NodeJS.ErrnoException)?.code;
  return code === "ERR_SQLITE_CONSTRAINT_UNIQUE" || /UNIQUE constraint failed/i.test(message);
}
