import type { HealthResponse, VersionResponse } from "@hof/shared";

/**
 * 共享契约只描述类型；HTTP 边界的形状在运行期由这些轻量解码器核实，
 * 不把任意 JSON 直接断言成契约类型。
 */

async function getJson(path: string): Promise<unknown> {
  const response = await fetch(path, { headers: { Accept: "application/json" } });
  if (!response.ok) {
    throw new Error(`${path} 返回 ${response.status}`);
  }
  return response.json();
}

function asRecord(value: unknown, where: string): Record<string, unknown> {
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    throw new Error(`${where} 应为对象，实际：${JSON.stringify(value)}`);
  }
  return value as Record<string, unknown>;
}

function asString(value: unknown, where: string): string {
  if (typeof value !== "string" || value.length === 0) {
    throw new Error(`${where} 应为非空字符串，实际：${JSON.stringify(value)}`);
  }
  return value;
}

function asPositiveInt(value: unknown, where: string): number {
  if (typeof value !== "number" || !Number.isInteger(value) || value < 1) {
    throw new Error(`${where} 应为正整数，实际：${JSON.stringify(value)}`);
  }
  return value;
}

export function decodeHealth(value: unknown): HealthResponse {
  const record = asRecord(value, "/api/health");
  if (record.status !== "ok") {
    throw new Error(`/api/health status 应为 ok，实际：${JSON.stringify(record.status)}`);
  }
  return { status: "ok", now: asString(record.now, "/api/health now") };
}

export function decodeVersion(value: unknown): VersionResponse {
  const record = asRecord(value, "/api/version");
  const app = asRecord(record.app, "/api/version app");
  const content = asRecord(record.content, "/api/version content");
  const database = asRecord(record.database, "/api/version database");
  const contentHash = asString(content.contentHash, "/api/version content.contentHash");
  if (!contentHash.startsWith("sha256:")) {
    throw new Error(`/api/version content.contentHash 非法：${contentHash}`);
  }
  return {
    app: {
      name: asString(app.name, "/api/version app.name"),
      version: asString(app.version, "/api/version app.version"),
    },
    content: {
      releaseId: asString(content.releaseId, "/api/version content.releaseId"),
      schemaVersion: asPositiveInt(content.schemaVersion, "/api/version content.schemaVersion"),
      contentHash,
    },
    database: {
      schemaVersion: asPositiveInt(database.schemaVersion, "/api/version database.schemaVersion"),
    },
  };
}

export async function fetchHealth(): Promise<HealthResponse> {
  return decodeHealth(await getJson("/api/health"));
}

export async function fetchVersion(): Promise<VersionResponse> {
  return decodeVersion(await getJson("/api/version"));
}
