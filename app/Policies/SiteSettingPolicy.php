<?php

namespace App\Policies;

use App\Policies\Concerns\HandlesCommerceAuthorization;

class SiteSettingPolicy
{
    use HandlesCommerceAuthorization;

    protected string $area = 'settings';
}
