import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { resolveSnapshotManifest } from "./contentManifest.js";

export interface AppConfig {
  host: string;
  port: number;
  /** SQLite 数据库文件路径（整个数据库目录必须位于本机持久磁盘）。必填。 */
  dbPath: string;
  migrationsDir: string;
  contentManifestPath: string;
  /** 仅在容器内网、入口为受信 Caddy 时为 true。 */
  trustProxy: boolean;
  appVersion: string;
}

function firstExisting(candidates: string[]): string | undefined {
  return candidates.find((p) => fs.existsSync(p));
}

export function loadConfig(env: NodeJS.ProcessEnv = process.env): AppConfig {
  const dbPath = env.HOF_DB_PATH;
  if (!dbPath) {
    throw new Error("缺少必填配置 HOF_DB_PATH（SQLite 数据库文件路径）");
  }

  const pkgUrl = new URL("../package.json", import.meta.url);
  const pkg = JSON.parse(fs.readFileSync(pkgUrl, "utf8")) as { version: string };

  const migrationsDir =
    env.HOF_MIGRATIONS_DIR ??
    firstExisting([
      path.resolve(process.cwd(), "migrations"),
      fileURLToPath(new URL("../migrations", import.meta.url)),
    ]) ??
    (() => {
      throw new Error("找不到迁移目录，请设置 HOF_MIGRATIONS_DIR");
    })();

  // 内容清单默认经 current-release 指针定位当前不可变快照；
  // HOF_CONTENT_MANIFEST 显式指向仍优先（测试与恢复演练用）。
  const contentManifestPath =
    env.HOF_CONTENT_MANIFEST ??
    (() => {
      const candidates = [
        path.resolve(process.cwd(), "content"),
        path.resolve(process.cwd(), "../../content"),
        path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..", "..", "content"),
      ];
      const dir = firstExisting(candidates);
      if (!dir) {
        throw new Error("找不到内容目录，请设置 HOF_CONTENT_MANIFEST");
      }
      return resolveSnapshotManifest(dir);
    })();

  return {
    host: env.HOF_HOST ?? "0.0.0.0",
    port: Number(env.HOF_PORT ?? "3000"),
    dbPath: path.resolve(dbPath),
    migrationsDir,
    contentManifestPath,
    trustProxy: env.HOF_TRUST_PROXY === "true",
    appVersion: pkg.version,
  };
}
