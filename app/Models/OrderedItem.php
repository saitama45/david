<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderedItem extends Model
{
    protected $table = 'transactiondetails';

    protected $fillable = [
        'id',
        'TransactionHeaderID',
        'ItemCode',
        'Cost',
        'REC_QTY',
        'PO_QTY',
        'POUserID',
        'RECUserID',
        'ExcelFilename',
        'CreatedDate',
        'LastUpdateDate',
        'IsApproved',
        'CreatedByID',
        'UpdatedByID',
        'ApprovedByID',
        'ReceivedByID',
        'Updated_at',
        'created_at',
        'Remarks',
        'ExpirationDate',
        'ReceivingDate',
        'isDelete',
        'DeletedByID',
        'DeleteRemarks',
        'isDuplicate',
        'SO_Number',
        'DRAttachment',
        'ApproveDate'
    ];

    public function order()
    {
        $this->belongsTo(Order::class, 'Id');
    }
}
