<?php

namespace App\Policies;

use App\Policies\Concerns\HandlesCommerceAuthorization;

class WarehousePolicy
{
    use HandlesCommerceAuthorization;

    protected string $area = 'settings';
}
