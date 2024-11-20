<?php

namespace App\Enum;

enum UserRole: string
{
    case ADMIN = 'admin';
    case SO_ENCODER = 'so_encoder';
    case REC_ENCODER = 'rec_encoder';
    case REC_APPROVER = 'rec_approver';

    public static function values()
    {
        $data = array_column(self::cases(), 'value');
        return array_combine($data, $data);
    }
}
