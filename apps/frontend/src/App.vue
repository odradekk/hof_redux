<script setup lang="ts">
import { onMounted, ref } from "vue";
import type { HealthResponse, VersionResponse } from "@hof/shared";
import { fetchHealth, fetchVersion } from "./api";

const health = ref<HealthResponse | null>(null);
const version = ref<VersionResponse | null>(null);
const loadError = ref<string | null>(null);
const loading = ref(false);

async function refresh() {
  loading.value = true;
  loadError.value = null;
  try {
    // 版本与健康分别请求：版本端点失败不应掩盖健康状态本身。
    const [healthResult, versionResult] = await Promise.allSettled([fetchHealth(), fetchVersion()]);
    health.value = healthResult.status === "fulfilled" ? healthResult.value : null;
    version.value = versionResult.status === "fulfilled" ? versionResult.value : null;
    if (healthResult.status === "rejected" || versionResult.status === "rejected") {
      const reason = [healthResult, versionResult]
        .filter((r): r is PromiseRejectedResult => r.status === "rejected")
        .map((r) => String(r.reason))
        .join("；");
      loadError.value = `部分状态读取失败：${reason}`;
    }
  } finally {
    loading.value = false;
  }
}

onMounted(refresh);
</script>

<template>
  <main class="page">
    <header class="masthead">
      <h1>HOF Redux</h1>
      <p class="subtitle">S1 受控测试 · 工程基线</p>
    </header>

    <section class="card" aria-label="系统状态">
      <h2>系统状态</h2>
      <dl class="status-grid">
        <div>
          <dt>健康状态</dt>
          <dd v-if="health" class="ok">正常（{{ health.now }}）</dd>
          <dd v-else class="bad">不可用</dd>
        </div>
        <div>
          <dt>应用版本</dt>
          <dd v-if="version">{{ version.app.name }}@{{ version.app.version }}</dd>
          <dd v-else class="bad">未知</dd>
        </div>
        <div>
          <dt>内容版本</dt>
          <dd v-if="version">{{ version.content.releaseId }}（schema v{{ version.content.schemaVersion }}）</dd>
          <dd v-else class="bad">未知</dd>
        </div>
        <div>
          <dt>数据库结构版本</dt>
          <dd v-if="version">迁移 v{{ version.database.schemaVersion }}</dd>
          <dd v-else class="bad">未知</dd>
        </div>
      </dl>
      <p v-if="loadError" class="bad" role="alert">{{ loadError }}</p>
      <button type="button" :disabled="loading" @click="refresh">
        {{ loading ? "刷新中…" : "刷新状态" }}
      </button>
    </section>

    <section class="card" aria-label="已开放范围">
      <h2>S1 本阶段已开放范围</h2>
      <ul>
        <li>本页仅验证工程基线：前后端构建、数据库迁移、健康与版本契约、重启持久性。</li>
        <li>注册、建队、编队、冒险、战报与管理后台等玩法功能<strong>尚未开放</strong>。</li>
      </ul>
    </section>

    <section class="card" aria-label="未交付限制">
      <h2>未交付限制</h2>
      <ul>
        <li>当前没有账号系统，本页不保存任何玩家数据。</li>
        <li>接口只提供健康状态与版本信息，其余命令一律拒绝（未实现的操作不会模拟成功）。</li>
        <li>内容清单为工程占位，首批玩法内容将随后续任务发布。</li>
      </ul>
    </section>

    <section class="card" aria-label="测试进度说明">
      <h2>测试进度说明</h2>
      <p>本环境数据仅用于工程验证，<strong>测试进度不保证继承</strong>到后续版本；如需重置，将事先说明范围并取得授权。</p>
    </section>
  </main>
</template>
