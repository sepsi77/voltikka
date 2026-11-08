import type { ExtensionAPI } from "@earendil-works/pi-coding-agent";

/** Keep non-interactive Pi alive while it awaits tools with unreferenced handles. */
export default function (pi: ExtensionAPI): void {
  let keepAlive: ReturnType<typeof setInterval> | undefined;

  pi.on("session_start", () => {
    keepAlive ??= setInterval(() => {}, 60_000);
  });

  pi.on("session_shutdown", () => {
    if (keepAlive) {
      clearInterval(keepAlive);
      keepAlive = undefined;
    }
  });
}
