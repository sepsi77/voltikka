# Plausible events for bill comparison usage

Add custom Plausible analytics tracking when visitors use the in-listing bill comparison feature on electricity contract comparison pages.

User-provided API: call `plausible('Event name', options)` manually from JavaScript. Guard calls so pages still work when Plausible is unavailable.

## Production verification follow-up

Verify the complete Livewire-to-Plausible browser path. The event listener must accept the payload shape that the installed Livewire version sends and must have a JavaScript regression test.
