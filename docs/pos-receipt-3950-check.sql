-- Read-only. Run in daviddb_test locally, or daviddb to inspect production.
-- UTC / UP Town Center, September 14, 2026, terminal 0003, receipt 3950.
-- Source record 9961 is different from the customer-facing receipt number 3950.
DECLARE @Company varchar(50) = 'CBTL-25021982';
DECLARE @Publisher varchar(50) = 'CBTL-25021982-0003';
DECLARE @Record varchar(50) = '9961';

-- Result 1: receipt header. Shows all stored copies with arrival timestamps.
SELECT
    fsiteid AS Store,
    fsale_date AS SalesDate,
    ftermid AS Terminal,
    ftrx_no AS ReceiptNumber,
    frecno AS SourceRecord,
    TRY_CAST(ftotal_qty AS decimal(18,4)) AS HeaderQuantity,
    TRY_CAST(fsubtotal AS decimal(18,2)) AS HeaderSubtotal,
    TRY_CAST(fgross AS decimal(18,2)) AS HeaderGross,
    fpost_flag AS PostedFlag,
    fvoid_flag AS VoidFlag,
    freturn_flag AS ReturnFlag,
    _sync_timestamp AS ArrivedAt
FROM dbo.pos_sale
WHERE fcompanyid = @Company AND fsiteid = 'UTC'
  AND fpubid = @Publisher AND frecno = @Record
ORDER BY _sync_timestamp DESC;

-- Result 2: detail items. Status 1 is treated as active by the current importer.
-- All copies/statuses are shown for inspection; do not sum duplicate versions.
SELECT
    fsiteid AS Store,
    frecno AS SourceRecord,
    fseqno AS LineNumber,
    fproductid AS POSCode,
    fstatus_flag AS LineStatus,
    TRY_CAST(fqty AS decimal(18,4)) AS ItemQuantity,
    TRY_CAST(funitprice AS decimal(18,2)) AS UnitPrice,
    TRY_CAST(ftotal_line AS decimal(18,2)) AS StoredLineAmount,
    TRY_CAST(fqty AS decimal(18,4))
        * TRY_CAST(funitprice AS decimal(18,2)) AS QuantityTimesPriceBeforeDiscount,
    fupdated_date AS SourceUpdatedAt,
    _sync_timestamp AS ArrivedAt
FROM dbo.pos_sale_product
WHERE fcompanyid = @Company AND fpubid = @Publisher AND frecno = @Record
ORDER BY fseqno, _sync_timestamp DESC;
