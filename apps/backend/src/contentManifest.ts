import fs from "node:fs";

export interface ContentManifest {
  releaseId: string;
  schemaVersion: number;
}

/**
 * 读取并最小校验内容发布清单。基线阶段清单为占位（无玩法内容），
 * 真实 S1 首批内容包由后续任务按 content-release 契约发布；
 * 清单缺失或形状非法时拒绝启动（启动完整性核对）。
 */
export function loadContentManifest(manifestPath: string): ContentManifest {
  const raw = JSON.parse(fs.readFileSync(manifestPath, "utf8")) as Record<string, unknown>;
  if (typeof raw.releaseId !== "string" || raw.releaseId.length === 0) {
    throw new Error(`内容清单缺少 releaseId：${manifestPath}`);
  }
  if (typeof raw.schemaVersion !== "number" || !Number.isInteger(raw.schemaVersion) || raw.schemaVersion < 1) {
    throw new Error(`内容清单 schemaVersion 非法：${manifestPath}`);
  }
  return { releaseId: raw.releaseId, schemaVersion: raw.schemaVersion };
}
