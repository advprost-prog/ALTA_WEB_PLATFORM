<?php

namespace App\Policies;

use App\Policies\Concerns\HandlesCommerceAuthorization;

class BrandPolicy
{
    use HandlesCommerceAuthorization;

    protected string $area = 'catalog';
}
