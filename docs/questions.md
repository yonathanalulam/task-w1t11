# Clarification Notes

## Item 1: Compliance KPI terminology mismatch

### What was unclear
The product is framed as a regulatory operations portal for credentialed legal practitioners, but the listed compliance KPIs include terms such as rescue volume, recovery rate, adoption conversion, average shelter stay, donation mix, and supply turnover, which read like a different domain.

### Interpretation
This appears to be a likely prompt inconsistency rather than an instruction to change the entire product domain away from legal/regulatory operations.

### Decision
Use the exact KPI names from the prompt verbatim: Rescue volume, Recovery rate, Adoption conversion, Average shelter stay, Donation mix, and Supply turnover.

### Why this is reasonable
The user explicitly restated the six KPI names from the prompt and confirmed they should be treated as the required labels.

## Item 2: Appointment hold duration

### What was unclear
The prompt requires held booking states with real-time conflict detection, but it does not define how long a temporary hold should last before automatic release.

### Interpretation
The system should support short-lived reservation holds during booking so concurrent users do not overbook the same slot.

### Decision
Use a 10-minute hold expiration by default, enforced server-side and visible in the UI.

### Why this is reasonable
It preserves the booking intent, supports concurrency safety, and is a conventional safe default that does not narrow product scope.
