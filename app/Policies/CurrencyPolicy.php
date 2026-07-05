<?php

namespace App\Policies;

use App\Policies\Concerns\HandlesCommerceAuthorization;

class CurrencyPolicy
{
    use HandlesCommerceAuthorization;

    protected string $area = 'settings';
}
