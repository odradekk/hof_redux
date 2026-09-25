import test from "node:test";
import assert from "node:assert/strict";
import { AuthAdmission, AuthenticationBusyError } from "../src/auth/admission.js";

test("密码工作并发与排队均有界，队满时明确拒绝", async () => {
  const admission = new AuthAdmission();
  let release!: () => void;
  const gate = new Promise<void>((resolve) => { release = resolve; });
  let active = 0;
  let peak = 0;
  const work = () => admission.runPassword(async () => {
    active++;
    peak = Math.max(peak, active);
    await gate;
    active--;
  });
  const accepted = Array.from({ length: 132 }, work);
  try {
    await assert.rejects(work(), AuthenticationBusyError);
    assert.equal(peak, 4);
  } finally {
    release();
    await Promise.all(accepted);
  }
  assert.equal(active, 0);
});
