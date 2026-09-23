import crypto from "node:crypto";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { dropIntervals, pickDrop, pickEncounter } from "./distributions.js";
import { validateContent } from "./validate.js";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(HERE, "..", "..", "..");
const CONTENT_DIR = path.join(REPO_ROOT, "content");

const SCHEMA_VERSION = 1;
const ENGINE_VERSION = "s1-engine-0";
const RANDOM_PROTOCOL = "s1-random-0";
const EVENT_FORMAT = "s1-event-0";

/** 编辑源文件（可手工编辑）；发布快照由构建生成到 content/releases/<releaseId>/，不可手工改。 */
const EDIT_FILES = [
  "recruitment.json",
  "jobs.json",
  "items.json",
  "skills.json",
  "conditions.json",
  "monsters.json",
  "maps.json",
  "assets.json",
] as const;

function sha256Hex(data: Buffer | string): string {
  return crypto.createHash("sha256").update(data).digest("hex");
}

function maxMigrationVersion(): number {
  const dir = path.join(REPO_ROOT, "apps", "backend", "migrations");
  const files = fs
    .readdirSync(dir)
    .filter((f) => /^\d{4}_[a-z0-9_]+\.sql$/.test(f))
    .sort();
  return Math.max(...files.map((f) => Number(f.slice(0, 4))));
}

function main(): void {
  const checkOnly = process.argv.includes("--check-only");
  const result = validateContent(CONTENT_DIR);
  if (result.errors.length > 0) {
    console.error(`内容校验失败（${result.errors.length} 个错误）：`);
    for (const d of result.errors.slice(0, 50)) {
      console.error(`- [${d.code}] ${d.file}${d.definitionId ? ` ${d.definitionId}` : ""}${d.fieldPath ? ` ${d.fieldPath}` : ""}: ${d.message}`);
    }
    process.exit(1);
  }
  const content = result.content!;

  // 固定随机输入验证：权重与掉落区间端点（不靠反复抽样碰运气）。
  const gb0 = content.maps.find((m) => m.id === "map.gb0")!;
  const total = gb0.encounters.reduce((s, e) => s + e.weight, 0);
  const encounterChecks: { roll: number; expected: string }[] = [
    { roll: 1, expected: "monster.1000" },
    { roll: 300, expected: "monster.1000" },
    { roll: 301, expected: "monster.1001" },
    { roll: total, expected: "monster.1001" },
  ];
  for (const c of encounterChecks) {
    const got = pickEncounter(gb0.encounters, c.roll);
    if (got.monsterId !== c.expected) {
      console.error(`遭遇端点验证失败：roll=${c.roll} 期望 ${c.expected}，得 ${got.monsterId}`);
      process.exit(1);
    }
  }

  const dropChecks: { roll: number; expected: string | null }[] = [
    { roll: 1, expected: "item.6000" },
    { roll: 1000, expected: "item.6000" },
    { roll: 1001, expected: "item.6001" },
    { roll: 2000, expected: "item.6001" },
    { roll: 2001, expected: "item.6002" },
    { roll: 2600, expected: "item.6002" },
    { roll: 2601, expected: "item.6003" },
    { roll: 2800, expected: "item.6003" },
    { roll: 2801, expected: "item.7100" },
    { roll: 2900, expected: "item.7100" },
    { roll: 2901, expected: null },
    { roll: 10000, expected: null },
  ];
  for (const m of content.monsters) {
    for (const c of dropChecks) {
      const got = pickDrop(m.drops.entries, m.drops.denominator, c.roll);
      if (got !== c.expected) {
        console.error(`掉落端点验证失败：${m.id} roll=${c.roll} 期望 ${String(c.expected)}，得 ${String(got)}`);
        process.exit(1);
      }
    }
  }

  // 发布身份由编辑源内容与版本配对元数据共同派生：任一变化即新发布，
  // 同一快照的启动与恢复条件恒定。配对元数据不参与 contentHash（后者只覆盖内容载荷）。
  const pairing = {
    schemaVersion: SCHEMA_VERSION,
    engine: ENGINE_VERSION,
    randomProtocol: RANDOM_PROTOCOL,
    eventFormat: EVENT_FORMAT,
    compatibleDbSchema: maxMigrationVersion(),
  };
  const editHashes = EDIT_FILES.map((f) => {
    const data = fs.readFileSync(path.join(CONTENT_DIR, f));
    return `${f}:${sha256Hex(data)}`;
  }).sort();
  const sourceHash = sha256Hex(editHashes.join("\n") + "\n");
  const releaseId = `s1-${sha256Hex(`${sourceHash}\n${JSON.stringify(pairing)}\n`).slice(0, 12)}`;
  const snapshotRel = `content/releases/${releaseId}`;
  const snapshotDir = path.join(REPO_ROOT, snapshotRel);

  if (checkOnly) {
    console.log(`内容校验通过：releaseId=${releaseId}（--check-only，不写快照）`);
    return;
  }

  // 在独立目录生成完整快照：8 个 JSON（含改写路径的 assets.json）+ 素材二进制。
  const staged = new Map<string, Buffer>();
  for (const f of EDIT_FILES) {
    const rel = `${snapshotRel}/${f}`;
    if (f === "assets.json") {
      const assetsDoc = JSON.parse(fs.readFileSync(path.join(CONTENT_DIR, f), "utf8")) as {
        assets: { path: string }[];
      };
      const rewritten = {
        assets: assetsDoc.assets.map((a) => ({ ...a, path: a.path.replace(/^content\/assets\//, `${snapshotRel}/assets/`) })),
      };
      staged.set(rel, Buffer.from(JSON.stringify(rewritten, null, 2) + "\n", "utf8"));
    } else {
      staged.set(rel, fs.readFileSync(path.join(CONTENT_DIR, f)));
    }
  }
  for (const a of content.assets) {
    const srcAbs = path.join(CONTENT_DIR, path.relative("content", a.path));
    staged.set(`${snapshotRel}/assets/${path.basename(a.path)}`, fs.readFileSync(srcAbs));
  }

  // 完整产物先在临时目录生成（含清单与报告），再整体发布；
  // 已有快照只做一致性核验，任何字节差异即失败，绝不原地改写。
  const stageDir = fs.mkdtempSync(path.join(os.tmpdir(), "hof-release-"));
  try {
    for (const [rel, data] of staged) {
      const abs = path.join(stageDir, path.relative("content", rel));
      fs.mkdirSync(path.dirname(abs), { recursive: true });
      fs.writeFileSync(abs, data);
    }

    const files = [...staged.keys()].sort().map((rel) => {
      const data = staged.get(rel)!;
      return { path: rel, sha256: sha256Hex(data), bytes: data.length };
    });
    const contentHash = `sha256:${sha256Hex(files.map((f) => `${f.path}:${f.sha256}`).join("\n") + "\n")}`;

    const handlerUse = new Map<string, string[]>();
    for (const s of content.skills) {
      if (s.handlerId) {
        const list = handlerUse.get(s.handlerId) ?? [];
        list.push(s.id);
        handlerUse.set(s.handlerId, list);
      }
    }
    const release = {
      releaseId,
      ...pairing,
      contentHash,
      sourceHash: `sha256:${sourceHash}`,
      files,
      notes: "S1 首批内容包（#23）：招募 1/2、职业 100/200、初始装备 1000/1700/3000/5000/5200、gb0、怪物 1000/1001、技能 1000/1001/1002/1014/1017/3010、条件 1000/1205/1206/1940 与有序 AND、材料 6000/6001/6002/6003/7100。旧 9000 为有序 AND 连接，不作技能。怪物 moneyReward=100 系已裁决保留旧行为（moneyhold<2000 覆盖，属规格来源核对修正，非批准差异）。",
    };
  const report = {
    releaseId,
    schemaVersion: SCHEMA_VERSION,
    contentHash,
    sourceFingerprint: `sha256:${sourceHash}`,
    snapshot: snapshotRel,
    coverage: {
      recruitments: content.recruitments.map((r) => r.id),
      jobs: content.jobs.map((j) => j.id),
      items: content.items.map((i) => `${i.id}(${i.kind})`),
      skills: content.skills.map((s) => `${s.id}${s.handlerId ? `[${s.handlerId}]` : "[generic]"}`),
      conditions: content.conditions.map((c) => `${c.id}[${c.kind}]`),
      monsters: content.monsters.map((m) => m.id),
      maps: content.maps.map((m) => m.id),
      assets: content.assets.map((a) => `${a.id} -> ${a.path} (${a.bytes}B)`),
    },
    mechanisms: {
      handlers: [...handlerUse.entries()].map(([handlerId, usedBy]) => ({ handlerId, usedBy })),
      genericDamage: content.skills.filter((s) => !s.handlerId).map((s) => s.id),
      connectorNote: "旧 9000 已转为有序 AND 条件组；skill.9000 / condition.9000 不存在且被 Schema 与校验拒绝。",
    },
    encounters: {
      mapId: gb0.id,
      candidates: gb0.encounters,
      totalWeight: total,
      endpointChecks: encounterChecks.map((c) => ({ ...c, got: c.expected, pass: true })),
    },
    drops: content.monsters.map((m) => ({
      monsterId: m.id,
      denominator: m.drops.denominator,
      intervals: dropIntervals(m.drops.entries),
      noDropRange: [2901, 10000] as const,
      endpointChecks: dropChecks.map((c) => ({ ...c, pass: true })),
    })),
    diagnostics: result.diagnostics,
    scenarios: [
      "gb0 遭遇 1/300/301/600 端点固定输入验证通过",
      "怪物掉落 1/1000/1001/2000/2001/2600/2601/2800/2801/2900/2901/10000 端点固定输入验证通过（两只怪物分别验证）",
    ],
  };

  const releaseBytes = Buffer.from(JSON.stringify(release, null, 2) + "\n", "utf8");
  const reportBytes = Buffer.from(JSON.stringify(report, null, 2) + "\n", "utf8");
  fs.writeFileSync(path.join(stageDir, "release.json"), releaseBytes);
  fs.writeFileSync(path.join(stageDir, "release.report.json"), reportBytes);

  const expected: [string, Buffer][] = [
    ...[...staged.entries()].map(([rel, data]) => [rel, data] as [string, Buffer]),
    [`${snapshotRel}/release.json`, releaseBytes],
    [`${snapshotRel}/release.report.json`, reportBytes],
  ];
  if (fs.existsSync(snapshotDir)) {
    for (const [rel, data] of expected) {
      const abs = path.join(REPO_ROOT, rel);
      if (!fs.existsSync(abs) || !fs.readFileSync(abs).equals(data)) {
        console.error(`快照 ${releaseId} 已存在但内容不一致（不可变快照不得原地改写）：${rel}`);
        process.exit(1);
      }
    }
    console.log(`快照 ${releaseId} 已存在且一致，仅核验（${expected.length} 个文件）`);
  } else {
    fs.mkdirSync(path.join(snapshotDir, "assets"), { recursive: true });
    for (const [rel, data] of expected) {
      fs.writeFileSync(path.join(REPO_ROOT, rel), data);
    }
    fs.writeFileSync(path.join(CONTENT_DIR, "current-release"), releaseId + "\n");
    console.log(`已发布 ${releaseId}：${files.length} 个内容文件，contentHash=${contentHash}`);
  }
  } finally {
    fs.rmSync(stageDir, { recursive: true, force: true });
  }
}

main();
