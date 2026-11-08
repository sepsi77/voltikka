#!/usr/bin/env python3
"""Run Pi in an isolated process group and remove all remaining descendants."""

from __future__ import annotations

import os
import signal
import subprocess
import sys
import time
from collections.abc import Sequence

TERMINATION_GRACE_SECONDS = 2.0


def signal_group(process_group_id: int, signal_number: int) -> bool:
    try:
        os.killpg(process_group_id, signal_number)
        return True
    except ProcessLookupError:
        return False


def stop_process_group(process_group_id: int) -> None:
    if not signal_group(process_group_id, signal.SIGTERM):
        return

    deadline = time.monotonic() + TERMINATION_GRACE_SECONDS
    while time.monotonic() < deadline:
        try:
            os.killpg(process_group_id, 0)
        except ProcessLookupError:
            return
        time.sleep(0.1)

    signal_group(process_group_id, signal.SIGKILL)


def shell_exit_code(return_code: int) -> int:
    if return_code < 0:
        return 128 - return_code
    return return_code


def run(log_path: str, pi_arguments: Sequence[str]) -> int:
    with open(log_path, "wb") as log_file:
        try:
            process = subprocess.Popen(
                ["pi", *pi_arguments],
                stdout=log_file,
                stderr=subprocess.STDOUT,
                start_new_session=True,
            )
        except OSError as error:
            log_file.write(f"Failed to start Pi: {error}\n".encode())
            return 127

        process_group_id = process.pid
        forwarded_signals = 0

        def forward_signal(signal_number: int, _frame: object) -> None:
            nonlocal forwarded_signals
            forwarded_signals += 1
            target_signal = signal.SIGKILL if forwarded_signals > 1 else signal_number
            signal_group(process_group_id, target_signal)

        handled_signals = [signal.SIGINT, signal.SIGTERM]
        if hasattr(signal, "SIGHUP"):
            handled_signals.append(signal.SIGHUP)
        for signal_number in handled_signals:
            signal.signal(signal_number, forward_signal)

        try:
            return shell_exit_code(process.wait())
        finally:
            stop_process_group(process_group_id)


def main() -> int:
    if len(sys.argv) < 3:
        print(f"Usage: {sys.argv[0]} <logfile> <pi-args...>", file=sys.stderr)
        return 2
    return run(sys.argv[1], sys.argv[2:])


if __name__ == "__main__":
    raise SystemExit(main())
