import test from "node:test";
import assert from "node:assert/strict";
import { findDuplicateKeys } from "../src/duplicateKeys.js";

test("顶层重复键被拒绝", () => {
  const diags = findDuplicateKeys('{"a": 1, "a": 2}', "t.json");
  assert.equal(diags.length, 1);
  assert.equal(diags[0]!.code, "E_DUP_KEY");
});

test("数组内对象的重复键被拒绝（正常编辑路径）", () => {
  const diags = findDuplicateKeys('{"jobs": [{"id": "job.100", "name": "a", "name": "b"}]}', "t.json");
  assert.ok(diags.some((d) => d.code === "E_DUP_KEY"), JSON.stringify(diags));
});

test("嵌套对象的重复键被拒绝", () => {
  const diags = findDuplicateKeys('{"a": {"x": 1, "x": 2}}', "t.json");
  assert.ok(diags.some((d) => d.code === "E_DUP_KEY"));
});

test("不同对象中的同名键合法", () => {
  const diags = findDuplicateKeys('{"a": {"x": 1}, "b": {"x": 2}, "arr": [{"x": 1}, {"x": 2}]}', "t.json");
  assert.deepEqual(diags, []);
});

test("转义键按解码值归一化", () => {
  const diags = findDuplicateKeys('{"a": 1, "\\u0061": 2}', "t.json");
  assert.ok(diags.some((d) => d.code === "E_DUP_KEY"), JSON.stringify(diags));
});

test("合法 JSON 无诊断", () => {
  const diags = findDuplicateKeys('{"a": [1, {"b": "x\\n"}], "c": null}', "t.json");
  assert.deepEqual(diags, []);
});

test("失衡结构报告 JSON 错误", () => {
  const diags = findDuplicateKeys('{"a": 1', "t.json");
  assert.ok(diags.some((d) => d.code === "E_JSON"));
});
