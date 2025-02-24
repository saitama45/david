<?php

namespace App\Enum;

enum TimePeriod: int
{
    case January = 1;
    case February = 2;
    case March = 3;
    case April = 4;
    case May = 5;
    case June = 6;
    case July = 7;
    case August = 8;
    case September = 9;
    case October = 10;
    case November = 11;
    case December = 12;
    case YTD = 0;

    public static function values()
    {
        $data = array_column(self::cases(), 'value');
        $keys = array_column(self::cases(), 'name');

        return array_combine($data, $keys);
    }
}
