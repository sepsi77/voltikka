#!/usr/bin/env python3
"""Run the Pi review toolkit headlessly and save its final Markdown report."""

from __future__ import annotations

import argparse
import json
import os
import shutil
import signal
import subprocess
import sys
import tempfile
import threading
from pathlib import Path

HERE = Path(__file__).resolve().parent
SUPPORT = HERE
COMPLETE = "<!-- pi-pr-review:complete -->"
INCOMPLETE = "<!-- pi-pr-review:incomplete -->"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--base", default="main", help="Local Git base ref (default: main)"
    )
    parser.add_argument(
        "--output", required=True, type=Path, help="New Markdown report path"
    )
    parser.add_argument("--model", default="openai-codex/gpt-6.1-sol")
    parser.add_argument(
        "--execution", choices=("parallel", "sequential"), default="parallel"
    )
    parser.add_argument(
        "--run-tests", action="store_true", help="Allow safe targeted tests"
    )
    args = parser.parse_args()
    log = None
    try:
        if not args.model.startswith("openai-codex/"):
            raise ValueError("Use an openai-codex/ model for subscription billing.")
        if not shutil.which("pi"):
            raise ValueError("Pi is not on PATH. Install and authenticate Pi first.")
        output = args.output.absolute()
        if output.exists() or output.is_symlink():
            raise ValueError(f"Report already exists; choose a new path: {output}")
        root = Path(git("rev-parse", "--show-toplevel"))
        head = git("rev-parse", "HEAD")
        base = git(
            "rev-parse", "--verify", "--end-of-options", f"{args.base}^{{commit}}"
        )
        merge_base = git("merge-base", base, head)
        agent_dir = Path(
            os.environ.get("PI_CODING_AGENT_DIR", Path.home() / ".pi/agent")
        )
        subagents = Path(
            os.environ.get(
                "PI_SUBAGENTS_PATH",
                agent_dir / "npm/node_modules/@tintinweb/pi-subagents",
            )
        ).absolute()
        if not subagents.exists():
            raise ValueError(
                "Missing pi-subagents. Install npm:@tintinweb/pi-subagents in Pi, "
                "or set PI_SUBAGENTS_PATH to its installed extension path."
            )
        artifacts = Path(tempfile.mkdtemp(prefix="pi-pr-review-"))
        log = artifacts / "events.log"
        prompt = artifacts / "prompt.md"
        prompt.write_text(
            build_prompt(
                root=root,
                base=base,
                merge_base=merge_base,
                head=head,
                execution=args.execution,
                run_tests=args.run_tests,
            ),
            encoding="utf-8",
        )
        print(f"Review log: {log}", file=sys.stderr, flush=True)
        command = [
            sys.executable,
            str(SUPPORT / "pi-process-supervisor.py"),
            str(log),
            "--no-extensions",
            "--no-skills",
            "--no-prompt-templates",
            "--no-approve",
            "-e",
            str(SUPPORT / "pi-print-keepalive.ts"),
            "-e",
            str(subagents),
            "-e",
            str(HERE / "headless.ts"),
            "--tools",
            "read,bash,grep,find,ls,Agent",
            "--model",
            args.model,
            "--thinking",
            "high",
            "--mode",
            "json",
            "--no-session",
            f"@{prompt}",
        ]
        # headless.ts forces this full provider/model ID on every reviewer. A bare
        # model ID from the manager can resolve to the API-billed openai/ provider.
        os.environ["PI_PR_REVIEW_MODEL"] = args.model
        result = run_process(command=command, cwd=root)
        if result:
            raise ValueError(f"Pi process failed (exit {result}).")
        report = final_report(log=log)
        new_head = git("rev-parse", "HEAD")
        if new_head != head:
            report = (
                f"> Incomplete: HEAD changed during review from `{head}` to `{new_head}`. "
                "Run the review again against a stable checkout.\n\n"
                + report.replace(COMPLETE, INCOMPLETE)
            )
        output.parent.mkdir(parents=True, exist_ok=True)
        # Exclusive creation also protects a report created while Pi was running.
        with output.open("x", encoding="utf-8") as handle:
            handle.write(report + "\n")
        print(f"Report: {output}")
        if report.endswith(INCOMPLETE):
            print("Review is incomplete; read the report and log.", file=sys.stderr)
            return 1
        return 0
    except (OSError, ValueError, subprocess.CalledProcessError) as exc:
        print(f"Review failed: {exc}", file=sys.stderr)
        if log:
            print(f"Review log retained: {log}", file=sys.stderr)
        return 1


def git(*args: str) -> str:
    """Read Git metadata without checking out, fetching or changing refs."""
    return subprocess.run(
        ["git", *args],
        check=True,
        capture_output=True,
        text=True,
    ).stdout.strip()


def build_prompt(
    *,
    root: Path,
    base: str,
    merge_base: str,
    head: str,
    execution: str,
    run_tests: bool,
) -> str:
    """Combine the bundled role instructions with the fixed invocation contract."""
    test_rule = (
        "Only the tests reviewer may execute safe, targeted tests, from their owning "
        "directory. Laravel tests run from laravel/ as `php artisan test --filter=...` "
        "(targeted only, on the test database from the test configuration; never on "
        "laravel/database/database.sqlite and never through the production snapshot "
        "workflow). Python tests run from orchestration/ or forecast/ with that "
        "package's own test command. First check test isolation and existing "
        "dependencies. Do not install, start services, use customer data, or run DB "
        "tests beside another DB test process. Do not run Railway commands or touch "
        "production. Do not make live application-provider calls (ENTSO-E, EEX, Azure, "
        "Fingrid, FMI, Syke, Open-Meteo, LLM providers), including from tests. "
        "Use offline fixtures instead. Report exact commands and results."
        if run_tests
        else "Do not run tests, builds, lint or type checks. Review test code only and "
        "state that executable checks were not run."
    )
    empty_range = (
        "\n- The committed range is EMPTY (merge-base equals head). The scope is the "
        "staged, unstaged and untracked source changes. Report an empty scope only "
        "when those local changes are also empty."
        if merge_base == head
        else ""
    )
    return f"""Run the Pi PR Review Toolkit now using the bundled instructions below.

## Invocation contract (overrides the generic guide where they differ)

- Working directory: {root}
- Review the ENTIRE committed feature branch, not only its working-tree diff.
- Base commit: {base}; merge-base: {merge_base}; head: {head}.
- Use `git diff {merge_base} {head}`. Include staged, unstaged and untracked
  source changes separately. Do not substitute a PR base for this explicit base.{empty_range}
- Selected aspects: all applicable code, tests, errors, types, comments, simplify.
  Explain skips. Code always runs when changes exist.
- Execution: {execution}. Every Agent call MUST use run_in_background: false,
  isolated: true, subagent_type: "general-purpose". For parallel execution, put
  independent foreground Agent calls in one tool-call batch: they run concurrently
  but keep the parent waiting. For sequential execution, finish one before the next.
  Do not start background work, schedules, nested agents, or another Pi process.
- Simplify runs last, only after the other roles have no unresolved critical or
  important issues. It is suggestions only, even though its name is selected.
- Source edits are NOT authorized. No report files from agents either. No commits,
  fetches, checkouts, pushes, PR comments, merges, installs or infrastructure changes.
  The launcher, not the model, saves the final answer as Markdown.
- {test_rule}
- Read repository instructions, but this task does not authorize their build,
  fix-loop or write actions. Do not invoke another review skill or runner.
- Give each reviewer its full role instructions and the scope above. Use the current
  model for all roles. Read their results, verify findings and combine duplicates.
- Return the complete Markdown report in your FINAL assistant message, starting
  with `# Pi PR Review Toolkit`. Include scope, every role's completed/skipped/failed
  state, critical issues, important issues, suggestions, strengths, checks and limits.
  Do not return only a status update or a path. No arbitrary top-N findings cap.
- End the report with `{COMPLETE}` only after all required reviewers finish
  and results are combined. Findings do not prevent a completed review. If a role
  fails or you cannot finish, report the limitation and end with `{INCOMPLETE}`.
  No applicable changes is a completed report with an explicit empty scope.
- Record HEAD or working-tree changes observed during review. Do not claim an
  unchanged checkout without checking. Do not infer a clean review from a failed command.

{(HERE / "review.md").read_text(encoding="utf-8")}
"""


def run_process(*, command: list[str], cwd: Path) -> int:
    """Reuse the existing Pi process supervisor, forwarding cancellation to it."""
    process = subprocess.Popen(command, cwd=cwd, stdin=subprocess.DEVNULL)
    previous = {}
    cancellation_timer = None

    def cancel(number: int, _frame: object) -> None:
        nonlocal cancellation_timer
        process.send_signal(number)
        if cancellation_timer is None:
            # A second signal makes the shared supervisor kill Pi's process group.
            # Do not depend on Pi returning from its first shutdown request.
            cancellation_timer = threading.Timer(
                5.0, process.send_signal, args=(signal.SIGTERM,)
            )
            cancellation_timer.daemon = True
            cancellation_timer.start()

    for sig in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
        previous[sig] = signal.getsignal(sig)
        signal.signal(sig, cancel)
    try:
        result = process.wait()
        return 1 if cancellation_timer is not None else result
    finally:
        if cancellation_timer is not None:
            cancellation_timer.cancel()
        for sig, handler in previous.items():
            signal.signal(sig, handler)


def final_report(*, log: Path) -> str:
    """Reject errors and interim answers even when JSON mode exits with code zero."""
    last = None
    settled = False
    with log.open(encoding="utf-8") as handle:
        for line in handle:  # LF framing, not str.splitlines (U+2028 is valid JSON).
            try:
                event = json.loads(line)
            except ValueError:
                continue  # The shared supervisor also captures stderr diagnostics.
            if not isinstance(event, dict):
                continue
            if event.get("type") == "agent_start":
                settled = False
                last = None
            elif event.get("type") == "agent_settled":
                settled = True
            elif event.get("type") == "message_end":
                message = event.get("message", {})
                if not isinstance(message, dict):
                    raise ValueError(
                        "Pi did not produce a successful, settled final answer."
                    )
                if message.get("role") == "assistant":
                    last = message
    if not settled or last is None or last.get("stopReason") != "stop":
        raise ValueError("Pi did not produce a successful, settled final answer.")
    content = last.get("content", [])
    if not isinstance(content, list) or any(
        not isinstance(block, dict) for block in content
    ):
        raise ValueError("Pi did not produce a successful, settled final answer.")
    text = "\n".join(
        block["text"]
        for block in content
        if block.get("type") == "text" and isinstance(block.get("text"), str)
    ).strip()
    if not text:
        raise ValueError("Pi did not produce a successful, settled final answer.")
    if not text.startswith("# Pi PR Review Toolkit") or not text.endswith(
        (COMPLETE, INCOMPLETE)
    ):
        raise ValueError(
            "Pi returned no complete review report (completion marker missing)."
        )
    return text


if __name__ == "__main__":
    raise SystemExit(main())
