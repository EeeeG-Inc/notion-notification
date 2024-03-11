<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Slack;

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
        //
        Slack::send('送信したいメッセージ');

    }
}
