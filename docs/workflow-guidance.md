# Workflow guidance and My Actions

The shared guidance panel appears in Mass Orders, DTS Mass Orders, order approvals,
CS commitments, receiving and receiving approvals, sales transactions, wastage,
MEC and interco workflows. It presents the current step, business rule, eligible
roles, a link to the next screen, and an expandable process reference.

My Actions is available from the sidebar and dashboard. It aggregates existing
permissions rather than granting new access. Records are restricted to the active
entity and the viewer's assigned branches. Interco commitment belongs to the
sending branch; interco receiving belongs to the requesting/receiving branch.
Supplier assignments also restrict mass ordering and category commitments.

## Responsibility

Role names are read from the current database without renaming operational roles.
The display excludes `admin` and `CT Admin`, deduplicates roles and omits user names.
Both module access and action permissions are required.
Inactive users and colleagues outside the viewer's branch/entity access are omitted.
Missing eligible users produce an assignment message; the feature does not silently
grant permissions or nominate an administrator as an operational owner.

The implemented transaction flows do not store a dedicated next-user assignment.
Consequently the UI lists responsible roles. User IDs are used internally for
personal task eligibility; administrator permissions remain effective. The legacy generalized workflow service refers to models absent from this
checkout; this feature uses the actual transaction statuses and authorization rules.

## Tasks and deadlines

- Old pending handoffs remain visible regardless of the missing-submission range.
- Missing order and sales tasks use the adoption report's existing data sources.
  The default range includes this month through 14 days ahead for upcoming orders;
  sales dates are capped at today. The selectable range is limited to 93 days.
- Ordering deadlines come from the configured cutoff and covered delivery dates.
- Applicable commitments are due before the delivery date. Delivery logging is
  evaluated on the delivery date; CPO remains exempt from this timing indicator.
- Sales deadlines use the report's weekend/working-day acceptance rule.
- MEC uses current entity settings and branch reopenings. An expired upload window
  displays the existing support contact and a reopening instruction.
- Approval tasks without a configured SLA show waiting age, not an invented deadline.
- One-level wastage final approvals finish the workflow; pre-existing Level 2
  records still need Level 2 approval after a settings change.
  Guidance labels, rules and process steps use the approval count saved at
  `/wastage-settings`. Level 2 menu visibility does not add Level 2 to the
  process for new or pending records when one-level approval is configured.

Links only navigate. They never approve, receive, submit, reopen or send messages.
The destination's existing validation and permissions remain authoritative.

## Measurement

The five adoption detail tabs show completion and on-time percentages separately
over the selected report period and filters. These new figures weight applicable
rows/categories equally; existing adoption totals and their weighting are unchanged.
Completed late records are not treated as missing uploads. NA and unscheduled
rows are excluded from the new denominators.

My Actions shows current waiting counts, average age and oldest age by handoff.
Age uses the available creation or prior-action timestamp; it is not a historical
completed-workflow duration. MEC lacks a dedicated submission timestamp, so initial
count age uses upload creation time.

Wastage currently derives occurrence and upload time from the same creation
timestamp and only reports existing records. The UI states that true upload delay
and missing wastage cannot be inferred. No fictitious zero-wastage tasks are created.

Use the report date filters to compare matching periods before and after rollout.
Compare completion, on-time performance and open handoff age together. No changes
to existing roles, business rules, database records or schema are required.

## Validation

Focused PHP regression tests include an explicit SQLite `:memory:` fixture for
branch/entity/permission isolation. Frontend state tests use Node's test runner.
The Playwright smoke test is read-only and uses an existing authenticated session.
Do not run the unrelated destructive E2E projects for this feature.
