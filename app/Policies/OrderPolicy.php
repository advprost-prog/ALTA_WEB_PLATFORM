<?php

namespace App\Policies;

use App\Policies\Concerns\HandlesCommerceAuthorization;

class OrderPolicy
{
    use HandlesCommerceAuthorization;

    protected string $area = 'sales';
}
