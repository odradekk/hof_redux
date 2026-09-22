import type { HealthResponse, VersionResponse } from "@hof/shared";

async function getJson<T>(path: string): Promise<T> {
  const response = await fetch(path, { headers: { Accept: "application/json" } });
  if (!response.ok) {
    throw new Error(`${path} 返回 ${response.status}`);
  }
  return (await response.json()) as T;
}

export function fetchHealth(): Promise<HealthResponse> {
  return getJson<HealthResponse>("/api/health");
}

export function fetchVersion(): Promise<VersionResponse> {
  return getJson<VersionResponse>("/api/version");
}
