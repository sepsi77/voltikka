# Decisions

- The component supplies the full HTML title because `layouts.app` renders the supplied title without a brand suffix.
- The HTML title and H1 are different on purpose. The title targets market comparison. The H1 keeps the wider contract intent.
- The broad-intent hero sentence is neutral. It renders when household contracts exist. The zero-contract state omits it and states that no household contract is in the comparison.
- The hero price sentence renders only when a calculated minimum exists. It uses the selected annual consumption.
- The market section always has a heading. Without a compatible same-date comparison, it shows only the approved unavailable message.
- The Spot section always has a heading. Without a household Spot contract, it states this and links to all Spot contracts.
- The offer empty state does not predict when a seller will publish an offer. It states only the current state and the daily update schedule.
- No pricing source, audience rule, market metric, business heading, FAQ rule, or delivery-area rule changed.
