"""Test the headless review runner without model calls or project data."""

import json
import os
import select
import signal
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

RUNNER = Path(__file__).with_name("run.py")
COMPLETE = (
    "# Pi PR Review Toolkit\n\nNo confirmed issues.\n<!-- pi-pr-review:complete -->"
)


def events(text=COMPLETE, reason="stop", settled=True):
    rows = [
        {"type": "agent_start"},
        {
            "type": "message_end",
            "message": {
                "role": "assistant",
                "stopReason": reason,
                "content": [{"type": "text", "text": text}],
            },
        },
    ]
    if settled:
        rows.append({"type": "agent_settled"})
    return "\n".join(json.dumps(row) for row in rows) + "\n"


class RunnerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.repo = self.root / "repo"
        self.repo.mkdir()
        self.git("init", "-b", "main")
        self.git("config", "user.email", "test@example.invalid")
        self.git("config", "user.name", "Test")
        (self.repo / "source.txt").write_text("base\n")
        self.git("add", ".")
        self.git("commit", "-m", "base")
        self.git("switch", "-c", "feature")
        self.git("switch", "main")
        (self.repo / "main-only.txt").write_text("main advanced\n")
        self.git("add", "main-only.txt")
        self.git("commit", "-m", "advance main")
        self.git("switch", "feature")
        (self.repo / "source.txt").write_text("feature\n")
        self.git("commit", "-am", "feature")
        self.output = self.repo / "tasks" / "review.md"
        self.capture = self.root / "invocation.json"
        self.fixture = self.root / "events.jsonl"
        self.fixture.write_text(events())
        self.bin = self.root / "bin"
        self.bin.mkdir()
        pi = self.bin / "pi"
        pi.write_text(
            f"#!{sys.executable}\n"
            + """
import json, os, pathlib, signal, subprocess, sys
args = sys.argv[1:]
if os.environ.get("SPAWN_CHILD"):
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
    child = subprocess.Popen([sys.executable, "-c", "import signal; signal.pause()"])
    if os.environ.get("GRACEFUL_CANCEL"):
        def graceful_cancel(*args):
            print(pathlib.Path(os.environ["EVENTS"]).read_text(), end="")
            sys.exit(0)
        signal.signal(signal.SIGTERM, graceful_cancel)
    with open(os.environ["READY_FIFO"], "w") as ready:
        ready.write(f"{os.getpid()} {child.pid}\\n")
    if os.environ.get("HANG_PI"):
        signal.pause()
if os.environ.get("CREATE_REPORT"):
    report = pathlib.Path(os.environ["CREATE_REPORT"])
    report.parent.mkdir(parents=True)
    report.write_text("created during review")
pathlib.Path(os.environ["CAPTURE"]).write_text(json.dumps({
    "args": args, "cwd": os.getcwd(), "stdin": sys.stdin.read(),
    "prompt": next((pathlib.Path(a[1:]).read_text() for a in args if a.startswith("@")), ""),
    "review_model": os.environ.get("PI_PR_REVIEW_MODEL"),
}))
if os.environ.get("MOVE_HEAD"):
    subprocess.run(["git", "commit", "--allow-empty", "-m", "external change"], check=True, stdout=subprocess.DEVNULL)
print(pathlib.Path(os.environ["EVENTS"]).read_text(), end="")
sys.exit(int(os.environ.get("PI_EXIT", "0")))
"""
        )
        pi.chmod(0o755)
        self.subagents = self.root / "subagents"
        self.subagents.mkdir()
        (self.subagents / "index.ts").write_text("// Test fixture\n")
        self.env = {
            **os.environ,
            "PATH": f"{self.bin}{os.pathsep}{os.environ['PATH']}",
            "PI_SUBAGENTS_PATH": str(self.subagents),
            "CAPTURE": str(self.capture),
            "EVENTS": str(self.fixture),
            "TMPDIR": str(self.root),
            "PYTHONDONTWRITEBYTECODE": "1",
        }

    def git(self, *args):
        return subprocess.run(
            ["git", *args],
            cwd=self.repo,
            check=True,
            capture_output=True,
            text=True,
        ).stdout.strip()

    def run_review(self, *args):
        return subprocess.run(
            [sys.executable, str(RUNNER), "--output", str(self.output), *args],
            cwd=self.repo,
            env=self.env,
            input="must not reach pi",
            text=True,
            capture_output=True,
            timeout=20,
            check=False,
        )

    def test_review_loads_tools_and_writes_only_final_report(self):
        result = self.run_review()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.output.read_text(), COMPLETE + "\n")
        invocation = json.loads(self.capture.read_text())
        self.assertEqual(Path(invocation["cwd"]).resolve(), self.repo.resolve())
        self.assertEqual(invocation["stdin"], "")
        args = invocation["args"]
        self.assertIn("--no-extensions", args)
        self.assertIn(str(self.subagents), args)
        self.assertIn("json", args)
        self.assertIn("read,bash,grep,find,ls,Agent", args)
        self.assertIn("openai-codex/gpt-6.1-sol", args)
        self.assertEqual(invocation["review_model"], "openai-codex/gpt-6.1-sol")
        merge_base = self.git("merge-base", "main", "HEAD")
        base_tip = self.git("rev-parse", "main")
        head = self.git("rev-parse", "HEAD")
        self.assertNotEqual(merge_base, base_tip)
        self.assertIn(f"merge-base: {merge_base}", invocation["prompt"])
        self.assertIn(f"Use `git diff {merge_base} {head}`.", invocation["prompt"])
        self.assertNotIn(f"Use `git diff {base_tip} {head}`.", invocation["prompt"])
        self.assertIn("run_in_background: false", invocation["prompt"])
        self.assertIn("Do not run tests", invocation["prompt"])
        self.assertIn("suggestions only", invocation["prompt"])

    def test_empty_committed_range_still_reviews_local_changes(self):
        # Voltikka often works directly on main: base == HEAD, work is uncommitted.
        (self.repo / "source.txt").write_text("local change\n")
        result = self.run_review("--base", "HEAD")
        self.assertEqual(result.returncode, 0, result.stderr)
        prompt = json.loads(self.capture.read_text())["prompt"]
        head = self.git("rev-parse", "HEAD")
        self.assertIn(f"Use `git diff {head} {head}`.", prompt)
        self.assertIn("The committed range is EMPTY", prompt)
        self.assertIn("staged, unstaged and untracked source changes", prompt)

    def test_non_empty_range_has_no_empty_range_clause(self):
        result = self.run_review()
        self.assertEqual(result.returncode, 0, result.stderr)
        prompt = json.loads(self.capture.read_text())["prompt"]
        self.assertNotIn("The committed range is EMPTY", prompt)

    def test_sequential_and_test_permission_are_explicit(self):
        result = self.run_review("--execution", "sequential", "--run-tests")
        self.assertEqual(result.returncode, 0, result.stderr)
        prompt = json.loads(self.capture.read_text())["prompt"]
        self.assertIn("sequential", prompt)
        self.assertIn("Only the tests reviewer may execute", prompt)
        self.assertIn("Do not make live application-provider calls", prompt)

    def test_does_not_overwrite_report(self):
        self.output.parent.mkdir()
        self.output.write_text("existing report")
        result = self.run_review()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.output.read_text(), "existing report")
        self.assertFalse(self.capture.exists())

    def test_report_created_during_review_is_preserved(self):
        self.env["CREATE_REPORT"] = str(self.output)
        result = self.run_review()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.output.read_text(), "created during review")

    def test_empty_later_run_cannot_reuse_an_earlier_report(self):
        self.fixture.write_text(
            events() + '{"type":"agent_start"}\n{"type":"agent_settled"}\n'
        )
        result = self.run_review()
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.output.exists())

    def test_cancel_stops_ignoring_pi_and_its_child(self):
        self.check_child_cleanup(cancel=True)

    def test_cancel_does_not_save_a_report_even_if_pi_exits_zero(self):
        self.env["GRACEFUL_CANCEL"] = "1"
        self.check_child_cleanup(cancel=True)

    def test_normal_exit_stops_remaining_child(self):
        self.check_child_cleanup(cancel=False)

    def check_child_cleanup(self, *, cancel):
        fifo = self.root / "ready"
        os.mkfifo(fifo)
        ready_fd = os.open(fifo, os.O_RDWR | os.O_NONBLOCK)
        self.addCleanup(os.close, ready_fd)
        self.env.update(SPAWN_CHILD="1", READY_FIFO=str(fifo))
        if cancel:
            self.env["HANG_PI"] = "1"
        process = subprocess.Popen(
            [sys.executable, str(RUNNER), "--output", str(self.output)],
            cwd=self.repo,
            env=self.env,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
        )
        pids = []
        try:
            self.assertTrue(
                select.select([ready_fd], [], [], 10)[0], "Fake Pi did not start"
            )
            pids = [int(pid) for pid in os.read(ready_fd, 1024).split()]
            if cancel:
                process.send_signal(signal.SIGTERM)
            try:
                _, stderr = process.communicate(timeout=12)
            except subprocess.TimeoutExpired:
                self.fail("One cancellation did not stop Pi within 12 seconds")
            if cancel:
                self.assertNotEqual(process.returncode, 0, stderr)
                self.assertFalse(self.output.exists())
            else:
                self.assertEqual(process.returncode, 0, stderr)
            for pid in pids:
                state = subprocess.run(
                    ["ps", "-o", "stat=", "-p", str(pid)],
                    capture_output=True,
                    text=True,
                    check=False,
                ).stdout.strip()
                self.assertTrue(
                    not state or state.startswith("Z"),
                    f"Process {pid} still runs: {state}",
                )
        finally:
            if pids:
                try:
                    os.killpg(pids[0], signal.SIGKILL)
                except ProcessLookupError:
                    pass
            if process.poll() is None:
                process.send_signal(signal.SIGTERM)
            process.communicate(timeout=10)

    def test_bad_base_and_missing_extension_fail_before_model_call(self):
        result = self.run_review("--base", "missing-ref")
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.capture.exists())
        self.env["PI_SUBAGENTS_PATH"] = str(self.root / "missing")
        result = self.run_review()
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.capture.exists())

    def test_refuses_other_billing_provider(self):
        result = self.run_review("--model", "openai/example")
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.capture.exists())

    def test_json_mode_model_error_is_not_success(self):
        self.fixture.write_text(events(reason="error"))
        result = self.run_review()
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.output.exists())
        self.assertIn("log", result.stderr.lower())

    def test_malformed_event_shapes_fail_without_saving_a_report(self):
        for message in (
            None,
            "not a message",
            [],
            {"role": "assistant", "stopReason": "stop", "content": None},
            {"role": "assistant", "stopReason": "stop", "content": 7},
            {"role": "assistant", "stopReason": "stop", "content": [None]},
            {"role": "assistant", "stopReason": "stop", "content": ["block"]},
            {
                "role": "assistant",
                "stopReason": "stop",
                "content": [{"type": "text", "text": 7}],
            },
        ):
            with self.subTest(message=message):
                self.fixture.write_text(
                    json.dumps({"type": "message_end", "message": message})
                    + '\n{"type":"agent_settled"}\n'
                )
                result = self.run_review()
                self.assertEqual(result.returncode, 1, result.stderr)
                self.assertIn(
                    "Review failed: Pi did not produce a successful, settled final answer.",
                    result.stderr,
                )
                self.assertNotIn("Traceback", result.stderr)
                self.assertFalse(self.output.exists())

    def test_non_string_text_blocks_are_skipped(self):
        rows = [
            {
                "type": "message_end",
                "message": {
                    "role": "assistant",
                    "stopReason": "stop",
                    "content": [
                        {"type": "text", "text": None},
                        {"type": "text", "text": COMPLETE},
                    ],
                },
            },
            {"type": "agent_settled"},
        ]
        self.fixture.write_text("\n".join(json.dumps(row) for row in rows) + "\n")
        result = self.run_review()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.output.read_text(), COMPLETE + "\n")

    def test_process_failure_and_missing_completion_are_not_reports(self):
        self.env["PI_EXIT"] = "7"
        result = self.run_review()
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.output.exists())
        self.env.pop("PI_EXIT")
        for data in (events(settled=False), events(text="Reviewers are running."), ""):
            with self.subTest(data=data):
                self.fixture.write_text(data)
                result = self.run_review()
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(self.output.exists())

    def test_incomplete_review_is_saved_but_not_successful(self):
        self.fixture.write_text(
            events(text=COMPLETE.replace(":complete", ":incomplete"))
        )
        result = self.run_review()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("pi-pr-review:incomplete", self.output.read_text())

    def test_changed_head_marks_report_incomplete(self):
        self.env["MOVE_HEAD"] = "1"
        result = self.run_review()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("HEAD changed", self.output.read_text())
        self.assertIn("pi-pr-review:incomplete", self.output.read_text())

    def test_intermediate_messages_and_unicode_do_not_break_framing(self):
        text = COMPLETE.replace("No confirmed", "A\u2028B\u2029C. No confirmed")
        self.fixture.write_text(
            "diagnostic on stderr\n" + events(text="Interim") + events(text=text)
        )
        result = self.run_review()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.output.read_text(), text + "\n")


if __name__ == "__main__":
    unittest.main()
