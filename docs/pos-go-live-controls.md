# Go-Live eligibility for automatic sales

The configured sales start is September 14, 2026. The source company/site mappings identify ownership; being mapped alone no longer permits posting. Eligibility uses the same `GoLiveStoresService::goLiveDates` query as Dashboard > Go-Live Stores: an active branch of the entity with a first `mass regular` order, using its creation date and any order status. Dashboard display filters do not change eligibility.

Automatic processing starts at the later of the configured start date and that branch's Go-Live date. A future Go-Live date is not eligible yet. Source discovery excludes non-live stores and earlier sales. Receipt processing rechecks eligibility inside the branch transaction, including queued requests. A non-live store is an intentional exclusion, not a startup failure. Startup reports its exclusion; other eligible stores continue.

Discovery cursors include the effective eligible start date. Moving the start backward or changing the Go-Live date causes a fresh scan without deleting tracking records. Existing receipt and stock snapshots prevent repeated deductions. Pre-existing manual sales can be linked only when verified; unverified legacy sales remain review exceptions.

## Risks and boundaries

- It is safe for the desktop uploader to continue replicating all stores into the source tables. Source presence does not grant permission to create application sales or stock deductions.
- The prior implementation did not use Go-Live eligibility. The local audit found 239 already posted receipts outside the new eligibility: NNSSR 115, NNTOL 102, NNVIA 22. These postings and their movements were preserved; the new rule does not retrospectively reverse stock. They require an audited reconciliation decision. This audit is local, not evidence of production writes.
- The dashboard considers orders of any status, including draft/cancelled orders. A test mass order can therefore make a store eligible. If formal rollout approval is desired, the dashboard and automation should both move to an explicit approved Go-Live date; do not silently give them different definitions.
- No stock-balance prerequisite is added. A Go-Live store without opening balances or receipts can have negative SOH because valid sales still consume inventory.
- When a mapped store later goes live, it becomes eligible automatically, but only from its Go-Live date. An unmapped store still needs a verified source-company/site mapping.
- Deleting/changing the first mass order can change eligibility; earlier inventory postings are not automatically reversed. Ordinary changes should preserve the historical rollout evidence.
- Missing BOM reports continue to include available sales for authorized stores, including not-live stores, to support rollout preparation. Report presence is not permission to post inventory.
- Manual import rules remain as requested: a store/day is blocked after successful automatic posting/verification. This new gate governs automatic posting, not a new ban on manual imports.

## Local audit

See `pos-go-live-audit.json`. Of 23 configured stores, 16 are eligible and 7 are not live: NNBIC, NNGAL, NNNT1, NNOPU, NNSSR, NNTOL, NNVIA. Five live branches lack a configured source mapping: NNABA, NNHSS, NNNUV, NNPDM, NNVER. No source headers were found for those five using their branch code or unprefixed site code in the local replica; alternate vendor site codes cannot be inferred safely.

No new migration is required for this gate. Existing local env profile overrides and shipped mapping files were updated to September 14. Azure startup uses the shipped mappings unless an explicit environment override exists.
