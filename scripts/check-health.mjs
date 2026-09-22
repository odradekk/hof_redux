#!/usr/bin/env node
/**
 * 健康/版本契约检查：断言 /api/health 与 /api/version 的共享契约形状。
 * 用法：node scripts/check-health.mjs <base-url>
 */
const baseUrl = process.argv[2];
if (!baseUrl) {
  console.error("用法：node scripts/check-health.mjs <base-url>");
  process.exit(2);
}

function fail(message) {
  console.error(`契约检查失败：${message}`);
  process.exit(1);
}

async function getJson(path) {
  const response = await fetch(`${baseUrl}${path}`, { headers: { Accept: "application/json" } });
  if (!response.ok) fail(`${path} 返回 HTTP ${response.status}`);
  return response.json();
}

const health = await getJson("/api/health");
if (health.status !== "ok") fail(`/api/health status 不是 ok：${JSON.stringify(health)}`);
if (typeof health.now !== "string" || Number.isNaN(Date.parse(health.now))) {
  fail(`/api/health now 非法：${JSON.stringify(health.now)}`);
}
if (Object.keys(health).sort().join(",") !== "now,status") {
  fail(`/api/health 暴露了最小状态之外的字段：${JSON.stringify(health)}`);
}

const version = await getJson("/api/version");
if (typeof version.app?.name !== "string" || typeof version.app?.version !== "string") {
  fail(`/api/version app 字段非法：${JSON.stringify(version.app)}`);
}
if (typeof version.content?.releaseId !== "string" || version.content.releaseId.length === 0) {
  fail(`/api/version content.releaseId 非法：${JSON.stringify(version.content)}`);
}
if (!Number.isInteger(version.content?.schemaVersion) || version.content.schemaVersion < 1) {
  fail(`/api/version content.schemaVersion 非法：${JSON.stringify(version.content)}`);
}
if (!Number.isInteger(version.database?.schemaVersion) || version.database.schemaVersion < 1) {
  fail(`/api/version database.schemaVersion 非法：${JSON.stringify(version.database)}`);
}

console.log(
  JSON.stringify({
    ok: true,
    health: health.status,
    app: `${version.app.name}@${version.app.version}`,
    content: version.content.releaseId,
    databaseSchemaVersion: version.database.schemaVersion,
  }),
);
