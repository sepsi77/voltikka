# Decisions Log

## 2026-01-20: ENTSO-E API vs Elering API for spot prices

**Context:** The project already had an EleringApiClient, but needed to implement ENTSO-E API support per the task requirements.

**Decision:** Created a new EntsoeService separate from EleringApiClient.

**Rationale:**
1. ENTSO-E is the official source for European electricity prices
2. Elering API might not always be available or may have rate limits
3. ENTSO-E provides more detailed historical data
4. Having both options allows flexibility in case one service is down
5. The APIs have very different response formats (JSON vs XML)

**Alternative considered:** Modifying EleringApiClient to support multiple sources. Rejected because the services are different enough that separate classes are cleaner.

---

## 2026-01-20: XML Parsing approach for ENTSO-E

**Context:** ENTSO-E API returns XML responses with namespaces.

**Decision:** Used SimpleXML with XPath queries and namespace registration.

**Rationale:**
1. SimpleXML is built into PHP, no extra dependencies
2. XPath allows flexible querying of XML structure
3. Namespace handling is explicit and clear
4. Good performance for typical response sizes (24-48 hours of data)

**Alternative considered:** DOMDocument. Rejected as more verbose for this use case. SimpleXML with XPath is sufficient for read-only parsing.

---

## 2026-01-20: 15-minute to hourly aggregation

**Context:** ENTSO-E may return prices at 15-minute resolution, but we store hourly data.

**Decision:** Average the 4 quarter-hourly prices to get the hourly average.

**Rationale:**
1. Simple average is the most straightforward approach
2. Matches how electricity costs would actually be calculated over an hour
3. Consistent with how other electricity price services aggregate data
4. Easy to understand and verify

**Alternative considered:** Using min/max or weighted average. Rejected as simple average is more intuitive and standard for electricity pricing.
