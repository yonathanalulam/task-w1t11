# Implementation Decisions and Clarifications

## 1. Compliance KPI Terminology Mismatch
* **Question:** How should the domain mismatch regarding the listed compliance KPIs be handled?
* **My Understanding:** The product is framed as a regulatory operations portal for credentialed legal practitioners, but the listed compliance KPIs (rescue volume, recovery rate, adoption conversion, average shelter stay, donation mix, and supply turnover) read like a different domain. This appears to be a prompt inconsistency rather than an instruction to change the entire product domain.
* **Solution:** Use the exact KPI names from the prompt verbatim. As confirmed previously, these six labels will be treated strictly as the required KPI names without altering the core legal/regulatory focus of the platform.

## 2. Appointment Hold Duration
* **Question:** How long should a temporary appointment hold last before automatic release?
* **My Understanding:** The prompt requires held booking states with real-time conflict detection to prevent overbooking by concurrent users, but it does not define the exact duration for these temporary holds.
* **Solution:** Use a 10-minute hold expiration by default, enforced server-side and clearly visible in the UI. This is a conventional, safe default that preserves the booking intent and supports concurrency safety without narrowing the product scope.