import type { ExtensionAPI } from "@earendil-works/pi-coding-agent";

/** Keep review delegation inside the lifetime of a one-shot Pi process. */
export default function (pi: ExtensionAPI): void {
  pi.on("tool_call", (event) => {
    if (event.toolName !== "Agent") return;
    if (event.input.schedule || event.input.resume) {
      return { block: true, reason: "This review permits one immediate run per role, not schedules or resumes." };
    }
    // Concurrent foreground calls still run in parallel. Unlike detached calls,
    // their tool results must arrive before print/JSON mode can exit.
    event.input.run_in_background = false;
    event.input.isolated = true;
    event.input.isolation = "off";
    // A bare model ID such as "gpt-6.1-sol" can resolve to the API-billed openai/
    // provider. Every reviewer uses the runner's full subscription model ID.
    const model = process.env.PI_PR_REVIEW_MODEL;
    if (model) event.input.model = model;
  });
}
