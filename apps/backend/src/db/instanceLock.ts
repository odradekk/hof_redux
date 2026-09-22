import { randomUUID } from "node:crypto";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";

interface LockPayload {
  /** 持有者唯一令牌：释放时的所有权凭证。 */
  token: string;
  /** 以下字段仅供人工诊断，不参与任何判断。 */
  pid: number;
  hostname: string;
  startedAt: string;
  dbPath: string;
}

/**
 * 启动互斥：同一数据库旁的锁文件只允许一个后端实例同时调度（运行契约：
 * 第二实例不得误启动）。应用内不提供接管——运行契约并不要求它，而任何
 * “先移除旧锁再装新锁”的做法都存在锁位空窗，会让并发启动者有机可乘。
 *
 * - 原子竞争（线性化点 = link）：候选文件完整写好后以 link 安装到锁位，
 *   仅当锁位不存在时成功；N 个并发启动者恰有一个成功，其余拒绝。
 * - 所有权安全（线性化点 = rename）：release 先把锁位改名到自己独有的
 *   临时名再核对 token，只删除确属自己的那份锁。即使持有者曾被误判死亡、
 *   锁文件被操作者清理并交给新实例，旧持有者的 release 也不会删掉新锁。
 * - 任何已存在的锁（含崩溃残留、旧版无 token 的锁）一律拒绝启动。恢复是
 *   人工步骤：确认旧实例确已停止后，删除 <dbPath>.instance.lock 再启动。
 */
export class InstanceLock {
  readonly ownerToken = randomUUID();

  private readonly lockPath: string;
  private readonly dbPath: string;
  private held = false;

  constructor(lockPath: string, dbPath: string) {
    this.lockPath = lockPath;
    this.dbPath = dbPath;
  }

  acquire(): void {
    fs.mkdirSync(path.dirname(this.lockPath), { recursive: true });
    // 候选文件先完整写好再 link：锁位上的文件一旦出现就一定是完整内容。
    const candidate = `${this.lockPath}.candidate-${process.pid}-${this.ownerToken}`;
    try {
      fs.writeFileSync(candidate, this.payload());
      try {
        fs.linkSync(candidate, this.lockPath);
        this.held = true;
      } catch (err) {
        if ((err as NodeJS.ErrnoException).code !== "EEXIST") throw err;
        throw new Error(this.conflictMessage());
      }
    } finally {
      fs.rmSync(candidate, { force: true });
    }
  }

  release(): void {
    if (!this.held) return;
    this.held = false;
    const removed = `${this.lockPath}.release-${process.pid}-${this.ownerToken}`;
    try {
      fs.renameSync(this.lockPath, removed);
    } catch (err) {
      if ((err as NodeJS.ErrnoException).code === "ENOENT") return;
      throw err;
    }
    if (this.readPayloadAt(removed)?.token === this.ownerToken) {
      fs.rmSync(removed, { force: true });
      return;
    }
    // 改名拿到的已不是自己的锁（被判定死亡后操作者已清理并交给新实例）：原样归还。
    try {
      fs.linkSync(removed, this.lockPath);
    } catch (err) {
      if ((err as NodeJS.ErrnoException).code !== "EEXIST") throw err;
    }
    fs.rmSync(removed, { force: true });
  }

  private conflictMessage(): string {
    const existing = this.readPayloadAt(this.lockPath);
    const held = existing
      ? JSON.stringify(existing)
      : fs.existsSync(this.lockPath)
        ? `无法解析（原始内容：${fs.readFileSync(this.lockPath, "utf8").trim().slice(0, 200)}）`
        : "（内容为空或不可读）";
    return (
      `检测到另一后端实例锁：${this.lockPath}（${held}）。` +
      "任何已存在的锁都会拒绝启动。若确认旧实例已停止（这是崩溃残留），" +
      `请删除锁文件后重启：rm '${this.lockPath}'`
    );
  }

  private payload(): string {
    const payload: LockPayload = {
      token: this.ownerToken,
      pid: process.pid,
      hostname: os.hostname(),
      startedAt: new Date().toISOString(),
      dbPath: this.dbPath,
    };
    return `${JSON.stringify(payload)}\n`;
  }

  /** 读取锁文件内容；旧版锁没有 token，null 表示不存在或不可解析。 */
  private readPayloadAt(file: string): Partial<LockPayload> | null {
    try {
      return JSON.parse(fs.readFileSync(file, "utf8")) as Partial<LockPayload>;
    } catch {
      return null;
    }
  }
}
