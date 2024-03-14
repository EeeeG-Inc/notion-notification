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
use App\Models\TableLess\NotionKptPage;
use Illuminate\Support\Collection;

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
        $token = config('notion.notion_insider_integration_secret');
        $notion = Notion::create($token);

        $databaseId = config('notion.notion_kpt_database_id');
        $database = $notion->databases()->find($databaseId);
        //todo:Notioから全ユーザー情報を取得して、それを元に全ユーザーがKPTを使ってるか判定する。
        //vendor/mariosimao/notion-sdk-php/src/Users/Client.phpのfindAllを使う。
        $query = Query::create()
        ->changeFilter(
            CompoundFilter::and(
                CheckboxFilter::property('_非表示')->equals(false),
                CheckboxFilter::property('_非表示K')->equals(false),
                CheckboxFilter::property('_非表示P')->equals(false),
                CheckboxFilter::property('_非表示T')->equals(false),
                CheckboxFilter::property('_全非表示')->equals(false),
            )
        );
        // ->addSort(Sort::property("Name")->ascending())
        // ->changePageSize(20);

        $queryResult = $notion->databases()->query($database, $query);

        $notionKptPages = $this->getNotionKptPages($queryResult);
        foreach($notionKptPages as $notionKptPage) {
            $pageComments = $notion->comments()->list($notionKptPage->id);
            if(!empty($pageComments)) {
                $notionKptPage->setComments($pageComments);
            }
        }
        dd($notionKptPages);

        // $guzzle = new Client();
        // $guzzle->request(
        //     'POST',
        //     config('app.slack_test_webhook'),
        //     [
        //         'json' => [
        //             "text" => "Guzzle\Client使ってPOSTしています"
        //         ]
        //     ]
        // );

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
}
