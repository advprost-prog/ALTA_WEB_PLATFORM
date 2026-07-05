<?php

namespace App\Policies;

use App\Policies\Concerns\HandlesCommerceAuthorization;

class ProductPolicy
{
    use HandlesCommerceAuthorization;

    protected string $area = 'catalog';
}
