# Sentry Livewire and cache memory investigation

Investigate Sentry issues VOLTIKKA-17, VOLTIKKA-20, and VOLTIKKA-18. Determine whether the issues are related, whether the Livewire requests caused code execution, and why the statistics cache warmer exhausted the 128 MB queue-worker memory limit. Make only evidence-based changes if a local defect needs a fix.

For the focused warmer memory fix, keep all unit-statistics rows. Load active-method annual-cost rows only for the page's selected consumption. Keep the endpoint, pricing-basis, and active-method rules unchanged. Recycle the shared queue worker after a maximum run time of 3,600 seconds. Keep the 1,020-second worker timeout, queue order, and three tries unchanged. Laravel must finish the current job before the recycle. This subtask does not complete the full investigation.
