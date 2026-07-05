<?php

namespace App\Policies;

use App\Policies\Concerns\HandlesCommerceAuthorization;

class CustomerPolicy
{
    use HandlesCommerceAuthorization;

    protected string $area = 'customers';
}
