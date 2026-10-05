-- Repair: mass orders that were approved again after the store had received them.
--
-- Until 2026-10-05 Mass Orders Approval let Approve run on any order. On a received order it
-- put order_status back to 'approved' ('committed' for CPO), and on a CPO order it also added
-- a second, date-less 'pending' receiving row per item. Deploy the application fix BEFORE
-- running this, or the next re-approval undoes it.
--
-- One transaction, two changes:
--   1. Removes the leftover receiving rows: pending, no received date, created after the
--      receipt of the same item was already posted. They never reached stock.
--   2. Sets order_status back by the application's own rule
--      (OrderReceivingService::getOrderStatus): 'received' when every line of the order has a
--      receipt, otherwise 'incomplete'.
-- Quantities, stock and receipts are not touched. A copy of every row it changes is kept in
-- dbo.repair_20261005_leftover_receive_rows and dbo.repair_20261005_order_status.
--
-- Checked read-only on Azure (Prod), 2026-10-05: 11 leftover rows on 3 orders (NNEVI-00104,
-- NNEVO-00102, NNFIL-00174) and 46 orders (45 back to received, 1 back to incomplete).
--
-- HOW TO RUN (as one batch, there is no GO in this file):
--   1. Run the file as it is. It ends with ROLLBACK, so nothing is saved: it only shows what
--      would change. Compare result sets 1 to 3 with the figures above.
--   2. When they look right, change the last statement to COMMIT TRANSACTION and run it again.

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

-- 1. The leftover receiving rows --------------------------------------------------------
SELECT p.*
INTO #leftover
FROM ordered_item_receive_dates p
JOIN store_order_items soi ON soi.id = p.store_order_item_id
JOIN store_orders so ON so.id = soi.store_order_id
WHERE so.variant = 'mass regular'
  AND p.status = 'pending'
  AND p.received_date IS NULL
  AND EXISTS (SELECT 1
              FROM ordered_item_receive_dates a
              WHERE a.store_order_item_id = p.store_order_item_id
                AND a.status = 'approved'
                AND a.id < p.id);

-- 2. The orders whose status says "not received" although receipts were posted -----------
SELECT CAST(so.id AS bigint) AS id, -- an expression, so the copy does not inherit the identity
       so.order_number,
       so.order_status AS old_status,
       CAST(CASE WHEN NOT EXISTS (SELECT 1
                                  FROM store_order_items i
                                  WHERE i.store_order_id = so.id
                                    AND NOT EXISTS (SELECT 1
                                                    FROM ordered_item_receive_dates r
                                                    WHERE r.store_order_item_id = i.id
                                                      AND r.status IN ('approved', 'received')))
                 THEN 'received' ELSE 'incomplete' END AS varchar(20)) AS new_status,
       so.receiving_finalized_at,
       SYSUTCDATETIME() AS repaired_at_utc
INTO #orders
FROM store_orders so
WHERE so.variant = 'mass regular'
  AND so.order_status IN ('pending', 'approved', 'partial_committed', 'committed')
  AND EXISTS (SELECT 1
              FROM ordered_item_receive_dates r
              JOIN store_order_items i ON i.id = r.store_order_item_id
              WHERE i.store_order_id = so.id
                AND r.status = 'approved');

-- Result set 1: the receiving rows that will be removed.
SELECT so.order_number, soi.item_code, l.id AS receive_row_id, l.quantity_received, l.status,
       l.received_date, l.created_at
FROM #leftover l
JOIN store_order_items soi ON soi.id = l.store_order_item_id
JOIN store_orders so ON so.id = soi.store_order_id
ORDER BY l.id;

-- Result set 2: the orders that will get their status back.
SELECT id, order_number, old_status, new_status, receiving_finalized_at
FROM #orders
ORDER BY id;

-- Result set 3: the totals.
SELECT (SELECT COUNT(*) FROM #leftover) AS leftover_rows_to_remove,
       (SELECT COUNT(DISTINCT soi.store_order_id)
        FROM #leftover l JOIN store_order_items soi ON soi.id = l.store_order_item_id) AS orders_with_leftover_rows,
       (SELECT COUNT(*) FROM #orders) AS orders_to_restore,
       (SELECT COUNT(*) FROM #orders WHERE new_status = 'received') AS back_to_received,
       (SELECT COUNT(*) FROM #orders WHERE new_status = 'incomplete') AS back_to_incomplete;

-- Keep a copy of what is about to change.
IF OBJECT_ID('dbo.repair_20261005_leftover_receive_rows') IS NULL
    SELECT * INTO dbo.repair_20261005_leftover_receive_rows FROM #leftover WHERE 1 = 0;

INSERT INTO dbo.repair_20261005_leftover_receive_rows
SELECT * FROM #leftover;

IF OBJECT_ID('dbo.repair_20261005_order_status') IS NULL
    SELECT * INTO dbo.repair_20261005_order_status FROM #orders WHERE 1 = 0;

INSERT INTO dbo.repair_20261005_order_status
SELECT * FROM #orders;

-- The two changes.
DELETE r
FROM ordered_item_receive_dates r
WHERE r.id IN (SELECT id FROM #leftover);

UPDATE so
SET so.order_status = o.new_status
FROM store_orders so
JOIN #orders o ON o.id = so.id;

-- Result set 4: what is left after the repair. Both figures must be 0.
SELECT (SELECT COUNT(*)
        FROM ordered_item_receive_dates p
        JOIN store_order_items soi ON soi.id = p.store_order_item_id
        JOIN store_orders so ON so.id = soi.store_order_id
        WHERE so.variant = 'mass regular'
          AND p.status = 'pending'
          AND p.received_date IS NULL
          AND EXISTS (SELECT 1
                      FROM ordered_item_receive_dates a
                      WHERE a.store_order_item_id = p.store_order_item_id
                        AND a.status = 'approved'
                        AND a.id < p.id)) AS leftover_rows_remaining,
       (SELECT COUNT(*)
        FROM store_orders so
        WHERE so.variant = 'mass regular'
          AND so.order_status IN ('pending', 'approved', 'partial_committed', 'committed')
          AND EXISTS (SELECT 1
                      FROM ordered_item_receive_dates r
                      JOIN store_order_items i ON i.id = r.store_order_item_id
                      WHERE i.store_order_id = so.id
                        AND r.status = 'approved')) AS orders_still_wrong;

DROP TABLE #leftover;
DROP TABLE #orders;

-- First run: leave this as ROLLBACK. To save the repair, change it to COMMIT TRANSACTION.
ROLLBACK TRANSACTION;
