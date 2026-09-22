import fs from "node:fs";
import os from "node:os";

interface LockPayload {
  pid: number;
  hostname: string;
  startedAt: string;
  dbPath: string;
}

/**
 * 启动互斥：阻止误启动的第二实例同时调度同一数据库（运行契约）。
 * - 锁文件与数据库同目录，随库走；正常关闭时释放。
 * - 同主机名且记录进程已死：判定为崩溃残留，自动回收。
 * - 其他情况（锁属活跃进程，或无法核验的异主机名锁）：拒绝启动，
 *   由操作者确认后删除锁文件或显式 HOF_LOCK_TAKEOVER=1 接管。
 */
export class InstanceLock {
  private fd: number | null = null;

  constructor(
    private readonly lockPath: string,
    private readonly dbPath: string,
  ) {}

  acquire(options: { takeover: boolean }): void {
    try {
      this.fd = fs.openSync(this.lockPath, "wx");
      fs.writeFileSync(this.fd, this.payload());
      return;
    } catch (err) {
      if ((err as NodeJS.ErrnoException).code !== "EEXIST") throw err;
    }

    const existing = this.readExisting();
    const sameHost = existing?.hostname === os.hostname();
    let recordedProcessAlive = false;
    if (sameHost && typeof existing.pid === "number") {
      try {
        process.kill(existing.pid, 0);
        recordedProcessAlive = true;
      } catch {
        recordedProcessAlive = false;
      }
    }

    const reclaimable = (sameHost && !recordedProcessAlive) || options.takeover;
    if (!reclaimable) {
      throw new Error(
        `检测到另一后端实例锁：${this.lockPath}（${JSON.stringify(existing)}）。` +
          "确认旧实例已停止后删除该锁文件，或以 HOF_LOCK_TAKEOVER=1 显式接管。",
      );
    }

    this.fd = fs.openSync(this.lockPath, "w");
    fs.writeFileSync(this.fd, this.payload());
  }

  release(): void {
    if (this.fd !== null) {
      try {
        fs.closeSync(this.fd);
      } catch {
        // 忽略：释放锁尽最大努力
      }
      this.fd = null;
    }
    try {
      fs.rmSync(this.lockPath, { force: true });
    } catch {
      // 忽略：释放锁尽最大努力
    }
  }

  private payload(): string {
    const payload: LockPayload = {
      pid: process.pid,
      hostname: os.hostname(),
      startedAt: new Date().toISOString(),
      dbPath: this.dbPath,
    };
    return JSON.stringify(payload) + "\n";
  }

  private readExisting(): LockPayload | null {
    try {
      return JSON.parse(fs.readFileSync(this.lockPath, "utf8")) as LockPayload;
    } catch {
      return null;
    }
  }
}
