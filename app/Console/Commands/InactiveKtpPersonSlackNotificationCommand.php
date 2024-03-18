<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use Notion\Notion;
use Notion\Databases\Query;
use Notion\Databases\Query\CompoundFilter;
use Notion\Databases\Query\DateFilter;
use Notion\Databases\Query\Sort;
use Notion\Databases\Query\TextFilter;
use Notion\Databases\Query\CheckboxFilter;
use Notion\Databases\Query\PeopleFilter;
use App\Models\TableLess\NotionKptPage;
use Exception;
use Illuminate\Support\Collection;

class InactiveKtpPersonSlackNotificationCommand extends Command
{
    private string $token;
    private string $databaseId;
    private string $slackTestWebhook;

    public function __construct()
    {
        parent::__construct();
        $this->token = config('notion.notion_insider_integration_secret');
        $this->databaseId = config('notion.notion_kpt_database_id');
        $this->slackTestWebhook = config('app.slack_test_webhook');
    }
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
        try {
            $this->info('start:InactiveKtpPersonSlackNotificationCommand');

            $notion = Notion::create($this->token);
            $this->info('getting users');
            $users = $notion->users()->findAll();
            $this->info('getting kpt database');
            $database = $notion->databases()->find($this->databaseId);

            foreach($users as $user) {
                sleep(1);
                $this->info('getting' . $user->name . " 's kpt");
                $query = Query::create()->changeFilter(
                    CompoundFilter::and(
                        PeopleFilter::property('Person')->contains($user->id),
                        CheckboxFilter::property('_非表示')->equals(false),
                        CheckboxFilter::property('_非表示K')->equals(false),
                        CheckboxFilter::property('_非表示P')->equals(false),
                        CheckboxFilter::property('_非表示T')->equals(false),
                        CheckboxFilter::property('_全非表示')->equals(false),
                    )
                );

                $queryResult = $notion->databases()->query($database, $query);

                $notionKptPages = $this->getNotionKptPages($queryResult);
                foreach($notionKptPages as $notionKptPage) {
                    $pageComments = $notion->comments()->list($notionKptPage->id);
                    if(!empty($pageComments)) {
                        $notionKptPage->setComments($pageComments);
                    }
                }
                dd($notionKptPages);
                $this->info('slack notification : user.name ' . $user->name);
                $text = "";
                $this->postSlack($text);
            }
        } catch(\Exception $e) {
            $this->error($e->getMessage());
        }
        $this->info('end:InactiveKtpPersonSlackNotificationCommand');
    }

    private function getNotionKptPages($queryResult):Collection
    {
        $hasMore = true;
        $result = collect();
        while($hasMore) {
            $pages = $queryResult->pages;
            foreach($pages as $page) {
                $kpt = "";
                foreach($page->properties['KPT']->title as $richText) {
                    $kpt .= $richText->plainText;
                }
                $result->push(new NotionKptPage(
                    $page->id,
                    $kpt,
                    $page->lastEditedTime,
                    $page->properties['Category']->option->id,
                    $page->properties['Category']->option->name,
                    empty($page->properties['Person']->users) ? "UNDEFINED_USER_ID" : $page->properties['Person']->users[0]->id,
                    empty($page->properties['Person']->users) ? "未設定のPerson" : $page->properties['Person']->users[0]->name,
                ));
            }
            $hasMore = $queryResult->hasMore;
            if($hasMore) {
                $queryResult->nextCursor;
            }
        }
        return $result;
    }

    private function postSlack(string $text):void
    {

        $guzzle = new Client();
        $guzzle->request(
            'POST',
            $this->slackTestWebhook,
            [
                'json' => [
                    "text" => $text
                ]
            ]
        );
    }
}
