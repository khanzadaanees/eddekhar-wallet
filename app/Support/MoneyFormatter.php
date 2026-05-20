<?php

namespace App\Support;

class MoneyFormatter
{
    public static function format(string|float|int $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
