const WINDOW_MS = 60_000;
const MAX_BUCKETS = 10_000;
const MAX_PASSWORD_WORKERS = 4;
const MAX_PASSWORD_QUEUE = 128;
const MAX_PASSWORD_WAIT_MS = 4_000;

interface Bucket {
  count: number;
  expiresAt: number;
}

export class AuthenticationBusyError extends Error {}

/** 每个应用实例独立的认证入口：按来源和目标限流，并限制密码计算积压。 */
export class AuthAdmission {
  private readonly buckets = new Map<string, Bucket>();
  private activePasswordWork = 0;
  private readonly passwordQueue: Array<() => void> = [];

  allowSource(source: string): boolean {
    return this.allow([[`source:${source}`, 300]]);
  }

  allowTarget(operation: "register" | "login", source: string, loginKey: string): boolean {
    return this.allow([
      [`target:${operation}:${loginKey}`, 30],
      [`pair:${operation}:${source}:${loginKey}`, 10],
    ]);
  }

  private allow(limits: Array<[string, number]>): boolean {
    const now = Date.now();
    if (this.buckets.size >= MAX_BUCKETS) {
      for (const [key, bucket] of this.buckets) {
        if (bucket.expiresAt <= now) this.buckets.delete(key);
      }
    }
    const current = limits.map(([key, limit]) => {
      const found = this.buckets.get(key);
      return { key, limit, bucket: found && found.expiresAt > now ? found : undefined };
    });
    if (current.some(({ bucket, limit }) => (bucket?.count ?? 0) >= limit)) return false;
    if (this.buckets.size + current.filter(({ key }) => !this.buckets.has(key)).length > MAX_BUCKETS) return false;
    for (const { key, bucket } of current) {
      this.buckets.set(key, { count: (bucket?.count ?? 0) + 1, expiresAt: bucket?.expiresAt ?? now + WINDOW_MS });
    }
    return true;
  }

  async runPassword<T>(work: () => Promise<T>): Promise<T> {
    if (this.activePasswordWork < MAX_PASSWORD_WORKERS) {
      this.activePasswordWork++;
    } else {
      if (this.passwordQueue.length >= MAX_PASSWORD_QUEUE) throw new AuthenticationBusyError("认证请求排队已满");
      await new Promise<void>((resolve, reject) => {
        const resume = () => { clearTimeout(timeout); resolve(); };
        const timeout = setTimeout(() => {
          const index = this.passwordQueue.indexOf(resume);
          if (index >= 0) {
            this.passwordQueue.splice(index, 1);
            reject(new AuthenticationBusyError("认证请求等待超时"));
          }
        }, MAX_PASSWORD_WAIT_MS);
        this.passwordQueue.push(resume);
      });
    }
    try {
      return await work();
    } finally {
      const next = this.passwordQueue.shift();
      if (next) next();
      else this.activePasswordWork--;
    }
  }
}
