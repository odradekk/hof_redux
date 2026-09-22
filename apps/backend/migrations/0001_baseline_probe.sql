-- 工程基线探测表：供“重启持久性”自动检查写入/读取探针行。
-- 这是工程验证设施，不是玩法业务表；玩法结构由后续模块任务以新迁移追加。
CREATE TABLE baseline_probe (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  note TEXT NOT NULL,
  written_at TEXT NOT NULL
) STRICT;
