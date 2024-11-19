<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'transactionheader';

    protected $attributes = [
        'id',
        'TransactionType' => 'transaction_type',
        'OrderDate' => 'order_date',
        'ReceivingDate' => 'receiving_date',
        'Total_Item' => 'total_item',
        'TOTALQUANTITY' => 'total_quantity',
        'Encoder_ID' => 'encoder_id',
        'Supplier' => 'supplier',
        'Status' => 'status',
        'IsApproved' => 'is_approved',
        'ApprovedById' => 'approved_by_id',
        'ReceivedById' => 'received_by_id',
        'LastUpdateByID' => 'last_update_by_id',
        'CreatedDate' => 'created_at',
        'LastUpdateDate' => 'updated_at',
        'DeliverReceiptNo' => 'deliver_receipt_no',
        'DRLink' => 'dr_link',
        'POReceiptNo' => 'po_receipt_no',
        'BranchID' => 'branch_id',
        'CounterNumber' => 'counter_number',
        'TransactionCode' => 'transaction_code',
        'SONumber' => 'so_number',
        'FromUpload' => 'from_upload',
        'UploadByUserId' => 'upload_by_user_id',
        'UploadFileName' => 'upload_file_name',
        'SODate' => 'so_date',
        'SourceSODuplicate' => 'source_so_duplicate',
        'isDuplicate' => 'is_duplicate',
        'Remarks' => 'remarks',
        'IsNoSO' => 'is_no_so',
        'DRAttachment' => 'dr_attachment',
        'SOApproved' => 'so_approved',
        'SOApprovedByID' => 'so_approved_by_id',
        'SOApprovedDate' => 'so_approved_date',
        'ApprovedDate' => 'approved_date'
    ];

    protected $casts = [
        'OrderDate' => 'datetime:F d, Y',
        'ReceivingDate' => 'date:F d, Y',
        'CreatedDate' => 'datetime:F d, Y',
        'LastUpdateDate' => 'datetime:F d, Y',
        'SODate' => 'date:F d, Y',
        'SOApprovedDate' => 'datetime:F d, Y',
        'ApprovedDate' => 'datetime:F d, Y',
        'created_at' => 'datetime:F d, Y'
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'BranchID');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'Supplier');
    }

    public function ordered_items()
    {
        return $this->hasMany(OrderedItem::class, 'TransactionHeaderID');
    }
}
