import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";

export interface ContentManifest {
  releaseId: string;
  schemaVersion: number;
  contentHash: string;
  fileCount: number;
}

/** 后端当前支持的内容版本组合；快照声明超出此集合即拒绝启动（不 Wagen 兼容）。 */
export const SUPPORTED_CONTENT = {
  schemaVersion: 1,
  engine: "s1-engine-0",
  randomProtocol: "s1-random-0",
  eventFormat: "s1-event-0",
} as const;

interface ReleaseFileEntry {
  path: string;
  sha256: string;
  bytes: number;
}

/**
 * 读取并校验内容发布快照（S1 首批内容包，#23）。
 * - 只接受快照布局 content/releases/<releaseId>/release.json，且 releaseId 与目录名一致。
 * - files 须排序、无重复；逐文件核对存在性、字节数与 SHA-256（大小写精确路径）。
 * - 按清单重算 contentHash，不符拒绝启动。
 * - 门控：schemaVersion / engine / randomProtocol / eventFormat 须为当前支持值；
 *   compatibleDbSchema 须等于实际迁移版本。基线占位清单（无 contentHash / files 为空）拒绝。
 * 失败一律抛错，由 main 拒绝启动。
 */
export function loadContentManifest(manifestPath: string, opts?: { expectedDbSchema?: number }): ContentManifest {
  const abs = path.resolve(manifestPath);
  const raw = JSON.parse(fs.readFileSync(abs, "utf8")) as Record<string, unknown>;
  if (typeof raw.releaseId !== "string" || raw.releaseId.length === 0) {
    throw new Error(`内容清单缺少 releaseId：${manifestPath}`);
  }
  const snapshotDir = path.dirname(abs);
  if (path.basename(snapshotDir) !== raw.releaseId || path.basename(path.dirname(snapshotDir)) !== "releases") {
    throw new Error(`内容清单须位于快照目录 content/releases/<releaseId>/：${manifestPath}`);
  }
  if (raw.schemaVersion !== SUPPORTED_CONTENT.schemaVersion) {
    throw new Error(`内容 schemaVersion 不受支持：${String(raw.schemaVersion)}（仅支持 ${SUPPORTED_CONTENT.schemaVersion}）`);
  }
  for (const key of ["engine", "randomProtocol", "eventFormat"] as const) {
    if (raw[key] !== SUPPORTED_CONTENT[key]) {
      throw new Error(`内容 ${key} 不受支持：${String(raw[key])}（仅支持 ${SUPPORTED_CONTENT[key]}）`);
    }
  }
  if (typeof raw.contentHash !== "string" || !raw.contentHash.startsWith("sha256:")) {
    throw new Error(`内容清单缺少 contentHash：${manifestPath}`);
  }
  if (opts?.expectedDbSchema !== undefined && raw.compatibleDbSchema !== opts.expectedDbSchema) {
    throw new Error(
      `内容 compatibleDbSchema（${String(raw.compatibleDbSchema)}）与实际数据库版本（${opts.expectedDbSchema}）不一致，拒绝启动`,
    );
  }
  const files = raw.files as unknown;
  if (!Array.isArray(files) || files.length === 0) {
    throw new Error(`内容清单 files 为空（基线占位清单已失效，须发布 S1 真实内容快照）：${manifestPath}`);
  }

  const repoRoot = path.dirname(path.dirname(path.dirname(snapshotDir)));
  const seen = new Set<string>();
  let prev = "";
  for (const entry of files as ReleaseFileEntry[]) {
    if (typeof entry?.path !== "string" || typeof entry?.sha256 !== "string" || typeof entry?.bytes !== "number") {
      throw new Error(`内容清单 files 条目形状非法：${manifestPath}`);
    }
    if (!entry.path.startsWith(`content/releases/${raw.releaseId as string}/`)) {
      throw new Error(`内容文件须位于本快照目录内：${entry.path}`);
    }
    if (seen.has(entry.path)) {
      throw new Error(`内容清单 files 重复：${entry.path}`);
    }
    seen.add(entry.path);
    if (entry.path < prev) {
      throw new Error(`内容清单 files 未排序：${entry.path}`);
    }
    prev = entry.path;
    const fileAbs = path.join(repoRoot, entry.path);
    if (!fs.existsSync(fileAbs)) {
      throw new Error(`内容文件缺失：${entry.path}`);
    }
    const data = fs.readFileSync(fileAbs);
    if (data.length !== entry.bytes) {
      throw new Error(`内容文件字节数不符：${entry.path}`);
    }
    if (crypto.createHash("sha256").update(data).digest("hex") !== entry.sha256) {
      throw new Error(`内容文件摘要不符：${entry.path}`);
    }
  }

  const hashInput = (files as ReleaseFileEntry[]).map((f) => `${f.path}:${f.sha256}`).join("\n") + "\n";
  const recomputed = `sha256:${crypto.createHash("sha256").update(hashInput).digest("hex")}`;
  if (recomputed !== raw.contentHash) {
    throw new Error(`内容摘要 contentHash 不符（清单 ${raw.contentHash}，重算 ${recomputed}）`);
  }

  return {
    releaseId: raw.releaseId,
    schemaVersion: raw.schemaVersion,
    contentHash: raw.contentHash as string,
    fileCount: (files as unknown[]).length,
  };
}

/**
 * 默认清单定位：经 content/current-release 指针找到当前快照。
 * 保留 HOF_CONTENT_MANIFEST 显式指向的优先权由 config 处理。
 */
export function resolveSnapshotManifest(contentDir: string): string {
  const pointer = path.join(contentDir, "current-release");
  const releaseId = fs.readFileSync(pointer, "utf8").trim();
  if (!/^s1-[0-9a-f]{12}$/.test(releaseId)) {
    throw new Error(`当前发布指针非法：${pointer}`);
  }
  return path.join(contentDir, "releases", releaseId, "release.json");
}
