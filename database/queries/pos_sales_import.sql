-- Read-only POS Sales Report by Product extraction.
-- Bind @CompanyId, @SiteId, @FromDate and @ToDate (YYYYMMDD).
-- Latest source revision wins. Equal-version conflicting rows must be rejected
-- by the application reader before posting; this query is for reconciliation.
WITH HeaderVersions AS (
    SELECT s.*, ROW_NUMBER() OVER (
        PARTITION BY s.fcompanyid, s.fpubid, s.frecno
        ORDER BY s.fupdated_date DESC, s._sync_timestamp DESC
    ) AS version_no
    FROM dbo.pos_sale s
    WHERE s.fcompanyid = @CompanyId AND s.fsiteid = @SiteId
), Headers AS (
    SELECT * FROM HeaderVersions
    WHERE version_no = 1 AND fsale_date BETWEEN @FromDate AND @ToDate
      AND fpost_flag = '1' AND fvoid_flag = '0' AND freturn_flag = '0'
      AND TRY_CONVERT(bigint, ftrx_no) > 0
), LineVersions AS (
    SELECT p.*, ROW_NUMBER() OVER (
        PARTITION BY p.fcompanyid, p.fpubid, p.frecno, p.fseqno
        ORDER BY p.fupdated_date DESC, p._sync_timestamp DESC
    ) AS version_no
    FROM dbo.pos_sale_product p
    JOIN Headers h ON h.fcompanyid = p.fcompanyid
        AND h.fpubid = p.fpubid AND h.frecno = p.frecno
)
SELECT
    h.fcompanyid AS source_company, h.fpubid AS source_publisher,
    h.frecno AS source_record, p.fseqno AS source_line,
    p.fproductid AS [Product ID], COALESCE(product.fname, p.fproductid) AS [Product Name],
    p.flotno AS [Lot/Serial], CONVERT(date, h.fsale_date, 112) AS [Date],
    SUBSTRING(h.fposted_date, 9, 4) AS [Posted], h.ftermid AS [TM#],
    h.ftrx_no AS [Receipt No],
    CONCAT(h.ftermid, '-', RIGHT(REPLICATE('0', 8) + h.ftrx_no, 8)) AS [TM-OR#],
    TRY_CONVERT(decimal(18,6), p.fqty) * TRY_CONVERT(decimal(18,6), p.fuomqty) AS [Base Qty],
    TRY_CONVERT(decimal(18,6), p.fqty) AS [Qty], p.fuom AS [Unit],
    TRY_CONVERT(decimal(18,6), p.funitprice) AS [Price],
    ROUND(TRY_CONVERT(decimal(18,6), p.funitprice) * TRY_CONVERT(decimal(18,6), p.fqty)
        - TRY_CONVERT(decimal(18,6), p.ftotal_line), 2) AS [Discount],
    TRY_CONVERT(decimal(18,6), p.ftotal_line) AS [Line Total],
    ROUND(CASE WHEN TRY_CONVERT(decimal(18,10), p.fvar) <> 0
        THEN TRY_CONVERT(decimal(18,6), p.ftotal_line) * (1 + TRY_CONVERT(decimal(18,10), p.fvar) / 100)
        WHEN TRY_CONVERT(decimal(18,6), h.fsubtotal) = 0
        THEN TRY_CONVERT(decimal(18,6), p.ftotal_line)
        ELSE TRY_CONVERT(decimal(18,6), p.ftotal_line)
            * ((TRY_CONVERT(decimal(18,6), h.fgross) - TRY_CONVERT(decimal(18,6), h.fservice_charge))
                / NULLIF(TRY_CONVERT(decimal(18,6), h.fsubtotal), 0)) END, 2) AS [Net Total],
    p.fplevelid AS [Price ID], pricelevel.fname AS [Price Level Name],
    CASE WHEN p.fdeliver_flag = '1' THEN 'Y' ELSE '' END AS [Take Out],
    h.fsiteid AS [Branch], h.fcustomerid AS [Customer ID],
    COALESCE(NULLIF(h.fcustomer_name, ''), customer.fname) AS [Customer],
    cancellation.fcancel_memo AS [Cancel Reason]
FROM Headers h
JOIN LineVersions p ON p.fcompanyid = h.fcompanyid AND p.fpubid = h.fpubid
    AND p.frecno = h.frecno AND p.version_no = 1 AND p.fstatus_flag = '1'
OUTER APPLY (SELECT TOP (1) m.fname FROM dbo.mst_product m
    WHERE m.fcompanyid = p.fcompanyid AND m.fproductid = p.fproductid
    ORDER BY m.fupdated_date DESC, m._sync_timestamp DESC) product
OUTER APPLY (SELECT TOP (1) m.fname FROM dbo.mst_price_level m
    WHERE m.fcompanyid = p.fcompanyid AND m.fplevelid = p.fplevelid
    ORDER BY m.fupdated_date DESC, m._sync_timestamp DESC) pricelevel
OUTER APPLY (SELECT TOP (1) m.fname FROM dbo.mst_account m
    WHERE m.fcompanyid = h.fcompanyid AND m.faccountid = h.fcustomerid
    ORDER BY m.fupdated_date DESC, m._sync_timestamp DESC) customer
OUTER APPLY (SELECT TOP (1) c.fcancel_memo FROM dbo.pos_sale_cancel c
    WHERE c.fcompanyid = p.fcompanyid AND c.fpubid = p.fpubid
      AND c.frecno = p.frecno AND c.fseqno = p.fseqno
    ORDER BY c.fcreated_date DESC, c._sync_timestamp DESC) cancellation;
-- mst_discount defines discount master rules. It is intentionally not joined:
-- actual line discount = quantity * sale price - recorded line total. Joining
-- current discount rules would recalculate history and can multiply sale rows.
