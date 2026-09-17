# Read-only real-source wording check

## Scope

The review used existing local SQLite public source snapshots only. It made no network requests, application bootstrap calls, application LLM calls, or database writes. The latest local source observation was 2026-08-11. One example below was last observed on 2026-08-04. These are historical wording examples, not current offers or a current price-impact sample.

The reviewer supplied all six actual source text fields and the existing input-builder tariff/unit normalization to the pure `EnergyRuleSourceProof::sourceClauses()` method. **All six examples returned null.** This checks grammar admission, not full publication validation. It does not show that all six lack a guarantee.

## Examples

### Cheap Kvartaalisähkö

`kpl3fw-cheap-energy-finland-oy-cheap-kvartaalisahko`

> Cheap Kvartaalisähkö -sopimuksessa on kiinteä energiahinta 7,49 snt/kWh + perusmaksu 0 €/kk ensimmäisen kuukauden ajan sopimuksen aloituspäivästä eteenpäin. Tämän jälkeen sopimuksen hinta noudattelee mm. markkinahintaa Nasdaq sähköjohdannaispörssissä, johon lisätään kiinteä kuukausittainen perusmaksu. Energianhinta 9,95 snt/kWh + perusmaksu 4,90€/kk. Hinta on lukittu 30.9.2026 asti. Hinta tarkistetaan kvartaaleittain ja tulevan kvartaalin hinta ilmoitetaan verkkosivuillamme aina maalis- kesä-, syys- ja joulukuun 15. päivään mennessä.

The first-month guarantee is explicit. Product-prefixed wording, combined energy/fee scope, a written-out month and linked sentences are not supported. The later lock needs separate start/scope proof. It must not become an indefinite normal-price guarantee or a fabricated observation date.

### Oomi Kiinteä 24 kk, business

`7gbreh-oomi-oy-oomi-kiintea-24-kk-yrityksille`

> Määräaikaisella Oomi Kiinteä 24 kk -sopimuksella hoidat yrityksesi sähköt kerralla kuntoon pidemmäksi aikaa. Hinta määräytyy ostohetken perusteella ja pysyy samana koko määräaikaisen sopimuskauden ajan. Oomi Kiinteä 24 kk on tarkoitettu yrityksille, joiden vuosikulutus on alle 100 000 kWh vuodessa.

A purchase-time term guarantee is explicit, but the amount is structured evidence, not repeated in the guarantee sentence. The numeric product name is also rejected. The consumption restriction cannot simply be discarded.

### Voima 6 kk

`7s6hln-imatran-seudun-sahko-oy-voima-6-kk` — last observed 2026-08-04.

> Sopimus sitoo asiakasta ja myyjää koko 6 kk:n sopimusajan. Hinta pysyy vakiona sopimuksen ajan, mikäli sähköön kohdistuviin veroihin ja viranomaismaksuihin ei tule muutoksia.

This is a qualified guarantee. Do not strip the tax/official-charge qualification to obtain an unconditional rule. The numeric name is also outside the current grammar.

### Iin Energia Yleissähkö

`bcztkj-iin-energia-oy-yleissahko`

> Jos olet jo Iin Energian asiakas, olethan yhteydessä sopimusta tehdessä, sillä saat pysyvän 1snt/kWh alennuksen kulloinkin voimassa olevaan yleissähkön myyntihintaan. Uusille asiakkaille nykyasiakkaan alennukset päivittyvät sopimukselle puolivuosittain.

The prevailing-tariff reduction is explicit, but its applicability is conditional and new-customer timing is not exact. Unknown is appropriate for an unconditional new-customer calculation. This is not a syntax-only acceptance change.

### Tyyni Vakiohinta

`oee7tf-aalto-energia-oyj-tyyni-vakiohinta`

> Energian hinta on 6,49 snt/kWh ja perusmaksu 5,99 €/kk. 1kk ilman perusmaksua. 1.9.2026 alkaen energian hinta on 13,65 snt/kWh ja perusmaksu 5,99 €/kk.

Current and future announced rates are explicit. Neither sentence proves an unchanged energy price for a finite span. Do not turn an announcement or a phase boundary into a guarantee.

### Hehku JATKUVA

`sk027a-hehku-energia-oy-hehku-jatkuva`

> Kampanjahinta 10,49 snt/kWh + perusmaksu 0 € ensimmäisen kuukauden ajan, tämän jälkeenkin vain 4,90€/kk.

> Hinta ei voi muuttua yllättäen, vaan ilmoitamme mahdollisista hinnanmuutoksista hyvissä ajoin 30 päivää etukäteen.

Energy campaign duration and normal energy continuation are unclear. The first-month fee wording cannot safely prove an energy guarantee. This is insufficient evidence, not only a grammar omission.

## Measured implementation result (source-language unit)

The original six-null result above describes the pre-change grammar. The new dedicated JSON fixtures retain the complete local public source payloads, with the same last-observed dates. Tests rebuild all input fields through the real input builder. No real source text was shortened or changed to obtain a pass.

| Full source | New result | Reason |
| --- | --- | --- |
| Cheap | Source proof, full V5 validator and locked publication pass | Exact product-prefixed combined clause proves one month at 7.49. Fee timing does not create an energy discount. |
| Oomi | Unknown | The business consumption restriction remains material; no unconditional energy rule is asserted. |
| Voima | Unknown | The tax/official-charge exception remains in the source. |
| Iin | Unknown | Existing-customer eligibility and inexact new-customer timing do not prove an unconditional first-customer formula. |
| Tyyni | Unknown | Announced current/future amounts do not prove a finite lock. |
| Hehku | Unknown | Fee duration does not supply missing energy campaign terms or a normal energy quote. |

Cheap's later 9.95 rate and 30 September lock are retained as bounded continuation context. The source does not state a lock start. They therefore do not prove a finite normal guarantee, a current normal observation, or a premium anchor. An explicit adjacent amount/start sentence plus a lock-end sentence can now prove a dated lock, with both citations. No analysis date is inferred as its start.

A separate, clearly labelled faithful full-context unconditional 24-month fixture passes the full validator and publication. It uses an explicit whole-term sentence plus exact undiscounted structured price, duration and component identity. It is not a modified Oomi or Voima offer, and does not establish an unconditional guarantee for either real offer. The whole-term sentence need not repeat the amount. Numeric product names alone still establish no guarantee.

Final local source/profile/publication/recurrence/historical scope: **189 passed, 1065 assertions**. Pint and diff checks passed. See `source-language-completion.md` for commands and remaining limits.

## Original consequence

The source layer passes its bounded synthetic cases, but practical language coverage is not established. A full-policy or producer-activation claim would be premature. A useful next language unit would support complete combined energy/fee guarantee clauses and term guarantees linked to exact structured rates, while retaining all qualifications and conflicts. It must use real full-context fixtures, not shortened excerpts.

A separate manager review also found open-ended harmless-marketing/contact suffix allowances. That safety correction is tracked in `source-context-hardening.md`; it is not a language-coverage expansion.
