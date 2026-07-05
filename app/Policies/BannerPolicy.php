<?php

namespace App\Policies;

use App\Policies\Concerns\HandlesCommerceAuthorization;

class BannerPolicy
{
    use HandlesCommerceAuthorization;

    protected string $area = 'marketing';
}
