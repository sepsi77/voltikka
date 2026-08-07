# Historical reconstruction addendum v3

This request is a retrospective reconstruction. Follow these rules in addition to the normal system prompt:

- The identity and structured components in the input are exact facts for the historical episode.
- Descriptive text can be a backcast from the first later immutable source or from retained last-observed fields. It is not proven contemporaneous with the episode.
- Read `_historical_provenance` before you use prose. It is control metadata, not seller evidence. Never cite `_historical_provenance` or any of its fields as seller evidence, and never describe backcast text as contemporaneous evidence.
- Backcast prose can recover stable classification, recurring cadence, and supplier adjustment-mechanism facts. It cannot supply a historical billed amount, normal amount, consumption-effect number, package fact, promotion duration, absolute date, recurring-period date, or continuation date.
- Bind each numeric phase component to one cited structured `components[N]` fact. Its canonical component type, payment unit, price role, amount, normal amount, and discount scope must all describe that same source component. Do not use an equal number from another source type, unit, or component.
- `EurPerMonth` maps only to `eur_per_month`. `CentPerKiwattHour` maps only to `cents_per_kwh`. A source `Monthly` charge can use `monthly_fee`, or monthly `flat_fee` where the normal validator permits that equivalence. Do not infer a unit from component type or prose.
- A component `amount` can be the same source component's original price. It can instead be the deterministic result of that component's active structured `UntilDate` or `NFirstMonth` discount. `normal_amount` can only be that same source component's original price. Use the matching current, introductory, or normal role and cite the structured component.
- One structured source component can occur only once as a billed component in one phase. Do not add a duplicate or other extra billed component.
- An `UntilDate` discounted phase must end on that component's exact `discount_until_date`. Its normal continuation must start exactly one day later. A first-N-month discounted phase and its continuation must use that same component's exact `discount_n_first_months`. A discount date or duration from another component cannot support the phase.
- `recurring_schedule.current_period_start` and `current_period_end` must be null. These episode inputs have no exact structured recurring-period date fields. Promotion dates are not recurring-period dates.
- `pricing.consumption_effect` numeric fields must be null. The historical structured component input has no typed source field for those mechanism values.
- A package must bind its fee to an exact `Monthly` / `EurPerMonth` source component. Its excess rate and allowance must bind to one exact applicable per-kWh source component with a valid `NFirstKwh` marker. The marker is the annual value and must equal 12 times the monthly `included_kwh`. Equal unrelated numbers are not package evidence.
- Never use `misleading_first_12_months=detected` for backcast evidence. A structured discount is already deterministically computable, and later prose cannot prove contemporaneous deception. Use `uncertain`, `not_detected`, or `not_assessable` as the normal schema and validator permit.
- Leave unsupported future amounts and exact dates null or unknown as allowed by the schema. Do not invent an absent price, date, duration, mechanism, discount, or other fact.
- Keep evidence paths relative to the flat input. Cite exact structured leaves and normalized prose leaves in the same format as a normal interpretation.
