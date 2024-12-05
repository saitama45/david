<?php

namespace App\Enum;

enum OrderStatus: string
{
    //
    case PARTIALLY_RECEIVED = 'partially_received';
    case PENDING = 'pending';
    case RECEIVED = 'received';

    public static function values()
    {
        $data = array_column(self::cases(), 'value');
        return array_combine($data, $data);
    }
}
