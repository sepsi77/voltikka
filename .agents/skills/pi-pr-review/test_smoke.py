"""Exercise real Pi and real subagents with an offline, zero-cost model fixture."""

import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from run import final_report

HERE = Path(__file__).resolve().parent
SUPPORT = HERE
AGENT_DIR = Path(os.environ.get("PI_CODING_AGENT_DIR", Path.home() / ".pi/agent"))
SUBAGENTS = Path(
    os.environ.get(
        "PI_SUBAGENTS_PATH",
        AGENT_DIR / "npm/node_modules/@tintinweb/pi-subagents",
    )
).absolute()


@unittest.skipUnless(
    shutil.which("pi") and SUBAGENTS.exists(), "Pi and pi-subagents required"
)
class SmokeTests(unittest.TestCase):
    def test_real_parallel_agents_finish_before_parent_exits(self):
        with tempfile.TemporaryDirectory(prefix="pi-review-smoke-") as directory:
            work = Path(directory)
            log = work / "events.log"
            args = [
                sys.executable,
                str(SUPPORT / "pi-process-supervisor.py"),
                str(log),
                "--offline",
                "--no-approve",
                "--no-extensions",
                "--no-skills",
                "--no-prompt-templates",
                "--no-context-files",
                "-e",
                str(SUBAGENTS),
                "-e",
                str(SUPPORT / "pi-print-keepalive.ts"),
                "-e",
                str(HERE / "headless.ts"),
                "-e",
                str(HERE / "smoke-provider.ts"),
                "--tools",
                "Agent",
                "--model",
                "pi-review-smoke/fixture",
                "--thinking",
                "off",
                "--no-session",
                "--mode",
                "json",
                "Run the offline smoke check.",
            ]
            process = subprocess.Popen(args, cwd=work, stdin=subprocess.DEVNULL)
            try:
                code = process.wait(timeout=60)
            except subprocess.TimeoutExpired:
                process.terminate()  # Let the supervisor clean its process group.
                process.wait(timeout=10)
                self.fail(f"Pi smoke timed out:\n{log.read_text()}")
            self.assertEqual(code, 0, log.read_text())
            self.assertIn("Both foreground children finished", final_report(log=log))
            lifecycle = []
            for line in log.read_text().split("\n"):
                try:
                    event = json.loads(line)
                except ValueError:
                    continue
                if event.get("toolName") == "Agent" and event.get("type") in (
                    "tool_execution_start",
                    "tool_execution_end",
                ):
                    self.assertFalse(event.get("isError", False), str(event))
                    lifecycle.append(event["type"])
            self.assertEqual(
                lifecycle,
                [
                    "tool_execution_start",
                    "tool_execution_start",
                    "tool_execution_end",
                    "tool_execution_end",
                ],
            )


if __name__ == "__main__":
    unittest.main()
