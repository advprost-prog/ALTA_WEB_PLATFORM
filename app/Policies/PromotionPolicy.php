<?php

namespace App\Policies;

use App\Policies\Concerns\HandlesCommerceAuthorization;

class PromotionPolicy
{
    use HandlesCommerceAuthorization;

    protected string $area = 'marketing';
}
