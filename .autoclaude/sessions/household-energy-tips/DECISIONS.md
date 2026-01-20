# Decisions Log

## 2026-01-20

### Time Window Constraints

**Decision:** Use specific time windows for each household activity based on practical constraints.

| Activity | Time Window | Rationale |
|----------|-------------|-----------|
| Sauna | 17:00-22:00 | Finnish tradition of evening sauna |
| Laundry | 07:00-22:00 | Noise consideration for apartment buildings |
| Dishwasher | 18:00-08:00 | After dinner, can delay start overnight |
| Water heater | 00:00-24:00 | No noise, timer-controlled |
| EV charging | Full day (existing) | Can charge anytime |

### Power Consumption Defaults

**Decision:** Use typical Finnish household appliance power ratings.

| Appliance | Power | Duration | Total kWh |
|-----------|-------|----------|-----------|
| Sauna heater | 8 kW | 1 hour | 8 kWh |
| Washing machine | 2 kW avg | 2 hours | 4 kWh |
| Dishwasher | 1.5 kW avg | 2 hours | 3 kWh |
| Water heater | 2.5 kW | 1 hour | 2.5 kWh |
| EV charging | 3.7 kW | 3 hours | 11.1 kWh |

### UI Design

**Decision:** Create unified "Kodin energiavinkit" section with consistent card design.

- Each card uses the coral design system colors
- Shows: activity icon, name, time constraints, recommended time, cost, savings
- Green highlight for recommended times
- Savings shown in euros for easy comparison
- Compact cards that fit in a responsive 3-column grid

### Midnight-Crossing Time Ranges

**Decision:** Implement proper handling for time ranges that cross midnight (e.g., 18:00-08:00).

The `getPricesInTimeRange()` method detects when `startHour > endHour` and includes hours from startHour to 23 AND 0 to endHour. This allows the dishwasher to recommend overnight operation when prices are cheapest.
