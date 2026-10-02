// The content of the Change Log page (/change-log), newest first.
//
// Written for the people who use the system, not for developers: say what changed in the
// process and which business rules now apply. No table, file or code names.
//
// To record a change, add an entry at the top:
//   date     'YYYY-MM-DD' the change was made
//   module   the page or area people know it by
//   type     'new' (shown as New Release) | 'improved' (Enhancements) | 'removed'
//            There is no "Fixed" type: a correction is logged as an enhancement.
//   title    one line, what is different now
//   summary  two or three sentences: what it was like before and what it is like now
//   steps    (optional) the process, in the order a user goes through it
//   rules    (optional) the business rules and conditions that apply
//   affects  who will notice the change

export const changeLog = [
    // ---------------------------------------------------------------- October 2, 2026
    {
        date: '2026-10-02',
        module: 'Inbound Orders (Receiving)',
        type: 'new',
        title: '"Final Receive All" button: receive everything and lock the delivery',
        summary:
            '"Confirm Receive All" posts what was received to stock but leaves the delivery open, so it was never clear when receiving was finished. Receiving History now has a "Final Receive All" button beside "Confirm Receive All". It is the last step for a delivery: it receives every remaining item and then locks the item list so nothing on it can be changed again.',
        steps: [
            'Open the order from Inbound Orders and attach the delivery receipt and an image.',
            'Record the received quantities, add any unlisted items, and use "Confirm Receive All" as often as needed. The list stays open after it.',
            'When the delivery is complete, click "Final Receive All".',
            'Read the "Final Receive All?" message. It says how many items not yet confirmed will be received now. Click "Final Receive All" to finish, or "Cancel" to go back.',
            'The page then shows "Finalized with Final Receive All" with the date and the name of the user who did it.',
        ],
        rules: [
            '"Final Receive All" receives every item not yet confirmed exactly as "Confirm Receive All" does, at the quantity recorded on its line, and posts it to stock.',
            'After "Final Receive All", no item can be added, edited or received on that delivery. "Add Unlisted Item", "Confirm Receive All", "Final Receive All" and the edit buttons are removed from the page, and the system refuses any change to the items.',
            'It cannot be undone.',
            'The button stays locked until the order has a delivery receipt and an image attached.',
            'Delivery receipts and images can still be added after the delivery is finalized. Only the item list is locked.',
            'Users with the "receive orders" permission can use it, the same as "Confirm Receive All".',
        ],
        affects: 'Store users who receive deliveries.',
    },
    {
        date: '2026-10-02',
        module: 'Inbound Orders (Receiving)',
        type: 'improved',
        title: 'No more 3-day limit for correcting a delivery: it stays open until "Final Receive All"',
        summary:
            'Received quantities could only be corrected, and unlisted items added, until the end of the third day after the delivery date. That limit is removed. A delivery now stays open for corrections until someone clicks "Final Receive All".',
        rules: [
            'Received quantities can be edited and "Add Unlisted Item" can be used on any delivery that has not been finalized, however old it is.',
            'Deliveries whose 3-day window had already closed are open again until they are finalized.',
            'A delivery receipt and an image must still be attached before any quantity is recorded.',
        ],
        affects: 'Store users who receive deliveries.',
    },
    {
        date: '2026-10-02',
        module: 'Inbound Orders (Receiving)',
        type: 'removed',
        title: '"SAP Masterlist" tab removed from "Add Unlisted Item"',
        summary:
            'The "Add Unlisted Item" dialog had two tabs, "Supplier Items" and "SAP Masterlist". The "SAP Masterlist" tab has been removed for now. Unlisted items are picked from the order\'s supplier item list only, as before.',
        rules: [
            'The dialog lists the active items of the order\'s supplier that are not yet on the order, once for each unit the supplier lists.',
            'An unlisted item is received at that supplier\'s cost. The cost box and the "Receive at zero cost?" question are gone.',
            'An item that is not in the order\'s supplier item list cannot be added. When every item of the supplier is already on the order, the dialog says "All items are already on this order".',
            'Lines already added from the SAP Masterlist stay on their orders as they are.',
        ],
        affects: 'Store users who receive deliveries.',
    },
    {
        date: '2026-10-02',
        module: 'Dashboard',
        type: 'improved',
        title: 'Success Rate now counts every ticket type from Helpdesk, not only MEC',
        summary:
            'On the Success Rate tab, Incoming and Closed come from Helpdesk, but only MEC tickets were being counted. Order, Commit, Receiving, Wastage, Sales Upload and Admin / Technical Concerns tickets showed 0 even after they were tagged in Helpdesk. All seven ticket types are now counted, including in past weeks.',
        rules: [
            'A ticket counts under the DAVID ticket type set on its Helpdesk item. Renaming the item in Helpdesk no longer stops its tickets from being counted.',
            'A new Helpdesk item counts only after its "DAVID Success Rate" type is picked on the item in Helpdesk. Items left as "Not counted", such as Account or the Wastages Concern items, are not counted.',
            'Incoming is the tickets opened in that week, Monday to Sunday. A ticket opened on a Sunday belongs to the week that ends that day.',
            'Closed is those same tickets that are now closed. Resolved and waiting tickets are not closed yet.',
            'For Nono\'s, every DAVID ticket counts, whichever company or mailbox it came through (head office TGI tickets and Coffee Bean tickets included), until Coffee Bean starts using the system.',
            'A partner escalation copy of a ticket is not counted a second time.',
            'Helpdesk counts are kept for up to 5 minutes. "Refresh from Helpdesk" fetches them at once.',
        ],
        affects: 'Management and anyone reading the Success Rate tab, and Helpdesk admins who manage the DAVID items.',
    },
    {
        date: '2026-10-02',
        module: 'BOM List',
        type: 'new',
        title: '"Create New Item" button to add one BOM line without uploading a file',
        summary:
            'A BOM line could only be added by importing an Excel file. The BOM List now has a "Create New Item" button, like the SAP Masterlist, that opens a form for a single line and applies the same checks as the import.',
        steps: [
            'Open BOM List and click "Create New Item".',
            'Type the POS Code. Its description from the POS Masterlist appears under the box, or a note that the code is not found.',
            'Type the Item Code of the ingredient. Its description from the SAP Masterlist appears, and the BOM UOM list fills with the units SAP has for that item.',
            'Pick the BOM UOM and enter the BOM Qty, which is how much is deducted from stock for each sale.',
            'Fill in Assembly, Rec Percent, Recipe Quantity, Recipe UOM, Unit Cost and Total Cost if they apply.',
            'Click Create. The new line appears in the BOM List.',
        ],
        rules: [
            'The POS Code must already be in the POS Masterlist, and the Item Code must already be in the SAP Masterlist. Add them there first.',
            'The BOM UOM must be one of the units SAP has for the item. It is picked from a list, not typed.',
            'The BOM Qty is required and must be more than zero.',
            'A line is the POS Code, Item Code, BOM UOM and Assembly together. The same line with the same BOM Qty is refused. Edit the existing line instead.',
            'The same line with a different BOM Qty is allowed only after you confirm "Add a repeated BOM line?". Every line is deducted separately on each sale, so add it only if the recipe really uses the item more than once.',
            'A different BOM UOM or a different Assembly makes it a different line, which needs no confirmation.',
            'The POS description and the item description are taken from the masterlists. They are not typed.',
            'The button shows only for users with the "create POSMasterfile BOM" permission, the same permission as the import.',
        ],
        affects: 'Users who maintain POS recipes (BOM).',
    },
    {
        date: '2026-10-02',
        module: 'Inbound Orders (Receiving)',
        type: 'improved',
        title: '"Add Unlisted Item" can now pick any active item from the SAP Masterlist',
        summary:
            'An unlisted item could only be picked from the item list of the order\'s supplier. If the delivered item was not in that list, or every item in it was already on the order, there was nothing to pick. The "Add Unlisted Item" dialog now has two tabs, "Supplier Items" and "SAP Masterlist", so any active SAP item can be recorded.',
        steps: [
            'Open the order from Inbound Orders and click "Add Unlisted Item".',
            'The dialog opens on the "Supplier Items" tab, which lists the items of the order\'s supplier that are not on the order. If all of them are already on the order, the dialog opens on the "SAP Masterlist" tab instead.',
            'Click "SAP Masterlist" to look in the whole masterlist. Type an item code or a name in the search box. The list shows the first 50 matching items, so type more to narrow it down.',
            'Each item is listed once for every unit it has, for example Kg and Gm. Pick the row with the unit the item was delivered in.',
            'If the item is not in the supplier\'s item list, a cost box appears with a warning that the item will be received at ZERO cost. Type the cost per unit from the delivery receipt or invoice.',
            'Enter the Quantity Received, the Expiry Date if there is one, and the Reason, then click "Add Item".',
            'If the cost is still zero, the system asks "Receive at zero cost?". Choose "Go back and enter a cost" or "Receive at zero cost".',
            'Click "Confirm Receive All" to post it to stock.',
        ],
        rules: [
            'The "Supplier Items" tab shows the active items of the order\'s supplier that are not yet on the order.',
            'The "SAP Masterlist" tab shows every active item of the SAP Masterlist that is not yet on the order. Inactive SAP items are not offered and are refused.',
            'An item that is already on the order cannot be added from either tab. Record the delivered quantity on its existing line instead.',
            'When every item of a tab is already on the order, the tab says "All items are already on this order".',
            'The unit is part of the choice. The quantity is converted from the unit picked into the item\'s stock unit when it is posted, for example 500 Gm becomes 0.5 Kg.',
            'A unit that cannot be converted into the item\'s stock unit is not offered.',
            'Cost of an item in the supplier\'s item list: it is received at that supplier\'s cost. No cost is asked for.',
            'Cost of any other SAP Masterlist item: the system has no price for it, so the receiver types the cost per unit. The cost starts at zero.',
            'A zero cost is allowed, but never by accident. The dialog shows "This item will be received at ZERO cost" as soon as the item is picked, and the system asks for confirmation before saving it at zero.',
            'An item received at zero cost adds stock with no value.',
            'The other rules are unchanged: a delivery receipt and an image must be attached first, the quantity must be more than zero, a reason is required, and items can be added until the end of the third day after the delivery date.',
        ],
        affects: 'Store users who receive deliveries, and finance staff who review receiving costs.',
    },
    {
        date: '2026-10-02',
        module: 'Inbound Orders (Receiving)',
        type: 'improved',
        title: '"Add Unlisted Item" is always shown until the 3-day window closes',
        summary:
            'The "Add Unlisted Item" button under Receiving History disappeared whenever every item of the order\'s supplier was already on the order, which made it look like the feature was missing. The button now stays on the page for as long as the delivery can still be corrected.',
        rules: [
            '"Add Unlisted Item" is shown on every order from the time it is ready to receive until the end of the third day after its delivery date. This includes the days after "Confirm Receive All" was done.',
            'The three days are counted from the order\'s delivery date, not from the day "Confirm Receive All" was clicked.',
            'After the 3-day window closes the button is removed, and the page shows the date the window closed.',
            'The button is locked, not hidden, while the order has no delivery receipt or no image attached. Hovering over it shows what is missing.',
            'If the supplier has no item left that is not already on the order, the button still opens the dialog, and the dialog says "All items are already on this order".',
        ],
        affects: 'Store users who receive deliveries.',
    },
    {
        date: '2026-10-02',
        module: 'Inbound Orders (Receiving)',
        type: 'improved',
        title: '"Confirm Receive" button renamed to "Confirm Receive All"',
        summary:
            'The button under Receiving History on an order\'s receiving page was called "Confirm Receive", which did not say that it confirms every pending line in one go. It is now called "Confirm Receive All". Only the name changed. What the button does is the same as before.',
        rules: [
            '"Confirm Receive All" posts all received quantities that are still pending to stock at once. It does not confirm one line at a time.',
            'The button shows only while the order has received quantities that are not yet confirmed.',
            'The button stays locked until the order has a delivery receipt and an image attached.',
            'The system asks you to confirm first, because the action cannot be undone.',
            'The Confirm Receive button on Interco Receiving keeps its name.',
        ],
        affects: 'Store users who receive deliveries.',
    },

    // ---------------------------------------------------------------- October 1, 2026
    {
        date: '2026-10-01',
        module: 'Users',
        type: 'new',
        title: 'Tick every live store at once when editing a user',
        summary:
            'The store list on the Edit User page has a new "Check All Live Stores" switch, so an administrator no longer has to look for and tick each live store one by one.',
        steps: [
            'Open Users and edit the user.',
            'In the store list, every store that is already live carries a green "Live" tag.',
            'Turn on "Check All Live Stores" to tick all of them in one go. The green counter beside the switch shows how many stores are live.',
            'The "Check All Branches" switch now also shows how many stores are ticked out of the total, for example "12 of 40 checked".',
            'Save the user.',
        ],
        rules: [
            'A store is live once it has placed its first order in Mass Orders. This is the same definition the Go-Live Stores tab on the Dashboard uses.',
            'Turning the switch on adds the live stores to whatever is already ticked. Nothing gets unticked.',
            'Turning the switch off unticks the live stores only. Stores that are not live stay exactly as they were.',
            'The switch cannot be used while no store is live yet.',
        ],
        affects: 'Administrators who create and maintain user accounts.',
    },
    {
        date: '2026-10-01',
        module: 'Inbound Orders (Receiving)',
        type: 'improved',
        title: 'Receiving History now says what each line is waiting for',
        summary:
            'Every line that was not yet received used to show "Pending", which did not tell the store whether the delivery could be received or was still waiting on the commissary. Each line now shows its real stage.',
        rules: [
            'Received (green): the delivered quantity has been recorded for the line.',
            'To Receive (purple): the line is committed and is waiting for the store to receive it. A line also counts as committed when the whole order is already Committed, Received or Incomplete.',
            'To Commit (blue): the line has not been committed yet.',
            'The colours match the Receiving History shown on the Mass Orders page.',
        ],
        affects: 'Store users who receive deliveries, and anyone who reviews an order\'s receiving history.',
    },
    {
        date: '2026-10-01',
        module: 'Inventory Movement Report',
        type: 'new',
        title: 'Variance column added beside Actual MEC',
        summary:
            'Under Final Balance the report now shows the difference between what the store counted and what the system expected, so you no longer have to subtract the two columns yourself.',
        rules: [
            'Variance = Actual MEC minus Theoretical SOH.',
            'A positive variance means the count found more stock than the books. A negative variance means the count found less.',
            'The column sits to the right of Actual MEC on the screen, in the PDF and in the Excel export.',
        ],
        affects: 'Finance, inventory analysts and area managers who read the report.',
    },
    {
        date: '2026-10-01',
        module: 'Month End Count',
        type: 'improved',
        title: 'A store cannot upload its count while it still has unfinished transactions',
        summary:
            'Previously only the template download was held back. A store with pending deliveries or wastage could still upload a count, even though its stock figures were not final. The upload now follows the same checks as the download.',
        steps: [
            'The store opens Month End Count.',
            'The system reviews the store\'s transactions from the 1st of the month being counted up to today.',
            'If anything is unfinished, the store is taken out of the upload form and a notice lists exactly what it has to finish, with links to each item.',
            'Once everything is finished, the store appears in the upload form again and can upload its count, as long as the upload window is still open.',
        ],
        rules: [
            'These hold back both the template and the upload: an order that is not yet received (still for approval, or still to be received); a received order with received quantities not yet approved; an interco transfer coming into the store that has not been received; a wastage record not yet approved at Level 2; and the previous month\'s count not yet approved at Level 2.',
            'An approved order that has not been committed is simply treated as not yet received. There is no separate "waiting for commit" condition.',
            'These do NOT hold a store back: an interco transfer the store is sending out but has not committed, and stock (SOH) adjustments still waiting for approval.',
            'The check runs up to today, so a new delivery left unreceived during the upload window can hold the store again.',
            'The upload window still applies first. A store that clears its pending work after the deadline needs its upload reopened, like any other late store.',
            'For a user who handles several stores, the upload form shows only for the stores that are allowed to upload.',
        ],
        affects: 'Store users who take the count, and the approvers who follow up late stores.',
    },
    {
        date: '2026-10-01',
        module: 'Month End Count',
        type: 'improved',
        title: 'Template "Current SOH" now covers the month being counted',
        summary:
            'A count is taken after its month ends: the September count is taken on October 1. The template was reading stock from the 1st of the calendar month, so on October 1 it looked at an empty October and every Current SOH came out as zero. Zeros were also printed as blank cells, which looked like a missing column.',
        rules: [
            'The period starts on the 1st of the month of the count the store takes next, and runs up to today.',
            'The "next count" is the latest scheduled count the store has not submitted yet. A rejected count is treated as not submitted. If the store is up to date, it is the next count on the schedule.',
            'A zero stock balance is now printed as 0. The cells the store fills in stay blank.',
        ],
        affects: 'Store users who download the count template.',
    },
    {
        date: '2026-10-01',
        module: 'Qty Variance / Cost Variance Report',
        type: 'improved',
        title: 'Pick the count from a list of MEC Scheduled Dates',
        summary:
            'The report used to ask for a free From and To date, which often matched no count or the wrong one. You now choose the count itself from a dropdown.',
        rules: [
            'A month end count is identified by its MEC Scheduled Date, not by a calendar month. The March 2026 count, for example, is dated April 5.',
            'The dropdown lists the scheduled dates. A date with no approved count is shown but cannot be selected.',
            'The report shows one count at a time.',
        ],
        affects: 'Finance and inventory analysts who review count variances.',
    },
    {
        date: '2026-10-01',
        module: 'Adoption Rate Tracking',
        type: 'improved',
        title: 'Only go-live stores are measured, and Commit and Sales Upload are always scored on time',
        summary:
            'Stores that have not started using the system were pulling the adoption figures down, and two indicators were measuring steps that are no longer part of the process. The report now measures only live stores and scores those two indicators at 100%.',
        rules: [
            'A store is measured starting from the Monday of the week it went live. Going live means placing its first order in Mass Orders, the same definition as the Go-Live Stores tab.',
            'Days before a store\'s go-live week, and stores that are not live at all, are left out of every indicator, the Overall tab, the Dashboard chart and the Success Rate transaction counts.',
            'Commit indicator: committing is no longer required before receiving, so every order that needs a commit is scored "Yes". DROPS, CPO and PUL-O finished goods stay "N/A". Commit dates are still shown for reference.',
            'Sales Upload indicator: sales now post automatically from the POS, so each day of a live store is scored "Yes" whether or not a file was uploaded. The "Uploaded?" column, upload date and working days still show what actually happened.',
            'My Actions still reminds stores that are not live yet about their orders and sales uploads.',
            'An excuse can only be requested for a row that is in the report, so it cannot be requested for a store that is not live.',
        ],
        affects: 'Management and operations teams who track adoption and success rates.',
    },
    {
        date: '2026-10-01',
        module: 'Adoption Rate Tracking',
        type: 'removed',
        title: '"Request excuse" link removed from Delivery Logging Timeliness',
        summary:
            'Stores can no longer file an excuse for a late delivery log from this tab.',
        rules: [
            'Excuses that were approved before the link was removed still show as "Excused" on the report.',
        ],
        affects: 'Store users and the approvers of business rule exceptions.',
    },

    // ---------------------------------------------------------------- September 30, 2026
    {
        date: '2026-09-30',
        module: 'Masterfile',
        type: 'new',
        title: 'Add a single SAP item, supplier item or POS item without uploading a file',
        summary:
            'Adding one item used to mean preparing and importing an Excel file. Each masterfile now has a Create form that applies the same checks as the import.',
        steps: [
            'Open SAP Masterlist, Supplier Items or POS Masterlist and click Create.',
            'Fill in the form and save.',
            'If a check fails, the form says which field is wrong and why, and nothing is saved.',
        ],
        rules: [
            'SAP item: Item Code, Description, Alt Qty, Alt UOM, Base Qty and Base UOM are required, and both quantities must be more than zero.',
            'SAP item: a row with the same Item Code, Alt UOM and Base UOM as an existing one is refused. Edit the existing row instead.',
            'SAP item: an item keeps one base unit. A different base unit is refused, unless it restates a pack already on file (Case = 48 Can, then Case = 18,720 Gm).',
            'SAP item: Item Type is optional, and only active types can be picked.',
            'Supplier item: you can only add items for suppliers assigned to you.',
            'Supplier item: the Item Code must already be in the SAP Masterfile, and the unit must be one of that item\'s SAP units. If not, the form lists the units you can use.',
            'Supplier item: the same item and unit already in that supplier\'s list is refused. Cost is required. A blank item name takes the SAP description.',
            'POS item: POS Code, Description and SRP are required, and the POS Code must not already exist in the entity. A category can be picked from the list or typed in.',
        ],
        affects: 'Masterfile maintainers.',
    },
    {
        date: '2026-09-30',
        module: 'CS Mass Commits',
        type: 'new',
        title: 'New permission for committing CONTROL items',
        summary:
            'Access to edit commits was decided only by the item\'s category. A separate "edit control commits" permission now lets a role handle CONTROL items without opening everything else.',
        rules: [
            'An item classified as CONTROL can be edited by a user with "edit control commits", whatever its category.',
            'For all other items the category decides: Finished Goods need "edit finished good commits", every other category needs "edit other commits".',
            'The control permission does not open items that are not classified as CONTROL.',
            'The same rule covers quantity edits, unit changes and Confirm All. Confirm All commits only the lines the user is allowed to edit and skips the rest.',
            'The permission is granted per role on the Roles page.',
        ],
        affects: 'Commissary staff who commit orders, and administrators who set up roles.',
    },
    {
        date: '2026-09-30',
        module: 'General',
        type: 'improved',
        title: 'Success messages appear once instead of twice',
        summary:
            'After saving or importing, the green confirmation message popped up twice on several pages (SAP Masterlist, POS Masterlist, Supplier Items, Month End Count Templates, Ordering Cut off, Suppliers and Sales/Budget Uploader). Each action now shows a single message.',
        affects: 'Everyone.',
    },

    // ---------------------------------------------------------------- September 29, 2026
    {
        date: '2026-09-29',
        module: 'Dashboard',
        type: 'improved',
        title: 'Small drops in Success Rate are now visible on the chart',
        summary:
            'Success Rate usually sits just under 100%, so on a 0 to 100 scale a drop to 99.87% looked like a flat line at 100%. The rate is now drawn in its own zoomed section of the chart and labelled to two decimal places.',
        affects: 'Management and anyone reading the Success Rate tab.',
    },

    // ---------------------------------------------------------------- September 28, 2026
    {
        date: '2026-09-28',
        module: 'Month End Count',
        type: 'new',
        title: 'A Level 1 approver can reject a count and send it back to the store',
        summary:
            'A count uploaded with mistakes could not be corrected: the approver could only approve it. The Level 1 approver can now reject it, which returns it to the store and reopens the upload.',
        steps: [
            'The Level 1 approver opens the count in MEC Approval 1st Level and chooses Reject.',
            'The approver types the reason and sets a re-upload deadline. The form suggests three days ahead at 11:59 PM.',
            'The count is returned to the store, and uploading is reopened for that store until the deadline, even if the regular upload window has already closed.',
            'On the Month End Count page the store sees that its count was rejected, by whom, when and why.',
            'The store corrects the count and uploads again. The new upload fully replaces the rejected one.',
            'The new count goes through Level 1 and Level 2 approval as usual.',
        ],
        rules: [
            'A reason is required, and the re-upload deadline must be in the future.',
            'Rejecting needs the same permission as Level 1 approval.',
            'If the store was already given a later reopen date, the later date is kept. A rejection never shortens it.',
            'A rejected count is treated as not submitted, so the store shows as outstanding again in Store Progress.',
            'A record of each rejection (who, why and how many items) is kept even after the store uploads the replacement.',
            'Only Level 1 can reject. Level 2 has no reject.',
        ],
        affects: 'Level 1 approvers and store users.',
    },
    {
        date: '2026-09-28',
        module: 'Month End Count',
        type: 'new',
        title: 'Template "Current SOH" now matches the Inventory Movement Report',
        summary:
            'The Current SOH printed on the count template now uses the same calculation as the Theoretical SOH on the Inventory Movement Report, so the two always agree. Because that figure is only right when the store\'s transactions are complete, the template is held back until they are.',
        rules: [
            'Current SOH = previous month\'s count + approved receipts + interco received − sales − wastage approved at Level 2 − interco sent out.',
            'The figure is shown in each template line\'s Bulk UOM. A line with no SAP item, or with no unit conversion, shows 0.',
            'The download is held back while the store has unfinished transactions in the period. The page lists them per store with links. See the October 1 entry for the full list of conditions.',
        ],
        affects: 'Store users who take the count.',
    },
    {
        date: '2026-09-28',
        module: 'Month End Count Schedules',
        type: 'improved',
        title: 'Store Progress can be opened for any month',
        summary:
            'Store Progress only opened when at least one store had submitted. For a past month where nobody uploaded, it could not be opened at all, which was exactly when a store\'s upload needed to be reopened.',
        steps: [
            'Open Month End Count Schedules and click the progress bar of the month.',
            'Tick the stores that still have to upload.',
            'Pick the date and time the upload should stay open until, then click Reopen.',
        ],
        rules: [
            'Store Progress opens for every month that has active stores: past, current or future, with or without submissions.',
            'Reopening needs the "reopen month end count" permission.',
            'A store that has already submitted its count cannot be reopened from here.',
        ],
        affects: 'Administrators and support staff who manage count schedules.',
    },

    // ---------------------------------------------------------------- September 25, 2026
    {
        date: '2026-09-25',
        module: 'Inventory Movement Report',
        type: 'new',
        title: '"Supplies Used" column for operating and cleaning supplies',
        summary:
            'Supplies such as gloves, cleaners and tissue are used up without anyone recording a transaction, so their usage never appeared on the report. It is now taken from the month end count.',
        rules: [
            'An item is treated as supplies when its SAP Item Type is OPERATING SUPPLIES or CLEANING SUPPLIES, or when its Supplier Items category is "Supplies".',
            'Supplies Used is the part of the book balance that the month end count did not find.',
            'It is never negative. A count higher than the books is a gain and stays in the variance.',
            'Items used through a recipe, such as cups and lids, are already counted under Sales and are not counted again here.',
            'The column appears on the screen, in the PDF and in the Excel export.',
        ],
        affects: 'Finance and inventory analysts.',
    },
    {
        date: '2026-09-25',
        module: 'BOM List',
        type: 'improved',
        title: 'A repeated BOM line with a different quantity is held for review',
        summary:
            'When an uploaded BOM file repeated a line with a different quantity, the second line silently replaced the first, so the recipe deducted the wrong amount. Such lines are now set aside for a person to decide.',
        steps: [
            'Upload the BOM file on the BOM List page.',
            'A line that matches an existing one and has the same BOM Qty simply updates that line.',
            'A line that matches an existing one but has a different BOM Qty is not imported. It appears in an amber "Needs review" box at the top of the page.',
            'Tick the rows and choose "Allow selected" or "Dismiss selected".',
        ],
        rules: [
            'Two lines are the same line when POS Code, Item Code, BOM UOM and Assembly all match.',
            'Allow: the line is added next to the existing one. Every allowed line is deducted separately on each sale, so allow it only if the recipe really uses the item more than once.',
            'Dismiss: the line is dropped from the review list without being saved.',
        ],
        affects: 'Users who maintain POS recipes (BOM).',
    },
    {
        date: '2026-09-25',
        module: 'Wastage, Interco Transfer and Stock Management',
        type: 'improved',
        title: 'Every unit of an item is offered once, with its own cost and stock',
        summary:
            'Item pickers showed only some of an item\'s units, and costs were taken from the first supplier row found for the item, so a Case could be priced as a Can. Each unit is now listed once with the right cost and stock.',
        rules: [
            'Every unit an item has in SAP (for example Case, Can, Gm) is listed, each only once.',
            'The cost is the supplier cost of the unit picked: a Case at ₱1,800, not the Can price of ₱37.50.',
            'Stock on hand is shown converted into the unit picked.',
            'In the wastage cart, the item added last is shown at the top.',
        ],
        affects: 'Store users who record wastage, interco transfers and stock adjustments.',
    },

    // ---------------------------------------------------------------- September 24, 2026
    {
        date: '2026-09-24',
        module: 'Stock Management',
        type: 'improved',
        title: 'One stock balance per item across its linked units',
        summary:
            'An item ordered by the Case and sold by the Can could end up with two separate stock balances, so receiving raised one while sales lowered the other. Linked units now share a single balance.',
        rules: [
            'Units that SAP links with a conversion (48 Can = 1 Case) share one stock balance, kept in the SAP base unit.',
            'A unit with no conversion link keeps its own balance.',
            'Quantities are converted through SAP\'s conversion chain before they are added to or taken from stock.',
            'This applies wherever stock moves: order receiving, interco sending and receiving, wastage, month end count approval and POS sales.',
            'A correction tool was added for stock history recorded under the wrong unit. It shows a preview first and changes nothing until it is confirmed.',
        ],
        affects: 'Everyone who relies on stock on hand figures.',
    },
    {
        date: '2026-09-24',
        module: 'Inventory Movement Report',
        type: 'improved',
        title: 'One row per item, in the SAP base unit, with correct totals',
        summary:
            'Items with more than one unit were counted once per unit, which doubled their totals, and ordering units were mixed with recipe units in the same row. The report now shows one row per item in a single unit.',
        rules: [
            'One row per item code.',
            'All quantities are stated in the item\'s SAP base unit. Example: 36 Gm sold of a 1,000 Gm Bag is shown as 0.036 Bag.',
            'The original ordered and received quantities are shown beside the converted figures.',
            'Orders received without a separate commit step are included in the totals.',
            'A unit that cannot be converted is listed on the row instead of being silently added in.',
        ],
        affects: 'Finance and inventory analysts.',
    },
    {
        date: '2026-09-24',
        module: 'Mass Orders',
        type: 'improved',
        title: 'Today and past delivery dates temporarily allowed for templates without a cutoff',
        summary:
            'To let stores encode orders that were already delivered, a mass order on a template with no cutoff can now be dated today or in the past. This is a temporary allowance.',
        rules: [
            'Applies only to ordering templates that have no cutoff set up.',
            'The delivery date can be today or up to 60 days back, as well as tomorrow up to 59 days ahead.',
            'Templates that do have a cutoff follow their cutoff as before.',
            'When the allowance is switched off, the earliest delivery date goes back to tomorrow.',
        ],
        affects: 'Store users who place mass orders.',
    },

    // ---------------------------------------------------------------- September 23, 2026
    {
        date: '2026-09-23',
        module: 'SAP Masterlist',
        type: 'improved',
        title: 'The same pack stated in two base units is accepted',
        summary:
            'SAP sometimes states one pack in two ways, for example Case = 48 Can and Case = 18,720 Gm. The import treated the second row as a duplicate and skipped it. Both rows now import.',
        rules: [
            'A row is identified by Item Code + Alt UOM + Base UOM.',
            'A second base unit is accepted only when the file already gave that same Alt UOM under the item\'s accepted base unit.',
            'Any other change of base unit is still refused (see the September 21 entry).',
        ],
        affects: 'Users who import the SAP masterfile.',
    },

    // ---------------------------------------------------------------- September 22, 2026
    {
        date: '2026-09-22',
        module: 'Mass Orders and DTS',
        type: 'improved',
        title: 'Templates without a cutoff are open for ordering, and the late-order request link is gone',
        summary:
            'An ordering template with no cutoff set up used to block delivery dates. It is now treated as unrestricted. The link for requesting a late order was also removed from the Mass Orders create dialog.',
        rules: [
            'A template with no cutoff accepts any delivery date from tomorrow up to 59 days ahead, for both mass orders and DTS.',
            'CPO orders remain fully unrestricted.',
            'Templates with a cutoff are enforced as before.',
            'The "late order" request link no longer appears when creating a mass order. Late-order approvals that were already granted are still honoured.',
        ],
        affects: 'Store users who place orders.',
    },
    {
        date: '2026-09-22',
        module: 'Ordering Templates',
        type: 'improved',
        title: 'Stores of every entity now appear in ordering templates',
        summary:
            'Only Nono\'s stores were showing up in ordering templates. The weekday delivery schedules (Monday to Sunday) were tied to one entity, which hid the stores of the others.',
        rules: [
            'The seven weekday delivery schedules are shared by all entities.',
            'Month end schedules that are generated now belong to the entity that generated them, so they show up for that entity.',
        ],
        affects: 'Coffee Bean & Tea Leaf and any entity other than Nono\'s.',
    },
    {
        date: '2026-09-22',
        module: 'Supplier Items',
        type: 'improved',
        title: 'Import template now comes with sample rows',
        summary:
            'The downloadable Supplier Items template now starts with three example rows that show how each column should be filled in, followed by your current items.',
        rules: [
            'The example rows are highlighted in yellow and their Item Code starts with "SAMPLE-".',
            'Example rows are ignored on upload, so sending the template back unchanged does no harm.',
        ],
        affects: 'Users who import supplier items.',
    },

    // ---------------------------------------------------------------- September 21, 2026
    {
        date: '2026-09-21',
        module: 'SAP Masterlist',
        type: 'new',
        title: 'Item Types for SAP items',
        summary:
            'SAP items can now be classified by Item Type (Food, Beverage, Operating Supplies and so on). The list of types is maintained in the system, and reports use it, for example to find supplies.',
        steps: [
            'Maintain the list of Item Types: add a type, rename it, or switch it on or off.',
            'Set an item\'s type when editing the item, or through the import file\'s "Item Type" column. The import template offers the types in a dropdown.',
            'Filter the SAP Masterlist by Item Type.',
        ],
        rules: [
            'The type belongs to the item code. All unit rows of the same item share one type.',
            'Starting list: FOOD, BEVERAGE, OPERATING SUPPLIES, RETAIL, CLEANING SUPPLIES and FOOD - OIL. Existing items were given a type based on their Month End Count template category.',
            'A type is never deleted, only deactivated. A deactivated type is no longer offered, but items that already have it keep it.',
            'A type name must be unique within the entity. Names are saved in capital letters, so "food" in a file matches FOOD.',
            'An import file that has no "Item Type" column leaves the existing types untouched.',
        ],
        affects: 'Masterfile maintainers and report users.',
    },
    {
        date: '2026-09-21',
        module: 'SAP Masterlist',
        type: 'improved',
        title: 'Rows that contradict an item\'s base unit are refused',
        summary:
            'When a file brought in an existing item under a different base unit, the import added a second set of rows. The store then counted the item twice, once per base unit, while stock moved on only one of them.',
        rules: [
            'An item has one base unit. A row that gives a different base unit is skipped.',
            'The rest of the file still imports. The skipped row appears on the skipped-items report with the reason.',
            'The base unit has to be corrected in SAP, then imported again.',
            'A file that contradicts itself is caught on its second base unit.',
            'Items that already have more than one base unit on file are left alone and remain editable.',
        ],
        affects: 'Users who import the SAP masterfile.',
    },
    {
        date: '2026-09-21',
        module: 'Qty Variance / Cost Variance Report',
        type: 'improved',
        title: 'Theoretical inventory now comes from the stock history',
        summary:
            'The report rebuilt the expected stock from orders, sales and wastage. Deliveries are recorded by the case or pack while stock is kept in base units, so pack deliveries were credited at a fraction of what arrived and the variance was wrong.',
        rules: [
            'Theoretical inventory is the system\'s stock on hand at the time of the count, the same figure Stock Management shows.',
            'Theoretical = counted quantity minus the adjustment the count made to stock at Level 2 approval.',
            'A count that needed no adjustment means the books already agreed with the count.',
            'Figures are per store and item code.',
        ],
        affects: 'Finance and inventory analysts.',
    },
    {
        date: '2026-09-21',
        module: 'Month End Count',
        type: 'removed',
        title: '"Request exception" buttons removed from the count page',
        summary:
            'A store that missed the upload window could file a one-time exception request from the Month End Count page. Those buttons were removed.',
        rules: [
            'A store that could not upload in time now contacts support, using the contact shown on the page.',
            'An authorised user reopens the upload for that store from Store Progress in Month End Count Schedules.',
        ],
        affects: 'Store users who missed the upload window.',
    },
    {
        date: '2026-09-21',
        module: 'Work Queue',
        type: 'improved',
        title: 'The automatic sync only runs when new POS data arrives',
        summary:
            'The sync was creating a Work Queue job on every check, even when nothing had changed, which filled the Work Queue with empty jobs. It now creates a job only when there is something new to post.',
        rules: [
            'The system checks for new POS data every 10 seconds. If nothing new arrived, no job is created.',
            'Receipts that were already checked and have not changed are not processed again.',
            'Older receipts that are still unresolved do not trigger new jobs on their own. They stay counted and visible in the Work Queue.',
            'Fixing a recipe (BOM) does not automatically reprocess old receipts. Support reruns them on request.',
            'When a receipt\'s header totals are zero but its lines carry amounts, the lines are used as the correct figures, and the Work Queue report notes it.',
        ],
        affects: 'Users who monitor the Work Queue.',
    },

    // ---------------------------------------------------------------- September 20, 2026
    {
        date: '2026-09-20',
        module: 'Store Transactions',
        type: 'new',
        title: 'POS sales post automatically and deduct stock',
        summary:
            'Stores used to upload a POS sales file every day. Sales now come in directly from the POS, and ingredient stock is deducted as each sale is posted.',
        steps: [
            'The store completes a sale on the POS.',
            'The sale reaches the system through the regular POS data feed.',
            'The system checks for new sales every 10 seconds.',
            'For each completed receipt it posts the sale and deducts the ingredients from stock, following the product\'s recipe (BOM).',
            'The Work Queue shows each batch under "Automated POS Sync", with a downloadable report.',
            'Stock on hand pages refresh every 10 seconds while they are open.',
        ],
        rules: [
            'Only completed receipts are posted. Voided receipts, returns and removed lines are not.',
            'Stock is deducted even when the balance is zero or not enough. The balance goes negative on purpose, to expose late receiving or missing stock.',
            'A product with no recipe is held for review and listed on the Missing BOM tab, unless it is registered as a non-inventory product.',
            'Manual upload is allowed only for a store and sales date that has no POS-posted receipt. A single POS-posted receipt protects that whole day for the store, across all terminals.',
            'In a manual file, receipts that are allowed are posted and the blocked ones are listed in the skipped report. A file containing a row with an invalid date or receipt number is rejected as a whole. Maximum file size is 20 MB.',
            'A receipt that was uploaded manually and later arrives from the POS is matched and linked. Stock is not deducted a second time.',
            'Editing a posted sale is a correction: the store, date, terminal and receipt number stay fixed, a reason is required, and the original deduction is reversed and replaced in one step.',
            'Changes to sales already posted, returns and whole-receipt cancellations are held for review and are not reversed automatically.',
            'The Work Queue has three tabs: All Jobs, Automated POS Sync and Manual Imports. Users see automatic batches only for the stores assigned to them.',
            'The Missing BOM report covers up to 31 days (the last 7 by default), can be filtered by store or POS code, and exports to CSV.',
            'Posting happens shortly after the POS data arrives. It is not instant at checkout.',
        ],
        affects: 'Store users, inventory analysts and anyone who uploaded sales files.',
    },
    {
        date: '2026-09-20',
        module: 'Month End Count',
        type: 'improved',
        title: 'The count adjustment is now based on the real stock balance',
        summary:
            'At final approval, the system compared the count with the Current SOH printed on the template when it was downloaded, which could be out of date. Example: a store counted 8, the template said 57, so the system deducted 49, although the real balance was 4 and the adjustment should have been +4.',
        rules: [
            'At Level 2 approval the adjustment is the approved count minus the stock balance at that moment, taken from the stock history.',
            'The adjustment is dated on the approval date, as before.',
            'The Current SOH on the template comes from the same stock history.',
            'Approval is refused when an item has no clear stock record or its units do not match, instead of marking it approved without adjusting stock.',
            'Approving the same count twice cannot post the adjustment twice, and an older count cannot overwrite a later approved count.',
            'Tools were added to correct past adjustments. They show a preview first, keep the original records, add correcting entries, and are run by support.',
        ],
        affects: 'Level 2 approvers and inventory analysts.',
    },

    // ---------------------------------------------------------------- September 18, 2026
    {
        date: '2026-09-18',
        module: 'Inbound Orders (Receiving)',
        type: 'new',
        title: '"Add Unlisted Item": record an item that was delivered but not on the order',
        summary:
            'When a delivery arrived with an item that was never ordered (a substitute, a bonus, or simply an extra), there was no way to record it, so it never reached stock. The order\'s receiving page now has an "Add Unlisted Item" button for it.',
        steps: [
            'Open the order from Inbound Orders.',
            'Make sure the order has its delivery receipt and an image attached. The button stays locked until both are there.',
            'Click "Add Unlisted Item" beside Confirm Receive.',
            'Search and pick the item. The list shows the items of the order\'s supplier that are not already on the order.',
            'Enter the Quantity Received. The unit shown beside it is the supplier item\'s unit.',
            'Enter the Expiry Date if the item has one. Leave it blank for non-perishables.',
            'Give the Reason. You can pick a ready-made one ("Delivered but not ordered", "Substitute for an ordered item", "Bonus / free goods") or type your own.',
            'Save. The item is added to the order\'s Receiving History.',
            'Click Confirm Receive to post it to stock, the same as any other received line.',
        ],
        rules: [
            'The item must be in the item list of the order\'s supplier. An item of another supplier cannot be added.',
            'The item must not already be on the order. If it is, record the delivered quantity on its existing line instead.',
            'The item must have its unit set up in the SAP masterfile, otherwise the quantity cannot be converted to stock and the item is refused.',
            'Quantity Received is required and must be more than zero.',
            'Reason is required.',
            'Expiry Date is optional. When given, it must be a future date.',
            'The order needs a delivery receipt and an image attachment before an unlisted item can be added.',
            'An unlisted item can be added until the end of the third day after the delivery date, even after Confirm Receive was already done. Confirm Receive comes back for the new line.',
            'The cost is taken from the supplier\'s item list. It is not typed in.',
            'The line is saved with an ordered quantity of zero, which marks it as "arrived but never ordered" for reporting.',
            'The button does not appear when the supplier has no other items to add.',
        ],
        affects: 'Store users who receive deliveries.',
    },
    {
        date: '2026-09-18',
        module: 'Inbound Orders (Receiving)',
        type: 'improved',
        title: 'Receive as soon as the order is approved, with proof of delivery and a 3-day correction window',
        summary:
            'An approved order could not be received until the commissary committed it. Receiving now starts from approval, and the proof-of-delivery rule is enforced on every way of recording a receipt.',
        steps: [
            'Once an order is approved it appears in Inbound Orders, under the COMMITED tab, ready to be received.',
            'Attach the delivery receipt and at least one image.',
            'Record the quantity received on each line.',
            'Click Confirm Receive to post the received quantities to stock.',
            'Corrections can be made for three days after the delivery date.',
        ],
        rules: [
            'Approved, partially committed and committed orders are all ready to receive.',
            'No received quantity can be recorded until the order has a delivery receipt and an image attached. This used to be checked only by the Confirm Receive button, so it could be skipped by editing line by line.',
            'Received quantities can be corrected until the end of the third day after the delivery date. After that the page shows that the 3-day window has closed, and differences are handled through SOH Adjustment, which has its own approval.',
            'Once receiving has started on an order, its items can no longer be changed from Mass Orders.',
        ],
        affects: 'Store users who receive deliveries, and commissary staff.',
    },
    {
        date: '2026-09-18',
        module: 'Reports',
        type: 'improved',
        title: 'Report dates follow Philippine time',
        summary:
            'Reports and their Excel exports stamped the date and set their default date range from a clock eight hours behind Manila. In the morning, reports showed yesterday\'s date and defaulted to yesterday. All of them now use Philippine time.',
        affects: 'Everyone who runs or exports reports.',
    },
    {
        date: '2026-09-18',
        module: 'Inventory Movement Report',
        type: 'new',
        title: 'Excel export',
        summary:
            'The report can now be downloaded as an Excel file, using the same filters and showing the same figures as the screen.',
        affects: 'Finance and inventory analysts.',
    },
    {
        date: '2026-09-18',
        module: 'Inventory Movement Report',
        type: 'improved',
        title: 'Sales are counted on the date of sale, not the upload date',
        summary:
            'Sales were placed on the day the POS file was uploaded. A day\'s file is usually uploaded the next day, and one upload often carries several sales dates, so sales landed in the wrong period.',
        rules: [
            'A sale belongs to the date it was made at the POS.',
            'The From and To dates of the report are inclusive.',
        ],
        affects: 'Finance and inventory analysts.',
    },
];
