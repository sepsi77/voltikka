# Sentry queue memory exhaustion 120877718

Investigate and fix production `queue:work` fatal memory exhaustion:

```
Allowed memory size of 134217728 bytes exhausted
/vendor/sentry/sentry/src/Profiling/Profile.php:315 $stack->getTrace()
```

Goals:
- Prevent Sentry profiling from exhausting 128 MB queue workers.
- Reduce memory used by queued contract price statistics cache warming where practical.
- Keep web tracing/profiling available under explicit sample-rate configuration.
- Cover the contract statistics warmer behavior with a targeted regression test.
