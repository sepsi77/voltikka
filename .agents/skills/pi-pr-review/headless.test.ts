import assert from "node:assert/strict";
import test from "node:test";
import register from "./headless.ts";

function handler() {
  let callback;
  register({ on: (event, fn) => { assert.equal(event, "tool_call"); callback = fn; } });
  return callback;
}

test("background requests become awaited isolated calls", () => {
  const call = { toolName: "Agent", input: { run_in_background: true, isolated: false, isolation: "worktree" } };
  assert.equal(handler()(call), undefined);
  assert.deepEqual(call.input, { run_in_background: false, isolated: true, isolation: "off" });
});

test("unrelated tools are unchanged", () => {
  const call = { toolName: "read", input: { path: "AGENTS.md" } };
  handler()(call);
  assert.deepEqual(call.input, { path: "AGENTS.md" });
});

test("scheduled and resumed agents are refused", () => {
  for (const input of [{ schedule: "+1h" }, { resume: "previous" }]) {
    assert.equal(handler()({ toolName: "Agent", input }).block, true);
  }
});

test("reviewers use the runner's full model ID", () => {
  process.env.PI_PR_REVIEW_MODEL = "openai-codex/gpt-6.1-sol";
  try {
    const call = { toolName: "Agent", input: { model: "gpt-6.1-sol" } };
    handler()(call);
    assert.equal(call.input.model, "openai-codex/gpt-6.1-sol");
  } finally {
    delete process.env.PI_PR_REVIEW_MODEL;
  }
});
