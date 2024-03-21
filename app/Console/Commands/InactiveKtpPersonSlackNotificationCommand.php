<?php

namespace App\Console\Commands;

use App\Models\TableLess\NotionKptPage;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Notion\Databases\Database;
use Notion\Databases\Query;
use Notion\Databases\Query\CompoundFilter;
use Notion\Databases\Query\CheckboxFilter;
use Notion\Databases\Query\PeopleFilter;
use Notion\Databases\Query\Result;
use Notion\Notion;
use Notion\Users\User;
use Illuminate\Support\Collection;

class InactiveKtpPersonSlackNotificationCommand extends Command
{
    private string $databaseId;
    private string $slackTestWebhook;
    private Notion $notion;
    private array $ignoreNotionUsers;

    public function __construct()
    {
        parent::__construct();
        $this->databaseId = config('notion.notion_kpt_database_id');
        $this->slackTestWebhook = config('app.slack_test_webhook');
        $this->notion = Notion::create(config('notion.notion_insider_integration_secret'));
        $this->ignoreNotionUsers = [
            'laravel-app',//NotionのKPTデータベースに紐づく内部インテグレーションシークレットアプリ名
        ];
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

            $this->info('getting users');
            $notionUsers = $this->getNotionUsers();

            $this->info('getting kpt database');
            $kptDatabase = $this->getNotionKptDatabase();

            foreach($notionUsers as $notionUser) {
                if(in_array($notionUser->name, $this->ignoreNotionUsers)) {
                    continue;
                }
                sleep(1);

                $this->info('getting ' . $notionUser->name . "'s kpt");
                $notionKptPages = $this->getNotionKptPagesByNotionUser($notionUser, $kptDatabase);

                $this->info('slack notification : user.name ' . $notionUser->name);
                $texts = $this->getSlackTexts($notionKptPages, $notionUser->name);

                foreach($texts as $text) {
                    $this->postSlack($text);
                }

            }

        } catch(\Exception $e) {
            $this->error($e->getMessage());
        }
        $this->info('end:InactiveKtpPersonSlackNotificationCommand');
    }

    /**
     * NotionKptPageモデルのコレクションを返却する。
     *
     * @param Result $rawResult
     * @return Collection
     */
    private function getNotionKptPageCollection(Result $rawResult):Collection
    {
        $hasMore = true;
        $collection = collect();

        while($hasMore) {
            $pages = $rawResult->pages;

            foreach($pages as $page) {
                $kpt = "";

                foreach($page->properties['KPT']->title as $richText) {
                    $kpt .= $richText->plainText;
                }

                $collection->push(new NotionKptPage(
                    $page->id,
                    $kpt,
                    $page->lastEditedTime,
                    $page->properties['Category']->option->id,
                    $page->properties['Category']->option->name,
                    empty($page->properties['Person']->users) ? "UNDEFINED_USER_ID" : $page->properties['Person']->users[0]->id,
                    empty($page->properties['Person']->users) ? "未設定のPerson" : $page->properties['Person']->users[0]->name,
                ));
            }

            $hasMore = $rawResult->hasMore;

            if($hasMore) {
                $rawResult->nextCursor;
            }
        }

        return $collection;
    }

    /**
     * slackに投稿する
     *
     * @param string $text
     * @return void
     */
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

    /**
     * Notionの全てのユーザーを取得し返却する
     *
     * @return array
     */
    private function getNotionUsers():array
    {
        return $this->notion->users()->findAll();
    }

    /**
     * NotionのKPTデータベースを取得し返却する
     *
     * @return Database
     */
    private function getNotionKptDatabase():Database
    {
        return $this->notion->databases()->find($this->databaseId);
    }

    /**
     * Notionユーザー単位でKPTデータベースのページコレクションとして加工して返却する
     *
     * @param User $notionUser
     * @param Database $kptDatabase
     * @return Collection
     */
    private function getNotionKptPagesByNotionUser(User $notionUser, Database $kptDatabase):Collection
    {
        $rawResult = $this->getRawNotionKptPages($notionUser->id, $kptDatabase);
        $collection = $this->getNotionKptPageCollection($rawResult);

        foreach($collection as $notionKptPage) {
            $pageComments = $this->notion->comments()->list($notionKptPage->id);
            if(!empty($pageComments)) {
                $notionKptPage->setComments($pageComments);
            }
        }

        return $collection;
    }

    /**
     * APIから生のNotionユーザー単位でKPTデータベースのページオブジェクト結果を返却する
     *
     * @param string $personId
     * @param Database $kptDatabase
     * @return Result
     */
    private function getRawNotionKptPages(string $personId, Database $kptDatabase):Result
    {
        $query = Query::create()->changeFilter(
            CompoundFilter::and(
                PeopleFilter::property('Person')->contains($personId),
                CheckboxFilter::property('_非表示')->equals(false),
                CheckboxFilter::property('_非表示K')->equals(false),
                CheckboxFilter::property('_非表示P')->equals(false),
                CheckboxFilter::property('_非表示T')->equals(false),
                CheckboxFilter::property('_全非表示')->equals(false),
            )
        );

        return $this->notion->databases()->query($kptDatabase, $query);
    }

    /**
     * Slackに通知するメッセージ配列を返却
     *
     * @param Collection $notionKptPages
     * @return array
     */
    private function getSlackTexts(Collection $notionKptPages, string $notionUserName):array
    {
        $texts = [];
        $texts = $this->setTextIfEmpty($texts, $notionKptPages, $notionUserName);
        $now = CarbonImmutable::now('Asia/Tokyo');
        $twoWeeksAgo = $now->subWeeks(2);
        foreach($notionKptPages as $notionKptPage) {
            $lastEditedTime = new CarbonImmutable($notionKptPage->lastEditedTime, 'Asia/Tokyo');
            if($lastEditedTime->lt($twoWeeksAgo)) {
                $texts[] = $notionUserName." の「".$notionKptPage->kpt."」は二週間以上編集されていないようです。 \n"
                ."振り返りをしましょう。"
                ;
                //TODO:コメントの中身も見て判別したほうがいい。２週間以上かつ本人のコメントがない場合は振り返りしていないとみなして良いと思う。
            }
        }

        return $texts;
    }

    /**
     * KPTページが一件も存在していない場合にアラートメッセージを設定する。
     *
     * @param array $texts
     * @param Collection $notionKptPages
     * @param string $notionUserName
     * @return array
     */
    private function setTextIfEmpty(array $texts, Collection $notionKptPages, string $notionUserName):array
    {
        if($notionKptPages->isEmpty()) {
            $texts[] = $notionUserName."のアクティブなKPTページが存在していないようです。\n"
                        ."problemやtryの登録をしましょう。"
            ;
        }
        return $texts;
    }
}
