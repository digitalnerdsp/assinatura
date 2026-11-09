<?php

declare(strict_types=1);

namespace App\Providers;

use App\Signing\SigningManager;
use App\Signing\Support\SealerClient;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class SigningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/signing.php', 'signing');

        $this->app->singleton(SealerClient::class, fn ($app) => new SealerClient(
            $app->make(Http::class),
            rtrim((string) config('signing.sealer.url'), '/'),
            (string) config('signing.sealer.token'),
            (int) config('signing.sealer.timeout'),
        ));

        $this->app->singleton(SigningManager::class, fn ($app) => new SigningManager(
            $app->make(SealerClient::class),
            $app->make(LoggerInterface::class),
            config('signing'),
        ));
    }
}
