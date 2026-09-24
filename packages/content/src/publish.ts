import fs from "node:fs";
import path from "node:path";

/** 安装完整快照后原子切换指针；已有快照只核验，失败不会覆盖旧发布。 */
export function publishSnapshot(contentDir: string, releaseId: string, files: ReadonlyMap<string, Buffer>): void {
  const releasesDir = path.join(contentDir, "releases");
  fs.mkdirSync(releasesDir, { recursive: true });
  // 与目标位于同一文件系统，保证目录和指针的 rename 不跨设备。
  const stageDir = fs.mkdtempSync(path.join(contentDir, ".release-"));
  const stagedSnapshot = path.join(stageDir, "snapshot");
  const snapshotDir = path.join(releasesDir, releaseId);
  try {
    for (const [relativePath, data] of files) {
      const target = path.join(stagedSnapshot, relativePath);
      fs.mkdirSync(path.dirname(target), { recursive: true });
      fs.writeFileSync(target, data);
    }
    if (fs.existsSync(snapshotDir)) {
      for (const [relativePath, data] of files) {
        const existing = path.join(snapshotDir, relativePath);
        if (!fs.existsSync(existing) || !fs.readFileSync(existing).equals(data)) {
          throw new Error(`快照 ${releaseId} 已存在但内容不一致（不可变快照不得原地改写）：${relativePath}`);
        }
      }
    } else {
      fs.renameSync(stagedSnapshot, snapshotDir);
    }

    // 即使快照已存在也切换指针，支持重建旧版本和安装后中断的重试。
    const stagedPointer = path.join(stageDir, "current-release");
    fs.writeFileSync(stagedPointer, releaseId + "\n");
    fs.renameSync(stagedPointer, path.join(contentDir, "current-release"));
  } finally {
    fs.rmSync(stageDir, { recursive: true, force: true });
  }
}
