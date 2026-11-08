// Offline fixture for a manual CLI smoke test. Never contacts a model provider.
import { createAssistantMessageEventStream } from "@earendil-works/pi-ai";
import type { ExtensionAPI } from "@earendil-works/pi-coding-agent";

export default function (pi: ExtensionAPI): void {
  pi.registerProvider("pi-review-smoke", {
    api: "pi-review-smoke",
    baseUrl: "https://offline.invalid",
    apiKey: "offline-test",
    models: [{
      id: "fixture", name: "Offline review fixture", reasoning: false, input: ["text"],
      cost: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0 },
      contextWindow: 1000000, maxTokens: 4096,
    }],
    streamSimple(model, context) {
      const stream = createAssistantMessageEventStream();
      queueMicrotask(() => {
        const user = context.messages.filter((message) => message.role === "user").at(-1);
        const prompt = JSON.stringify(user?.content);
        const results = context.messages.filter((message) => message.role === "toolResult");
        let content;
        if (prompt.includes("SMOKE_CHILD_")) {
          content = [{ type: "text", text: "SMOKE_CHILD_FINISHED" }];
        } else if (results.length) {
          if (results.length !== 2 || results.some((result) => result.isError || !JSON.stringify(result.content).includes("SMOKE_CHILD_FINISHED"))) {
            throw new Error("Parent did not receive both completed child reports");
          }
          content = [{ type: "text", text: "# Pi PR Review Toolkit\n\nBoth foreground children finished.\n<!-- pi-pr-review:complete -->" }];
        } else {
          content = [1, 2].map((n) => ({
            type: "toolCall", id: `smoke-${n}`, name: "Agent", arguments: {
              subagent_type: "general-purpose", prompt: `SMOKE_CHILD_${n}`,
              description: `Offline smoke child ${n}`, run_in_background: true,
              model: "pi-review-smoke/fixture",
            },
          }));
        }
        const output = {
          role: "assistant", api: model.api, provider: model.provider, model: model.id,
          timestamp: Date.now(), content,
          stopReason: content[0].type === "toolCall" ? "toolUse" : "stop",
          usage: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0, totalTokens: 0,
            cost: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0, total: 0 } },
        };
        stream.push({ type: "start", partial: output });
        stream.push({ type: "done", reason: output.stopReason, message: output });
        stream.end();
      });
      return stream;
    },
  });
}
