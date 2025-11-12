<?php

namespace App\Providers;

use Google\Client;
use Google\Service\Drive;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Masbug\Flysystem\GoogleDriveAdapter;
use League\Flysystem\Filesystem;

class GoogleDriveServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Storage::extend('google', function ($app, $config) {
            \Illuminate\Support\Facades\Log::info('--- Google Drive Config ---');
            \Illuminate\Support\Facades\Log::info('Folder ID: ' . ($config['folder'] ?? 'Not Set'));
            \Illuminate\Support\Facades\Log::info('Team Drive ID: ' . ($config['teamDriveId'] ?? 'Not Set'));
            \Illuminate\Support\Facades\Log::info('--- End Google Drive Config ---');

            // --- Start of SSL fix ---
            $caBundlePath = null;
            $iniPath = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
            if ($iniPath && file_exists($iniPath)) {
                $caBundlePath = $iniPath;
            }
            if (!$caBundlePath) {
                $publicCertPath = public_path('cacert.pem');
                if (file_exists($publicCertPath)) {
                    $caBundlePath = $publicCertPath;
                }
            }

            $guzzleClientOptions = [];
            if ($caBundlePath) {
                $guzzleClientOptions['verify'] = $caBundlePath;
            }
            // --- End of SSL fix ---

            $options = []; // Options array is kept for future compatibility if needed

            $client = new Client();
            if (!empty($guzzleClientOptions)) {
                $httpClient = new \GuzzleHttp\Client($guzzleClientOptions);
                $client->setHttpClient($httpClient);
            }
            $client->setClientId($config['clientId']);
            $client->setClientSecret($config['clientSecret']);
            $client->refreshToken($config['refreshToken']);

            $service = new Drive($client);
            
            // Force the adapter to use the 'folder' ID as the root, ignoring any team drive settings.
            $adapter = new GoogleDriveAdapter($service, $config['folder'] ?? '/', $options);

            return new \Illuminate\Filesystem\FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });
    }
}