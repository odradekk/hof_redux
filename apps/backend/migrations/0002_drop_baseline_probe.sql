-- 移除工程基线阶段的探针表：重启持久性验证改用 schema_migrations 元数据，
-- 生产 schema 不再包含测试设施表。该表从未承载业务数据。
DROP TABLE IF EXISTS baseline_probe;
