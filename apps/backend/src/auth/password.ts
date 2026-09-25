import { hash, verify } from "@node-rs/argon2";

/**
 * 密码哈希：Argon2id，基线 19 MiB / 2 次迭代 / 并行度 1（账号契约）。
 * @node-rs/argon2 默认即 m=19456,t=2,p=1，显式声明以防上游默认值漂移。
 * 哈希计算在 SQLite 写事务之外执行。
 */
export async function hashPassword(password: string): Promise<string> {
  return hash(password, { memoryCost: 19456, timeCost: 2, parallelism: 1 });
}

export async function verifyPassword(encoded: string, password: string): Promise<boolean> {
  try {
    return await verify(encoded, password);
  } catch {
    return false;
  }
}
