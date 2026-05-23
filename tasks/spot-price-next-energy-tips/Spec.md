# Spot price next energy tips

Ensure the `/spot-price` “Kodin energiavinkit” appliance cards recommend actionable upcoming hours, not hours that have already started or passed.

Requirements:
- Exclude the current hour from recommendation windows so e.g. 17:45 never recommends 17:00.
- Continue to use official today/tomorrow spot prices only for these cards.
- Make copy reflect upcoming recommendations, including tomorrow when applicable.
- Add/adjust tests for the current-hour exclusion.
