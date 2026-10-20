# Approval when no fuel consumption is recorded

Fuel Efficiency may be excluded only when the trip has neither a positive-liter fuel transaction nor a positive actual-fuel record in route history. Unverified fuel records block this exclusion and must be reviewed. Other missing criteria retain their existing evidence and approval requirements.

The fuel score remains null with status `excluded` and reason `No recorded fuel consumption`. It receives neither zero nor perfect credit. Original configured weights remain stored unchanged. The remaining applicable weight is normalized to 100: with default weights, five scores of 80 yield `68 / 85 × 100 = 80`, rather than 68 or an invented fuel score.

The saved timing snapshot and existing approval audit include the exclusion, applicable weight and calculation basis. Existing finalized evaluations are not recalculated or modified by this change. A zero applicable-weight evaluation cannot be finalized.

Verification: 11 isolated no-fuel approval tests, existing scoring/role suite (41), document assessment suite (12), delivery KPI suite (15), and audit presentation suite (11) passed. The actual TRP-024 Admin form was tested with the finalization POST intercepted: fuel no longer blocks submission. No real evaluation was finalized. Temporary sessions were cleaned.
