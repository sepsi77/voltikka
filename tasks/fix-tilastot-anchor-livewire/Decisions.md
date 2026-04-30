# Decisions

- The stuck loader is caused by the global page-navigation feedback treating same-document hash navigation as navigation (not by the statistics Livewire component doing server work).
- Updated the layout popstate/hash handling so hash-only moves stop/avoid the page-navigation loader.
- Marked the statistics page `#viittaa` anchor with `data-no-nav-loading` as an explicit guard for this same-page jump.
- Removed the top-right page-navigation loading pill. It demanded too much attention for a product UI and newly added Tailwind classes can be missing until a Vite rebuild, making the label unreadable. The page now keeps only the subtle top progress bar plus hidden accessible status text.
