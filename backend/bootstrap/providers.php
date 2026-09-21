<?php

use App\Modules\ModulesServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    ModulesServiceProvider::class,
    AdminPanelProvider::class,
];
