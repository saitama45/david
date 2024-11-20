<?php

namespace App\Enum;
 
enum OrderRequestStatus : string
{
    case APRROVED = 'approved';
    case PENDING = 'pending';
    case REJECTED = 'rejected';

    public static function values()
    {
        $data = array_column(self::cases(), 'value');
        return array_combine($data, $data);
    }
}
