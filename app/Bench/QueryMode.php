<?php

namespace App\Bench;

enum QueryMode: string
{
    case Eloquent = 'eloquent';
    case Raw = 'raw';
}
