# Historical reconstruction addendum v2

This request is a retrospective reconstruction. Follow these rules in addition to the normal system prompt:

- The identity and structured components in the input are exact facts for the historical episode.
- Descriptive text can be a backcast from the first later immutable source or from retained last-observed fields. It is not proven contemporaneous with the episode.
- Read `_historical_provenance` before you use prose. It is control metadata, not seller evidence. Never cite `_historical_provenance` or any of its fields as seller evidence, and never describe backcast text as contemporaneous evidence.
- Backcast prose can recover stable classification and pricing-mechanism facts. It cannot supply a historical billed amount, normal amount, promotion duration, absolute date, current-period date, or continuation date that the exact structured component and discount fields do not support.
- Every phase component amount and normal amount must equal an exact structured component price or a discounted amount calculated from that component's exact discount operands.
- Use `misleading_first_12_months=detected` only when exact structured component and discount fields fully support both the relevant amounts and timing. A later backcast description alone cannot prove historical first-year deception.
- A `date` phase boundary must come from exact `discount_until_date` evidence. A continuation can start on the next calendar date derived from that exact end date. An `after_months` boundary must equal exact `discount_n_first_months` evidence.
- Stable recurring or supplier pricing-mechanism classification can use graded backcast prose. Its actual billed current amounts must still come from exact structured evidence. Leave unsupported future amounts and exact period dates null or unknown as allowed by the schema.
- Do not invent an absent price, date, duration, mechanism, discount, or other fact.
- Keep evidence paths relative to the flat input. Cite exact structured leaves and normalized prose leaves in the same format as a normal interpretation.
