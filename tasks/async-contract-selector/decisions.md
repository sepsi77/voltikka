# Decisions

- Use interaction-gated async search instead of server-rendered `<select>` options so article crawlers do not receive every contract name in the initial DOM.
- Preserve default auto-cheapest selection; explicit user selection is optional and happens through search results.
- Search results require at least 2 characters and are capped to 8 matches to keep Livewire responses and the DOM small.
- The component still calculates the default cheapest contracts server-side, but only the visible chosen contracts are rendered before user interaction.
