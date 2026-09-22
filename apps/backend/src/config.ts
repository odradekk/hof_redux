import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

export interface AppConfig {
  host: string;
  port: number;
  /** SQLite 数据库文件路径（整个数据库目录必须位于本机持久磁盘）。必填。 */
  dbPath: string;
  migrationsDir: string;
  contentManifestPath: string;
  /** 仅当操作者显式确认接管遗留实例锁时为 true（HOF_LOCK_TAKEOVER=1）。 */
  lockTakeover: boolean;
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

  const contentManifestPath =
    env.HOF_CONTENT_MANIFEST ??
    firstExisting([
      path.resolve(process.cwd(), "content/release.json"),
      path.resolve(process.cwd(), "../../content/release.json"),
    ]) ??
    (() => {
      throw new Error("找不到内容发布清单，请设置 HOF_CONTENT_MANIFEST");
    })();

  return {
    host: env.HOF_HOST ?? "0.0.0.0",
    port: Number(env.HOF_PORT ?? "3000"),
    dbPath: path.resolve(dbPath),
    migrationsDir,
    contentManifestPath,
    lockTakeover: env.HOF_LOCK_TAKEOVER === "1",
    trustProxy: env.HOF_TRUST_PROXY === "true",
    appVersion: pkg.version,
  };
}
