import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { spawn } from "node:child_process";
import { fileURLToPath } from "node:url";
import { InstanceLock } from "../src/db/instanceLock.js";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const BACKEND_DIR = path.resolve(HERE, "..");

interface LockPayload {
  token?: string;
  pid?: number;
  hostname?: string;
  startedAt?: string;
  dbPath?: string;
}

function newLockPath(): string {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-lock-"));
  return path.join(dir, "hof.sqlite.instance.lock");
}

function readPayload(lockPath: string): LockPayload {
  return JSON.parse(fs.readFileSync(lockPath, "utf8")) as LockPayload;
}

function acquire(lockPath: string): InstanceLock {
  const lock = new InstanceLock(lockPath, lockPath.replace(/\.instance\.lock$/, ""));
  lock.acquire();
  return lock;
}

interface ChildResult {
  code: number;
  token: string | null;
}

/** 同时起 N 个子进程竞争同一把锁：全员就绪后发令，收集退出码与赢家 token。 */
async function raceChildren(lockPath: string, count: number): Promise<ChildResult[]> {
  const work = `${lockPath}.race-${Date.now()}-${Math.random().toString(36).slice(2)}`;
  fs.mkdirSync(work);
  const gate = path.join(work, "go");
  const results: ChildResult[] = [];
  const done: Promise<void>[] = [];

  for (let i = 0; i < count; i++) {
    const child = spawn(process.execPath, ["--import", "tsx", path.join(HERE, "lockChild.ts")], {
      cwd: BACKEND_DIR,
      env: { ...process.env, LOCK_PATH: lockPath, READY_DIR: work, GATE: gate },
      stdio: ["ignore", "pipe", "pipe"],
    });
    let token: string | null = null;
    child.stdout.on("data", (chunk: Buffer) => {
      const first = chunk.toString().split("\n", 1)[0];
      if (first) token = first;
    });
    done.push(
      new Promise<void>((resolve) => {
        child.on("close", (code) => {
          results.push({ code: code ?? -1, token });
          resolve();
        });
      }),
    );
  }

  // 等全部子进程就位后再发令，确保竞争真实同时发生。
  const deadline = Date.now() + 30_000;
  while (fs.readdirSync(work).filter((f) => f.startsWith("ready-")).length < count) {
    assert.ok(Date.now() < deadline, "子进程未能在期限内就绪");
    await new Promise((resolve) => setTimeout(resolve, 10));
  }
  fs.writeFileSync(gate, "go\n");
  await Promise.all(done);
  fs.rmSync(work, { recursive: true, force: true });
  return results;
}

test("首个实例获取锁，锁文件记录持有者信息", () => {
  const lockPath = newLockPath();
  const lock = acquire(lockPath);
  const payload = readPayload(lockPath);
  assert.equal(payload.pid, process.pid);
  assert.equal(payload.hostname, os.hostname());
  assert.ok(payload.token);
  lock.release();
  assert.equal(fs.existsSync(lockPath), false);
});

test("已有锁时第二实例被拒绝，且不改写现有锁", () => {
  const lockPath = newLockPath();
  const first = acquire(lockPath);
  const before = fs.readFileSync(lockPath, "utf8");
  assert.throws(() => acquire(lockPath), /实例锁/);
  assert.equal(fs.readFileSync(lockPath, "utf8"), before);
  first.release();
});

test("锁释放后可被重新获取", () => {
  const lockPath = newLockPath();
  acquire(lockPath).release();
  const second = acquire(lockPath);
  assert.ok(fs.existsSync(lockPath));
  second.release();
});

test("并发竞争同一数据库时恰有一个赢家", async () => {
  const lockPath = newLockPath();
  const results = await raceChildren(lockPath, 8);
  const winners = results.filter((r) => r.code === 0);
  const refused = results.filter((r) => r.code === 3);
  assert.deepEqual(
    results.map((r) => r.code).sort(),
    [0, 3, 3, 3, 3, 3, 3, 3],
    `应恰有一个赢家，实际退出码：${JSON.stringify(results.map((r) => r.code))}`,
  );
  assert.equal(refused.length, 7);
  // 锁文件属于赢家本人，且没有遗留任何中间文件。
  assert.equal(readPayload(lockPath).token, winners[0].token);
  assert.deepEqual(fs.readdirSync(path.dirname(lockPath)), [path.basename(lockPath)]);
});

test("崩溃残留（含旧版无 token 锁与不可解析内容）一律拒绝，错误信息含恢复步骤", () => {
  const legacy = newLockPath();
  fs.writeFileSync(legacy, `${JSON.stringify({ pid: 1, hostname: "gone", startedAt: "2026-01-01T00:00:00Z" })}\n`);
  let legacyError: Error | undefined;
  try {
    acquire(legacy);
  } catch (err) {
    legacyError = err as Error;
  }
  assert.ok(legacyError, "旧版锁应被拒绝");
  assert.match(legacyError.message, /实例锁/);
  assert.match(legacyError.message, /rm/);

  const garbage = newLockPath();
  fs.writeFileSync(garbage, "not-json\n");
  assert.throws(() => acquire(garbage), /实例锁/);

  const empty = newLockPath();
  fs.writeFileSync(empty, "");
  assert.throws(() => acquire(empty), /实例锁/);
});

test("持有者被误判死亡且锁已交给新实例时，旧持有者的释放不删新锁", () => {
  const lockPath = newLockPath();
  const stale = acquire(lockPath);
  // 模拟人工恢复：操作者确认旧实例停止后删除残留锁，新实例随即取得锁位。
  fs.rmSync(lockPath);
  const current = acquire(lockPath);
  const currentToken = readPayload(lockPath).token;
  stale.release();
  // 新实例的锁必须原样保留，且没有遗留中间文件。
  assert.equal(readPayload(lockPath).token, currentToken);
  assert.deepEqual(fs.readdirSync(path.dirname(lockPath)), [path.basename(lockPath)]);
  current.release();
  assert.equal(fs.existsSync(lockPath), false);
});

test("未持有时调用 release 是无害的", () => {
  const lockPath = newLockPath();
  const lock = new InstanceLock(lockPath, lockPath.replace(/\.instance\.lock$/, ""));
  lock.release();
  assert.equal(fs.existsSync(lockPath), false);
});
