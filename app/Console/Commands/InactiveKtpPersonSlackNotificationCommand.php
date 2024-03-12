<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use \GuzzleHttp\Client;

class InactiveKtpPersonSlackNotificationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'slack:inactive-kpt-person-notification-command';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $guzzle = new Client();
        $guzzle->request(
            'POST',
            config('app.slack_test_webhook'),
            [
                'json' => [
                    "text" => "Guzzle\Client使ってPOSTしています"
                ]
            ]
        );

    }
}
