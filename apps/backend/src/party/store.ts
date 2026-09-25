import type { DatabaseSync } from "node:sqlite";
import type { PartyCharacterView } from "@hof/shared";
import { newId, sha256Hex } from "../auth/tokens.js";
import type { PartyContent } from "./content.js";

function withTransaction<T>(db: DatabaseSync, fn: () => T): T {
  db.exec("BEGIN IMMEDIATE");
  try {
    const result = fn();
    db.exec("COMMIT");
    return result;
  } catch (err) {
    try {
      db.exec("ROLLBACK");
    } catch {
      // 回滚失败时保留原错误。
    }
    throw err;
  }
}

function conflict(code: string, message: string): Error {
  const err = new Error(message);
  (err as NodeJS.ErrnoException).code = code;
  return err;
}

export interface CreateFirstPartyOpts {
  accountId: string;
  teamName: string;
  characterName: string;
  recruitId: string;
  gender: string;
  requestId: string;
}

export interface CreateFirstPartyResult {
  teamName: string;
  characterId: string;
  replayed: boolean;
}

/**
 * 首次建队事务：队名占用、首名角色及其初始装备实例、技能、默认战术、
 * 建队完成标志、幂等记录与业务流水一次提交。
 * - 首角免费，不扣费；失败不留部分结果（ROLLBACK）。
 * - 同一账号+请求身份重复提交返回原结果；换参数拒绝。
 * - 已建队账号拒绝（幂等重放除外）；队名争用仅一胜。
 */
export function createFirstParty(
  db: DatabaseSync,
  content: PartyContent,
  opts: CreateFirstPartyOpts,
): CreateFirstPartyResult {
  const template = content.recruitments.get(opts.recruitId);
  if (!template) throw conflict("INVALID_INPUT", "职业选择非法（S1 仅支持战士或法师）");
  if (opts.gender !== "male" && opts.gender !== "female") throw conflict("INVALID_INPUT", "性别选择非法");

  const requestKey = `first-party:${opts.accountId}:${opts.requestId}`;
  const paramDigest = sha256Hex(
    ["first-party", opts.accountId, opts.teamName, opts.characterName, opts.recruitId, opts.gender].join("\n"),
  );
  const now = new Date().toISOString();

  return withTransaction(db, () => {
    // 同一请求身份重复提交：参数一致返回原结果，换参数拒绝。
    const existing = db.prepare("SELECT * FROM first_party_requests WHERE request_key = ?").get(requestKey) as unknown as
      | { param_digest: string; character_id: string }
      | undefined;
    if (existing) {
      if (existing.param_digest !== paramDigest) {
        throw conflict("REQUEST_CONFLICT", "同一请求身份不得更换参数");
      }
      const account = db.prepare("SELECT team_name FROM accounts WHERE id = ?").get(opts.accountId) as unknown as
        | { team_name: string | null }
        | undefined;
      if (!account?.team_name || !existing.character_id) {
        throw conflict("REQUEST_CONFLICT", "原操作记录异常");
      }
      return { teamName: account.team_name, characterId: existing.character_id, replayed: true };
    }

    const account = db.prepare("SELECT id, team_completed FROM accounts WHERE id = ? AND deleted_at IS NULL").get(
      opts.accountId,
    ) as unknown as { id: string; team_completed: number } | undefined;
    if (!account) throw conflict("UNAUTHORIZED", "需要登录");
    if (account.team_completed === 1) throw conflict("PARTY_ALREADY_COMPLETED", "已完成首次建队");

    // 队名占用与建队标志一次写入；UNIQUE 冲突即队名已被占用。
    try {
      const changed = db.prepare("UPDATE accounts SET team_name = ?, team_completed = 1 WHERE id = ? AND team_completed = 0").run(
        opts.teamName,
        opts.accountId,
      );
      if (Number((changed as unknown as { changes: number }).changes ?? 0) === 0) {
        throw conflict("PARTY_ALREADY_COMPLETED", "已完成首次建队");
      }
    } catch (err) {
      if (isTeamNameViolation(err)) throw conflict("TEAM_NAME_TAKEN", "队伍名已被占用");
      throw err;
    }

    const characterId = newId();
    db.prepare(
      `INSERT INTO characters (id, account_id, name, job_id, gender, level, experience,
        max_hp, hp, max_sp, sp, str, intelligence, dex, spd, luk,
        unassigned_ap, unassigned_sp, position, guard_policy, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?)`,
    ).run(
      characterId,
      opts.accountId,
      opts.characterName,
      template.jobId,
      opts.gender,
      template.initialLevel,
      template.initialExperience,
      template.initialHpSp.maxHp,
      template.initialHpSp.hp,
      template.initialHpSp.maxSp,
      template.initialHpSp.sp,
      template.initialStats.str,
      template.initialStats.int,
      template.initialStats.dex,
      template.initialStats.spd,
      template.initialStats.luk,
      template.position,
      template.guardPolicy.kind,
      now,
    );

    const insertSkill = db.prepare("INSERT INTO character_skills (character_id, position, skill_id) VALUES (?, ?, ?)");
    template.initialSkillIds.forEach((skillId, index) => {
      insertSkill.run(characterId, index, skillId);
    });

    // 每件初始装备拥有不复用的持有身份；同款不合并。
    const insertEquipment = db.prepare(
      "INSERT INTO owned_equipment (id, account_id, definition_id, slot, equipped_character_id, created_at) VALUES (?, ?, ?, ?, ?, ?)",
    );
    for (const [slot, definitionId] of Object.entries(template.initialEquipment)) {
      const equipmentSlot = content.equipmentSlots.get(definitionId) ?? slot;
      insertEquipment.run(newId(), opts.accountId, definitionId, equipmentSlot, characterId, now);
    }

    const insertTactic = db.prepare(
      "INSERT INTO character_tactics (character_id, position, skill_id, conditions_json) VALUES (?, ?, ?, ?)",
    );
    template.defaultTactics.forEach((tactic, index) => {
      insertTactic.run(characterId, index, tactic.skillId, JSON.stringify(tactic.conditions));
    });

    db.prepare(
      "INSERT INTO first_party_requests (request_key, param_digest, status, account_id, character_id, created_at) VALUES (?, ?, 'completed', ?, ?, ?)",
    ).run(requestKey, paramDigest, opts.accountId, characterId, now);
    db.prepare(
      `INSERT INTO game_change_records (id, account_id, kind, money_delta, stamina_delta, reason, request_key, created_at)
       VALUES (?, ?, 'first_party', 0, 0, ?, ?, ?)`,
    ).run(newId(), opts.accountId, `首次建队：${opts.teamName} 获得免费首角`, requestKey, now);

    return { teamName: opts.teamName, characterId, replayed: false };
  });
}

export interface MinePartyResult {
  teamCompleted: boolean;
  teamName: string | null;
  character: PartyCharacterView | null;
}

/** 只读查看：角色等级/经验/属性/未分配点/技能/装备/阵位/默认战术（S1 无培养编辑）。 */
export function getPartyMine(db: DatabaseSync, accountId: string): MinePartyResult {
  const account = db.prepare("SELECT team_name, team_completed FROM accounts WHERE id = ? AND deleted_at IS NULL").get(
    accountId,
  ) as unknown as { team_name: string | null; team_completed: number } | undefined;
  if (!account) throw conflict("UNAUTHORIZED", "需要登录");
  if (account.team_completed !== 1) {
    return { teamCompleted: false, teamName: null, character: null };
  }
  const character = db.prepare("SELECT * FROM characters WHERE account_id = ? ORDER BY created_at LIMIT 1").get(accountId) as unknown as
    | Record<string, unknown>
    | undefined;
  if (!character) {
    // 建队标志已立但角色缺失属于数据异常：如实返回未完成视图以外的空角色，不伪造。
    return { teamCompleted: true, teamName: account.team_name, character: null };
  }
  const characterId = String(character.id);
  const skills = (
    db.prepare("SELECT skill_id FROM character_skills WHERE character_id = ? ORDER BY position").all(characterId) as unknown as {
      skill_id: string;
    }[]
  ).map((row) => row.skill_id);
  const equipment = (
    db.prepare("SELECT id, definition_id, slot FROM owned_equipment WHERE equipped_character_id = ? ORDER BY slot, id").all(
      characterId,
    ) as unknown as { id: string; definition_id: string; slot: string }[]
  ).map((row) => ({ equipmentId: row.id, definitionId: row.definition_id, slot: row.slot }));
  const tactics = (
    db.prepare("SELECT skill_id, conditions_json FROM character_tactics WHERE character_id = ? ORDER BY position").all(
      characterId,
    ) as unknown as { skill_id: string; conditions_json: string }[]
  ).map((row) => ({
    conditions: JSON.parse(row.conditions_json) as Array<{ conditionId: string; quantity: number }>,
    skillId: row.skill_id,
  }));

  return {
    teamCompleted: true,
    teamName: account.team_name,
    character: {
      characterId,
      name: String(character.name),
      jobId: String(character.job_id),
      gender: String(character.gender),
      level: Number(character.level),
      experience: Number(character.experience),
      maxHp: Number(character.max_hp),
      hp: Number(character.hp),
      maxSp: Number(character.max_sp),
      sp: Number(character.sp),
      stats: {
        str: Number(character.str),
        int: Number(character.intelligence),
        dex: Number(character.dex),
        spd: Number(character.spd),
        luk: Number(character.luk),
      },
      unassignedAp: Number(character.unassigned_ap),
      unassignedSp: Number(character.unassigned_sp),
      skillIds: skills,
      equipment,
      position: String(character.position),
      guardPolicy: { kind: String(character.guard_policy) },
      defaultTactics: tactics,
    },
  };
}

/** 建队资格：未建队账号不能招募或冒险（后续招募/冒险入口调用此门槛）。 */
export function isAccountTeamed(db: DatabaseSync, accountId: string): boolean {
  const row = db.prepare("SELECT team_completed FROM accounts WHERE id = ? AND deleted_at IS NULL").get(accountId) as unknown as
    | { team_completed: number }
    | undefined;
  return row?.team_completed === 1;
}

function isTeamNameViolation(err: unknown): boolean {
  const message = err instanceof Error ? err.message : String(err);
  const code = (err as NodeJS.ErrnoException)?.code;
  const unique = code === "ERR_SQLITE_CONSTRAINT_UNIQUE" || /UNIQUE constraint failed/i.test(message);
  // 本事务内唯一可能冲突的业务唯一键是 accounts.team_name（其余主键均为新生成的 UUID）。
  return unique && /team_name/i.test(message);
}
