<?php

declare(strict_types=1);

namespace App\Enums;

enum OptionColor: string
{
    case Red = '#ff5454';
    case Orange = '#f87614';
    case Amber = '#f5a300';
    case Lime = '#aabf29';
    case Green = '#05d17e';
    case Cyan = '#02b9eb';
    case Blue = '#266df0';
    case Magenta = '#db4beb';
    case Purple = '#9b69ff';
    case Pink = '#fa4b94';
    case Olive = '#cfb701';
    case Gray = '#a2a4a7';

    public function label(): string
    {
        return __('colors.option.'.strtolower($this->name));
    }
}
