import fs from "node:fs";

/**
 * 锁竞争子进程：就绪后在发令文件出现时尝试获取锁（父进程用它构造真实并发竞争）。
 * 退出码：0 = 获取成功；3 = 被拒绝；4 = 意外错误（stderr 有详情）。
 * 环境变量：LOCK_PATH 锁文件路径；READY_DIR 就绪标记目录；GATE 发令文件路径。
 */
const lockPath = process.env.LOCK_PATH;
const gate = process.env.GATE;

if (!lockPath || !gate || !process.env.READY_DIR) {
  console.error("缺少 LOCK_PATH / READY_DIR / GATE 环境变量");
  process.exit(4);
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// 报告就绪，然后等发令文件，让所有子进程几乎同时竞争。
import path from "node:path";
fs.writeFileSync(path.join(process.env.READY_DIR, `ready-${process.pid}`), "");
while (!fs.existsSync(gate)) {
  await sleep(1);
}

const { InstanceLock } = await import("../src/db/instanceLock.js");
try {
  const lock = new InstanceLock(lockPath, lockPath.replace(/\.instance\.lock$/, ""));
  lock.acquire();
  process.stdout.write(`${lock.ownerToken}\n`);
  await sleep(50);
  process.exit(0);
} catch (err) {
  const message = err instanceof Error ? err.message : String(err);
  if (/实例锁/.test(message)) {
    process.exit(3);
  }
  console.error(message);
  process.exit(4);
}
