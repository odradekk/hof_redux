<script setup lang="ts">
import { onMounted, ref } from "vue";
import type { HealthResponse, MeResponse, MinePartyResponse, VersionResponse } from "@hof/shared";
import {
  ApiError,
  createFirstParty,
  fetchHealth,
  fetchMe,
  fetchMineParty,
  fetchVersion,
  login,
  logout,
  newRequestId,
  registerAccount,
} from "./api";

const health = ref<HealthResponse | null>(null);
const version = ref<VersionResponse | null>(null);
const loadError = ref<string | null>(null);
const loading = ref(false);

const me = ref<MeResponse | null>(null);
const authChecked = ref(false);

// 注册表单（内存态，不写入长期存储）。
const regLogin = ref("");
const regPassword = ref("");
const regError = ref<string | null>(null);
const regBusy = ref(false);
const regPending = ref(false);
const regRequestId = ref<string | null>(null);
const recoveryCode = ref<string | null>(null);
const recoverySaved = ref(false);
const regAccount = ref<{ accountId: string; loginName: string } | null>(null);

// 登录表单。
const loginName = ref("");
const loginPassword = ref("");
const loginError = ref<string | null>(null);
const loginBusy = ref(false);
const logoutBusy = ref(false);

// 首次建队表单（#25；内存态，不写入长期存储）。
const mineParty = ref<MinePartyResponse | null>(null);
const teamName = ref("");
const characterName = ref("");
const recruitId = ref("recruit.1");
const gender = ref("male");
const partyError = ref<string | null>(null);
const partyBusy = ref(false);
const partyPending = ref(false);
const partyRequestId = ref<string | null>(null);

function friendlyError(err: unknown): string {
  if (err instanceof ApiError) return err.message;
  return String(err instanceof Error ? err.message : err);
}

async function refresh() {
  loading.value = true;
  loadError.value = null;
  try {
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

async function refreshMe() {
  try {
    me.value = await fetchMe();
  } catch {
    me.value = null;
  } finally {
    authChecked.value = true;
  }
  if (me.value) {
    try {
      mineParty.value = await fetchMineParty();
    } catch {
      mineParty.value = null;
    }
  } else {
    mineParty.value = null;
  }
}

function validateLocalLoginName(name: string): string | null {
  if (!/^[A-Za-z0-9]{4,16}$/.test(name)) return "登录名须为 4–16 位 ASCII 字母或数字";
  return null;
}

function validateLocalPassword(password: string): string | null {
  if ([...password].length < 15 || [...password].length > 128) return "密码须为 15–128 个字符（按 Unicode 码点计数）";
  return null;
}

function validateLocalPartyName(name: string, label: string): string | null {
  const normalized = name.normalize("NFC");
  // eslint-disable-next-line no-misleading-character-class
  if (/[\p{Cc}\p{Cf}]/u.test(normalized)) return `${label}不得包含换行、控制或不可见格式字符`;
  const trimmed = normalized.trim();
  if (trimmed.length === 0) return `${label}不能为空`;
  const length = [...trimmed].length;
  if (length < 1 || length > 16) return `${label}须为 1–16 个字符（按 Unicode 码点计数）`;
  return null;
}

async function onCreateFirstParty() {
  partyError.value = null;
  const teamErr = validateLocalPartyName(teamName.value, "队伍名");
  if (teamErr) {
    partyError.value = teamErr;
    return;
  }
  const charErr = validateLocalPartyName(characterName.value, "角色名");
  if (charErr) {
    partyError.value = charErr;
    return;
  }
  if (recruitId.value !== "recruit.1" && recruitId.value !== "recruit.2") {
    partyError.value = "职业选择非法（S1 仅支持战士或法师）";
    return;
  }
  if (gender.value !== "male" && gender.value !== "female") {
    partyError.value = "性别选择非法";
    return;
  }
  partyBusy.value = true;
  partyRequestId.value ??= newRequestId();
  try {
    const result = await createFirstParty(
      teamName.value.normalize("NFC").trim(),
      characterName.value.normalize("NFC").trim(),
      recruitId.value,
      gender.value,
      partyRequestId.value,
    );
    partyRequestId.value = null;
    partyPending.value = false;
    if (result.replayed) {
      partyError.value = "该请求已受理（幂等重放），未重复赠送。";
    }
    await refreshMe();
  } catch (err) {
    if (err instanceof ApiError && err.status === 429) {
      partyPending.value = true;
      partyError.value = "本次重试受到限流，先前建队仍待确认。请稍后用原请求重试。";
    } else if (err instanceof ApiError && err.status >= 400 && err.status < 500) {
      // 确定性拒绝：下次提交换新请求身份（同身份换参数会被幂等拒绝）。
      partyRequestId.value = null;
      partyPending.value = false;
      if (err.code === "TEAM_NAME_TAKEN") {
        partyError.value = "队伍名已被占用，请更换。";
      } else if (err.code === "PARTY_ALREADY_COMPLETED") {
        partyError.value = "已完成首次建队，无需重复提交。";
        await refreshMe();
      } else {
        partyError.value = friendlyError(err);
      }
    } else {
      partyPending.value = true;
      partyError.value = `建队结果待确认：${friendlyError(err)}。请用原请求重试，不要另建队伍。`;
    }
  } finally {
    partyBusy.value = false;
  }
}

async function onRegister() {
  regError.value = null;
  regAccount.value = null;
  const nameErr = validateLocalLoginName(regLogin.value);
  if (nameErr) {
    regError.value = nameErr;
    return;
  }
  const passErr = validateLocalPassword(regPassword.value);
  if (passErr) {
    regError.value = passErr;
    return;
  }
  regBusy.value = true;
  regRequestId.value ??= newRequestId();
  try {
    const result = await registerAccount(regLogin.value, regPassword.value, regRequestId.value);
    regRequestId.value = null;
    regPending.value = false;
    regAccount.value = { accountId: result.accountId, loginName: result.loginName };
    recoveryCode.value = result.recoveryCode ?? null;
    recoverySaved.value = false;
    if (result.replayed) {
      regError.value = "该请求已受理（幂等重放），恢复码不再重复展示。请直接登录。";
    }
    regPassword.value = "";
    await refreshMe();
  } catch (err) {
    if (err instanceof ApiError && err.status === 429 && regPending.value) {
      regError.value = "本次重试受到限流，先前注册仍待确认。请稍后用原请求重试，或尝试登录。";
    } else if (err instanceof ApiError && err.status >= 400 && err.status < 500) {
      regRequestId.value = null;
      regPending.value = false;
      regError.value = friendlyError(err);
    } else {
      regPending.value = true;
      regError.value = `注册结果待确认：${friendlyError(err)}。请用原请求重试，或尝试用刚设置的密码登录；不要另建账号。`;
    }
  } finally {
    regBusy.value = false;
  }
}

function confirmRecoverySaved() {
  // 一次展示确认后即从内存清除，不进入长期存储。
  recoveryCode.value = null;
  recoverySaved.value = true;
}

async function onLogin() {
  loginError.value = null;
  const nameErr = validateLocalLoginName(loginName.value);
  if (nameErr) {
    loginError.value = nameErr;
    return;
  }
  if (loginPassword.value.length === 0) {
    loginError.value = "请输入密码";
    return;
  }
  loginBusy.value = true;
  try {
    await login(loginName.value, loginPassword.value);
    loginPassword.value = "";
    await refreshMe();
  } catch (err) {
    if (err instanceof ApiError && err.status === 401) {
      loginError.value = "登录名或密码不正确";
    } else {
      loginError.value = friendlyError(err);
    }
  } finally {
    loginBusy.value = false;
  }
}

async function onLogout() {
  logoutBusy.value = true;
  try {
    await logout();
  } catch (err) {
    loginError.value = friendlyError(err);
  } finally {
    logoutBusy.value = false;
    await refreshMe();
  }
}

onMounted(async () => {
  await refresh();
  await refreshMe();
});
</script>

<template>
  <main class="page">
    <header class="masthead">
      <h1>HOF Redux</h1>
      <p class="subtitle">S1 受控测试 · 真实账号</p>
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
          <dd v-if="version">{{ version.content.releaseId }}（schema v{{ version.content.schemaVersion }}，{{ version.content.contentHash.slice(0, 19) }}…）</dd>
          <dd v-else class="bad">未知</dd>
        </div>
        <div>
          <dt>数据库结构版本</dt>
          <dd v-if="version">迁移 v{{ version.database.schemaVersion }}<span v-if="version.recoveryEpoch !== undefined"> · 恢复代次 {{ version.recoveryEpoch }}</span></dd>
          <dd v-else class="bad">未知</dd>
        </div>
      </dl>
      <p v-if="loadError" class="bad" role="alert">{{ loadError }}</p>
      <button type="button" :disabled="loading" @click="refresh">
        {{ loading ? "刷新中…" : "刷新状态" }}
      </button>
    </section>

    <section class="card" aria-label="账号">
      <h2>账号</h2>
      <p v-if="!authChecked" class="muted">正在确认登录状态…</p>
      <div v-else-if="me">
        <dl class="status-grid">
          <div><dt>登录名</dt><dd>{{ me.loginName }}</dd></div>
          <div><dt>建队状态</dt><dd>{{ me.teamCompleted ? "已完成" : "待完成首次建队（#25）" }}</dd></div>
          <div><dt>金钱</dt><dd>{{ me.money }}</dd></div>
          <div><dt>体力</dt><dd>{{ me.stamina }}</dd></div>
          <div><dt>恢复码代次</dt><dd>{{ me.recoveryGeneration }}</dd></div>
        </dl>
        <p class="muted">重新登录后仍可读取上述持久状态。恢复码消费、改密、重签发及多设备退出由后续票据交付。</p>
        <button type="button" :disabled="logoutBusy" @click="onLogout">
          {{ logoutBusy ? "退出中…" : "退出当前会话" }}
        </button>
      </div>
      <div v-else>
        <p class="muted">未登录。注册成功后请妥善保存只展示一次的恢复码，再登录。</p>
        <h3>注册</h3>
        <form @submit.prevent="onRegister">
          <label>登录名（4–16 位字母或数字）
            <input v-model="regLogin" autocomplete="username" maxlength="16" inputmode="text" autocapitalize="none" autocorrect="off" :disabled="regBusy || regPending" />
          </label>
          <label>长口令（15–128 个字符，可含中文/空格/符号，不裁剪）
            <input v-model="regPassword" type="password" autocomplete="new-password" :disabled="regBusy || regPending" />
          </label>
          <button type="submit" :disabled="regBusy">{{ regBusy ? "注册中…" : regPending ? "用原请求重试" : "注册" }}</button>
        </form>
        <p v-if="regError" class="bad" role="alert">{{ regError }}</p>
        <div v-if="recoveryCode" class="recovery" role="alert">
          <p><strong>恢复码（仅展示一次，请立即离线保存）：</strong></p>
          <code>{{ recoveryCode }}</code>
          <p class="muted">响应丢失时不重放明文：请用已设置的密码登录，后续凭密码重新签发。</p>
          <button type="button" @click="confirmRecoverySaved">我已保存恢复码</button>
        </div>
        <p v-else-if="recoverySaved" class="ok">恢复码已确认并从页面清除。请登录。</p>
        <p v-else-if="regAccount" class="ok">账号 {{ regAccount.loginName }} 已创建（初始 10,000 金钱 / 100 体力）。</p>

        <h3>登录</h3>
        <form @submit.prevent="onLogin">
          <label>登录名
            <input v-model="loginName" autocomplete="username" maxlength="16" inputmode="text" autocapitalize="none" autocorrect="off" />
          </label>
          <label>密码
            <input v-model="loginPassword" type="password" autocomplete="current-password" />
          </label>
          <button type="submit" :disabled="loginBusy">{{ loginBusy ? "登录中…" : "登录" }}</button>
        </form>
        <p v-if="loginError" class="bad" role="alert">{{ loginError }}</p>
      </div>
    </section>

    <section class="card" aria-label="首次建队">
      <h2>首次建队</h2>
      <div v-if="!me">
        <p class="muted">登录后可建立队伍并获得首名角色。</p>
      </div>
      <div v-else-if="mineParty?.teamCompleted && mineParty.character">
        <dl class="status-grid">
          <div><dt>队伍名</dt><dd>{{ mineParty.teamName }}</dd></div>
          <div><dt>首名角色</dt><dd>{{ mineParty.character.name }}</dd></div>
          <div><dt>职业</dt><dd>{{ mineParty.character.jobName }}（{{ mineParty.character.gender === "male" ? "男" : "女" }}）</dd></div>
          <div><dt>等级 / 经验</dt><dd>{{ mineParty.character.level }} / {{ mineParty.character.experience }}</dd></div>
          <div><dt>HP / SP</dt><dd>{{ mineParty.character.hp }} / {{ mineParty.character.maxHp }} · {{ mineParty.character.sp }} / {{ mineParty.character.maxSp }}</dd></div>
          <div><dt>属性（力/智/敏/速/运）</dt><dd>{{ mineParty.character.stats.str }} / {{ mineParty.character.stats.int }} / {{ mineParty.character.stats.dex }} / {{ mineParty.character.stats.spd }} / {{ mineParty.character.stats.luk }}</dd></div>
          <div><dt>未分配点数</dt><dd>属性 {{ mineParty.character.unassignedAp }} · 技能 {{ mineParty.character.unassignedSp }}</dd></div>
          <div><dt>技能</dt><dd>{{ mineParty.character.skills.map((skill) => skill.name).join("、") }}</dd></div>
          <div><dt>阵位 / 掩护</dt><dd>{{ mineParty.character.position === "front" ? "前排" : "后排" }} / {{ mineParty.character.guardPolicy.kind === "always" ? "掩护" : "不掩护" }}</dd></div>
        </dl>
        <h3>装备（每件独立身份）</h3>
        <ul>
          <li v-for="item in mineParty.character.equipment" :key="item.equipmentId">
            {{ item.slot }}：{{ item.name }}（归属本角色）
          </li>
        </ul>
        <h3>默认战术（只读）</h3>
        <ol>
          <li v-for="(tactic, index) in mineParty.character.defaultTactics" :key="index">
            {{ tactic.conditions.map((c) => `${c.description}（${c.quantity}）`).join(" + ") }} → {{ tactic.skillName }}
          </li>
        </ol>
        <p class="muted">S1 尚未开放加点、技能学习、换装与战术编辑；以上为真实保存的只读状态。</p>
      </div>
      <div v-else-if="me.teamCompleted">
        <p class="muted">正在读取角色状态…</p>
      </div>
      <div v-else>
        <p class="muted">输入队名与首名角色姓名，选择战士/法师及性别，一次提交建队（首角免费）。队名全服唯一，角色名允许重名；名称 1–16 个字符，按 Unicode 码点计数。</p>
        <form @submit.prevent="onCreateFirstParty">
          <label>队伍名（全服唯一）
            <input v-model="teamName" inputmode="text" autocapitalize="none" autocorrect="off" :disabled="partyBusy || partyPending" />
          </label>
          <label>首名角色姓名（可重名）
            <input v-model="characterName" inputmode="text" autocapitalize="none" autocorrect="off" :disabled="partyBusy || partyPending" />
          </label>
          <fieldset>
            <legend>职业</legend>
            <label><input v-model="recruitId" type="radio" value="recruit.1" :disabled="partyBusy || partyPending" /> 战士（前排）</label>
            <label><input v-model="recruitId" type="radio" value="recruit.2" :disabled="partyBusy || partyPending" /> 法师（后排）</label>
          </fieldset>
          <fieldset>
            <legend>性别</legend>
            <label><input v-model="gender" type="radio" value="male" :disabled="partyBusy || partyPending" /> 男</label>
            <label><input v-model="gender" type="radio" value="female" :disabled="partyBusy || partyPending" /> 女</label>
          </fieldset>
          <button type="submit" :disabled="partyBusy">{{ partyBusy ? "建队中…" : partyPending ? "用原请求重试" : "建立队伍" }}</button>
        </form>
        <p v-if="partyError" class="bad" role="alert">{{ partyError }}</p>
      </div>
    </section>

    <section class="card" aria-label="已开放范围">
      <h2>S1 本阶段已开放范围</h2>
      <ul>
        <li>真实注册、登录、初始资产（10,000 金钱 / 100 体力）与一次性恢复码展示。</li>
        <li>首次建队：队名与首角一次提交，首角免费，角色只读查看。</li>
        <li>招募、冒险、战报与管理后台等后续功能<strong>尚未开放</strong>，入口已隐藏、接口直接拒绝。</li>
      </ul>
    </section>

    <section class="card" aria-label="未交付限制">
      <h2>未交付限制</h2>
      <ul>
        <li>恢复码消费、改密、重签发及多设备退出由后续票据交付。</li>
        <li>未完成首次建队的账号只能访问获准范围，不能招募或冒险。</li>
        <li>内容清单为 S1 首批内容快照；应用/内容/数据库版本见顶部状态卡。</li>
      </ul>
    </section>

    <section class="card" aria-label="测试进度说明">
      <h2>测试进度说明</h2>
      <p>本环境数据仅用于工程验证，<strong>测试进度不保证继承</strong>到后续版本；如需重置，将事先说明范围并取得授权。</p>
    </section>
  </main>
</template>
