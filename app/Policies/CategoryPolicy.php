<?php

namespace App\Policies;

use App\Policies\Concerns\HandlesCommerceAuthorization;

class CategoryPolicy
{
    use HandlesCommerceAuthorization;

    protected string $area = 'catalog';
}
