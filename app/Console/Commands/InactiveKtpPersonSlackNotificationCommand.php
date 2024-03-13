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
use App\Models\TableLess\NotionPage;
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

        $query = Query::create();
        // ->changeFilter(
        //     CompoundFilter::and(
        //         DateFilter::createdTime()->pastWeek(),
        //         TextFilter::property("Name")->contains("John"),
        //     )
        // )
        // ->addSort(Sort::property("Name")->ascending())
        // ->changePageSize(20);

        $queryResult = $notion->databases()->query($database, $query);
        $result = $this->getPage($queryResult);
        dd($result);

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

    private function getPage($queryResult):Collection
    {
        $hasMore = true;
        $result = collect();
        while($hasMore) {
            $pages = $queryResult->pages;
            foreach($pages as $page) {
                $result->push(new NotionPage($page->id));
            }
            $hasMore = $queryResult->hasMore;
            if($hasMore) {
                $queryResult->nextCursor;
            }
        }
        return $result;
    }
}
