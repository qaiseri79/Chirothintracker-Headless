# NON-ARCHIVE / RESET STARTING VALUES — PRIORITY BACKLOG

**Status:** On Hold — Requires Further Deliberation  
**Priority:** HIGH (affects user dashboard accuracy for inches/weights)  
**Created:** 2026-09-28

---

## What's Not Migrated (Documented in `TrackingCalculator::runEvaluations()`)

| Component | Description | Where It Lives Now |
|-----------|-------------|-------------------|
| `userRoles()` | Role management: ecommerce, mindset, chirothin/white_label based on clinic settings | `custom_module::UserCurrentProgramDay` |
| `userProfile()` | ECK `chirothin_profile` create/repair/sync | `custom_module::UserCurrentProgramDay` |
| `UserClinician()` | Chiropractor assignment + clinic sync | `custom_module::UserCurrentProgramDay` |

**Action:** Keep in `custom_module` hooks for now. Document in `TrackingCalculator` docblock.

---

## What NEEDS Implementation (Blocking Dashboard Accuracy)

### 1. `resetStartingValue()` — Queue Worker + Cron
**Impact:** These dashboard fields are STALE until implemented:
- `field_net_inches_lost` — needs first submission's total inches
- `field_program_start_inches` — needs first submission's total inches  
- `field_overall_start_weight` — needs max weight across ALL submissions
- `field_program_start_weight` — needs max weight (net) across ALL submissions
- `field_net_weight_loss` — currently real-time via `computeNetWeightLoss()`, but full recalc uses different logic
- `field_gross_weight_loss` — same

**Current Behavior:** `TrackingCalculator` computes per-submission values (real-time). The Views-based full recalc from `ResetStartingValues` is NOT running.

**Solution:** 
- Create `RecalculateStartingValuesWorker` (QueueWorker)
- Create nightly cron hook to enqueue ALL enrolled patients
- On submit: enqueue that user for immediate update
- Nightly: full reconciliation

**No Data Migration Needed:** Cron processes ALL existing patients automatically.

### 2. Document `CalculateGoal()` Status
**Status:** ✅ Done in `TrackingCalculator::computeGoalAchieved()`  
**Note:** Real-time per submission. Full recalc may differ slightly — nightly cron corrects.

---

## Files to Create (When Ready)

| File | Purpose |
|------|---------|
| `src/RecalculateStartingValuesWorker.php` | QueueWorker: queries ALL submissions via entityQuery (no Views), updates user fields |
| `src/RecalculateStartingValuesCron.php` | Cron: enqueues ALL enrolled patients nightly |
| `headless_progress.services.yml` | Register queue worker |
| `headless_progress.cron.yml` | Register cron hook |

---

## Key Decision Points (Resolve Before Implementing)

1. **Immediate vs Delayed:** Run queue worker immediately after submit (real-time) or only nightly?
   - Immediate = heavy Views/entityQuery on every submit
   - Nightly only = inches/weights lag up to 24h

2. **Incremental Update:** Add lightweight `updateStartingValuesIncremental()` in `runEvaluations()` for real-time max-weight tracking?

3. **Views vs EntityQuery:** Original used 5 Views. Reimplement with direct entityQuery for queue worker?

4. **Batch Size:** How many users per cron run? (100? 500? all?)

---

## Related Code Locations

| File | Relevant Section |
|------|-----------------|
| `TrackingCalculator.php` | `runEvaluations()` docblock, `checkLateSubmission()` ✅ |
| `ResetStartingValues.php` | `resetStartingValue()` — source logic |
| `UserCurrentProgramDay.php` | `userRoles()`, `userProfile()`, `UserClinician()`, `CalculateGoal()` |
| `ProgressController.php` | `submit()` — where to enqueue |

---

## Next Steps (When Resuming)

1. Decide: immediate queue processing or nightly-only
2. Decide: incremental update on submit?
3. Implement `RecalculateStartingValuesWorker` with entityQuery (no Views)
4. Add cron hook
5. Test with existing patient data
6. Monitor first cron run performance