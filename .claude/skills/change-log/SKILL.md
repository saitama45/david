---
name: change-log
description: Add an entry to the DAVID in-app Change Log (/change-log) for every New Release, Enhancement or Removed feature. Use automatically, without being asked, whenever a task in this codebase adds a feature, changes how a page or process behaves, changes a business rule, corrects something users could see, or removes something users had. Also use when asked to "update the change log", "log this change" or "add release notes".
---

# Change Log — DAVID Inventory System

The system has a Change Log page (`/change-log`, the header button beside Knowledge Base) that
tells store and office users what changed. Its content is one file:

`resources/js/Pages/ChangeLog/entries.js`

**Every New Release, every Enhancement and every Removed feature goes into that file as part of
the same task that makes the change.** Do not wait to be asked, and do not ask whether to do it. A task that changes
what users see or how a rule works is not finished until its entry is written.

## Step 1 — Decide whether the change is logged

Log it when a user of the system would notice the difference:

| Change | Type key | Shown as |
|---|---|---|
| A page, button, column, report, export, permission or process that did not exist before | `new` | New Release |
| An existing page or process that now behaves differently, a business rule that changed, or a correction to something that was wrong | `improved` | Enhancements |
| A feature, link or option users had that was taken away | `removed` | Removed |

**There is no "Fixed" type.** The user removed it on 2026-10-01. A correction is logged as an
Enhancement. Never add a Fixed type, tag or filter back.

Do **not** log internal work nobody using the system can see: refactors, tests, documentation,
CLAUDE.md or memory updates, build and deploy changes, performance work with no visible effect,
and throwaway diagnostics. If a task is purely internal, skip this skill and say nothing about it.

## Step 2 — Gather the facts from the code, not the commit title

Read the actual diff before writing. Commit subjects in this repo are technical and often hide
extra changes (one "show each toast once" commit also added three Create forms and a new
permission). For each change collect:

- what it was like before, and what it is like now;
- the steps a user goes through, in order;
- every condition the server enforces: required fields, limits, deadlines, who is allowed, what is
  refused and the message the user gets, what counts and what does not;
- who is affected.

Never write a rule you have not seen in the code. If one task delivers several separate changes,
write one entry per change. If several commits deliver one change, write one entry.

## Step 3 — Write the entry

Add it at the **top** of the `changeLog` array (the file is newest first), under a date comment
for that day if one does not exist yet. Use today's date in Manila time.

```js
{
    date: 'YYYY-MM-DD',
    module: 'Month End Count',          // the page or area as users know it
    type: 'improved',                   // 'new' | 'improved' | 'removed'
    title: 'One line: what is different now',
    summary:
        'Two or three sentences. What it was like before, and what it is like now.',
    steps: [                            // optional: the process, in the order a user does it
        'The store opens Month End Count.',
    ],
    rules: [                            // optional: business rules and conditions
        'A reason is required, and the deadline must be in the future.',
    ],
    affects: 'Store users who take the count.',
},
```

**`module` is the page's label in the sidebar**, read from `menuLabel('<key>', '<label>')` in
`resources/js/components/Sidebar.vue` (Inbound Orders, SAP Masterlist, BOM List, Month End Count
Schedules), never a name of your own. Reuse an existing `module` when the change belongs to one,
so the page's module filter does not fill up with near-duplicates.

**Name the feature by its exact on-screen label, in the title.** Open the Vue page and copy the
button or dialog text ("Add Unlisted Item", "Check All Live Stores", "Confirm Receive"). People
search the log by the label they clicked. An entry that called "Add Unlisted Item" an "unordered
item" under "Order Receiving" could not be found by searching "unlisted" or "inbound".

**One feature, one entry.** A new button or dialog gets its own entry even when it shipped in the
same commit as other changes, so its title can carry its name.

## Step 4 — Wording rules

The reader is a store or office user, not a developer.

- Explain the **process** and the **business rules and conditions**. That is what the user asked
  the log to contain.
- No table, column, file, class, method, route, command or commit names. No developer terms:
  "stock history", not "ledger"; "one balance per item", not "base row"; "the system checks", not
  "the service validates".
- Use the names users see on screen, spelled exactly as shown: sidebar labels, page titles, button
  labels, tab names, status names, permission names. Do not paraphrase a label.
- Give a real example where it helps ("36 Gm sold of a 1,000 Gm Bag is shown as 0.036 Bag").
- State each rule as a full sentence that stands on its own.
- Plain sentences only. No dashes used as punctuation inside the text.

## Step 5 — Check

Run this from the repo root. It must report no problems.

```bash
node --input-type=module -e "
import { pathToFileURL } from 'node:url';
const { changeLog } = await import(pathToFileURL(process.cwd() + '/resources/js/Pages/ChangeLog/entries.js'));
const types = ['new', 'improved', 'removed'];
const problems = [];
let prev = '9999-99-99';
changeLog.forEach((e, i) => {
    for (const k of ['date', 'module', 'type', 'title', 'summary', 'affects']) if (!e[k]) problems.push('#' + i + ' missing ' + k);
    if (!types.includes(e.type)) problems.push('#' + i + ' bad type ' + e.type);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(e.date)) problems.push('#' + i + ' bad date ' + e.date);
    if (e.date > prev) problems.push('#' + i + ' is not in newest-first order');
    prev = e.date;
});
console.log('entries:', changeLog.length, 'problems:', problems.length ? problems : 'none');
"
```

Then check the entry can be found: the page's search box matches the title, summary, module,
steps, rules and "affects" text. The button label and the sidebar label must both appear in the
new entry, word for word.

## Step 6 — Report and commit together

- The entry belongs in the **same commit** as the change it describes, so the log is deployed
  with the feature. Both Azure (Prod) and the Test site build from `master`, so nothing else is
  needed for it to appear.
- In the final message, say in one line that the Change Log was updated and under which title.
- Commit messages stay one line with no body, as always in this repo.
