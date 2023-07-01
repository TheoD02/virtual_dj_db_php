<?php

namespace App\Enum;

enum PoiType: string
{
    case CUE = 'cue';
    case AUTOMIX = 'automix';
    case REMIX = 'remix';
    case BEATGRID = 'beatgrid';
    case ACTION = 'action';
}
