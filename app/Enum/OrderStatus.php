<?php

namespace App\Enum;

enum OrderStatus: string
{
        //
    case INCOMPLETE = 'incomplete';
    case PENDING = 'pending';
    case RECEIVED = 'received';
    case APPROVED = 'approved';
    case COMMITED = 'commmited';
    case REJECTED = 'rejected';
    public static function values()
    {
        $data = array_column(self::cases(), 'value');
        return array_combine($data, $data);
    }
}
