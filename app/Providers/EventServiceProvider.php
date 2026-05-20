<?php

namespace App\Providers;

use App\Events\WalletCredited;
use App\Events\WalletDebited;
use App\Listeners\LogWalletActivity;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        WalletCredited::class => [
            LogWalletActivity::class,
        ],
        WalletDebited::class => [
            LogWalletActivity::class,
        ],
    ];

    protected static $shouldDiscoverEvents = false;
}
