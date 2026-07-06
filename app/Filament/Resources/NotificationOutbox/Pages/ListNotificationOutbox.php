<?php

namespace App\Filament\Resources\NotificationOutbox\Pages;

use App\Filament\Resources\NotificationOutbox\NotificationOutboxResource;
use Filament\Resources\Pages\ListRecords;

class ListNotificationOutbox extends ListRecords
{
    protected static string $resource = NotificationOutboxResource::class;
}
