-- READ ONLY. Captured examples are from daviddb_test, not independently verified in production.
-- Company + publisher + source record identify the source; site additionally scopes the header.
SELECT DB_NAME() AS database_name;

-- Full recorded unresolved counts, without TOP or a retry-time restriction.
SELECT e.entity_id, e.store_branch_id, b.branch_code, e.reason, COUNT(*) AS unresolved_receipts
FROM pos_sync_exceptions e
JOIN store_branches b ON b.id = e.store_branch_id AND b.entity_id = e.entity_id
WHERE e.resolved_at IS NULL
GROUP BY e.entity_id, e.store_branch_id, b.branch_code, e.reason
ORDER BY b.branch_code, e.reason;

-- Four source examples: existing import, subtotal mismatch, no active lines, unit conversion.
SELECT fcompanyid,fsiteid,fpubid,frecno,fsale_date,ftermid,ftrx_no,
       fpost_flag,fvoid_flag,freturn_flag,fsubtotal,fgross,fservice_charge,_sync_timestamp
FROM pos_sale
WHERE fcompanyid = 'CBTL-25021982' AND (
    (fsiteid='UTC' AND fpubid='CBTL-25021982-0003' AND frecno IN ('9995','9961')) OR
    (fsiteid='RAO' AND fpubid='CBTL-25021982-0006' AND frecno='16900') OR
    (fsiteid='UPM' AND fpubid='CBTL-25021982-0037' AND frecno='17245'));

SELECT fcompanyid,fsiteid,fpubid,frecno,fseqno,fproductid,fstatus_flag,
       fqty,fuomqty,funitprice,ftotal_line,fupdated_date,_sync_timestamp
FROM pos_sale_product
WHERE fcompanyid='CBTL-25021982' AND (
    (fpubid='CBTL-25021982-0003' AND frecno IN ('9995','9961')) OR
    (fpubid='CBTL-25021982-0006' AND frecno='16900') OR
    (fpubid='CBTL-25021982-0037' AND frecno='17245'))
ORDER BY fpubid,frecno,fseqno,_sync_timestamp;
-- Inspect all returned versions; do not sum duplicate source versions together.

-- UTC manual import: its existence and totals do not establish a verified posting audit.
SELECT id,entity_id,store_branch_id,order_date,tim_number,receipt_number
FROM store_transactions WHERE entity_id=1 AND store_branch_id=16 AND id=52798;
SELECT * FROM store_transaction_items WHERE store_transaction_id=52798;
SELECT * FROM sales_postings WHERE entity_id=1 AND store_transaction_id=52798;
SELECT id,product_inventory_id,quantity,action,transaction_date,remarks
FROM product_inventory_stock_managers
WHERE entity_id=1 AND store_branch_id=16
  AND (remarks LIKE '%Receipt No. 27668)%' OR remarks LIKE 'Sale #52798,%');
-- Legacy remarks lack terminal identity; these are evidence candidates, not a complete audit.

-- Cake-board unit mismatch: BOM says GRAM; stock mappings only PC and PACK(100).
SELECT POSCode,ItemCode,BOMUOM,BOMQty FROM pos_masterfiles_bom
WHERE entity_id=1 AND ItemCode='171A2A';
SELECT id,ItemCode,ItemDescription,BaseUOM,AltUOM,BaseQty,AltQty
FROM sap_masterfiles WHERE entity_id=1 AND ItemCode='171A2A';
