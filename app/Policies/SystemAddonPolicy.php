<?php

namespace App\Policies;

use App\Policies\Concerns\HandlesCommerceAuthorization;

class SystemAddonPolicy
{
    use HandlesCommerceAuthorization;

    protected string $area = 'system';
}
