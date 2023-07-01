<?php

namespace App\Enum;

enum AutomixPoint: string
{
    case FADE_START = 'fadeStart';
    case FADE_END = 'fadeEnd';
    case REAL_START = 'realStart';
    case REAL_END = 'realEnd';
    case CUT_START = 'cutStart';
    case CUT_END = 'cutEnd';
    case TEMPO_START = 'tempoStart';
    case TEMPO_END = 'tempoEnd';
}
