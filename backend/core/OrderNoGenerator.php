<?php

class OrderNoGenerator
{
    public static function generate(string $prefix = 'ORD'): string
    {
        return $prefix . date('YmdHis') . str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
