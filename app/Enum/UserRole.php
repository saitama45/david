<?php

namespace App\Enum;

enum UserRole: string
{
    case ADMIN = 'admin';
    case SO_ENCODER = 'so encoder';
    case REC_ENCODER = 'rec encoder';
    case REC_APPROVER = 'rec approver';

    public static function values()
    {
        $data = array_column(self::cases(), 'value');
        return array_combine($data, $data);
    }
}
