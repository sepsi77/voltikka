# Decisions

- **Retry all error responses, not a list of statuses.** The status code of the stalled response is
  unknown (it was never logged). Two extra requests for a permanent error (for example a bad token)
  cost nothing, and the hourly run has a 60-minute overlap lock. Worst case is about 3 x 30 s plus 2 s.
- **Keep the 1 s retry delay and the 30 s timeout.** The failures are single slow requests, and the next
  hourly run always succeeded. If failures stay frequent, the `http_<status>` reason will show the next step.
- **Status code only.** The reporter rules forbid response bodies, URLs and upstream text, because the
  URL contains the ENTSO-E token. The integer status is safe.
- **Suppress only the scheduler exception for exit code 1 of the three self-reporting imports.** These
  commands catch every Throwable and return 1 only after a reporter `fail()` call. A PHP fatal error
  (255) or a kill does not reach the reporter, so those stay reportable. Message matching is on framework
  text from `ScheduleRunCommand::runEvent()`; the unit test builds it with
  `Application::formatCommandString()`. Not chosen: `runInBackground()` for the imports, because it
  changes scheduler blocking and the non-zero-exit log listener does not get background exits.
- **The `contracts:fetch` refused-checkpoint path now calls `fail('checkpoint')`**, because before this
  change the scheduler exception was its only Issue.
