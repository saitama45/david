-- Optional source-side read indexes for frequent incremental polling.
-- Run on the replicated POS database, not the store's live POS database.
-- No business rows are modified. Schedule index creation appropriately on a
-- busy replica: the existing local source has over two million product lines.
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.pos_sale') AND name = 'IX_pos_sale_sync_window')
    CREATE INDEX IX_pos_sale_sync_window ON dbo.pos_sale (_sync_timestamp)
    INCLUDE (fcompanyid, fpubid, frecno, fsiteid);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.pos_sale_product') AND name = 'IX_pos_sale_product_sync_window')
    CREATE INDEX IX_pos_sale_product_sync_window ON dbo.pos_sale_product (_sync_timestamp)
    INCLUDE (fcompanyid, fpubid, frecno);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.pos_sale_cancel') AND name = 'IX_pos_sale_cancel_sync_window')
    CREATE INDEX IX_pos_sale_cancel_sync_window ON dbo.pos_sale_cancel (_sync_timestamp)
    INCLUDE (fcompanyid, fpubid, frecno);
