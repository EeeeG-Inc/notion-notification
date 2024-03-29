<?php

namespace App\Console\Commands;

use App\Models\TableLess\NotionKptPage;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use Notion\Databases\Database;
use Notion\Databases\Query;
use Notion\Databases\Query\CompoundFilter;
use Notion\Databases\Query\CheckboxFilter;
use Notion\Databases\Query\PeopleFilter;
use Notion\Databases\Query\Result;
use Notion\Notion;
use Notion\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class InactiveKtpPersonSlackNotificationCommand extends Command
{
    private string $databaseId;
    private string $slackKptNotificationWebhook;
    private Notion $notion;
    private array $ignoreNotionUsers;
    private int $deadlineDays;
    private string $tz;
    private Client $guzzle;
    private bool $enableSlackMention;

    public function __construct()
    {
        parent::__construct();

        if((config('app.env') === 'production') || (config('app.env') ===  'prod')) {
            $this->slackKptNotificationWebhook = config('slack.slack_kpt_notification_webhook');
        } else {
            $this->slackKptNotificationWebhook = config('slack.slack_test_webhook');
        }

        $this->enableSlackMention = config('slack.enable_slack_mention');
        $this->databaseId = config('notion.notion_kpt_database_id');
        $this->notion = Notion::create(config('notion.notion_insider_integration_secret'));
        $this->ignoreNotionUsers = [
            'laravel-app',//NotionのKPTデータベースに紐づく内部インテグレーションシークレットアプリ名
        ];
        $this->deadlineDays = 14;
        $this->tz = 'Asia/Tokyo';
        $this->guzzle = new Client();
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
    protected $description = 'Send slack notifications to users who are unable to operate KPT';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $this->info('start:InactiveKtpPersonSlackNotificationCommand');

            $slackUseIds = $this->getSlackUserIds();

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

                foreach($texts as $index => $text) {
                    $isFirstText = ($index === 0) ? true : false;
                    if($isFirstText) {
                        $text = $this->addUserName($notionUser->name, $text, $slackUseIds);

                    }
                    $this->postSlack($text);
                }

            }

        } catch(\Exception $e) {
            Log::error($this->error($e->getMessage()));
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
                    $page->url,
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

        $guzzle = $this->guzzle;
        $guzzle->request(
            'POST',
            $this->slackKptNotificationWebhook,
            [
                'json' => [
                    "text" => $text,
                    "username" => "KPT Bot",
                    "icon_emoji" => ":robot_face:"
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
     * @param string $notionUserName
     * @return array
     */
    private function getSlackTexts(Collection $notionKptPages, string $notionUserName):array
    {
        $texts = [];
        $texts = $this->setTextIfEmpty($texts, $notionKptPages, $notionUserName);
        $texts = $this->setTextNoEditAndNoComment($texts, $notionKptPages, $notionUserName);

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
            $texts[] = ":cry: 現在アクティブな KPT ページが存在しないようです\n"
                . ":arrow_right: *Problem や Try の登録をしましょう！*"
            ;
        }
        return $texts;
    }

    /**
     * 特定日時より新しいコメントがない場合trueを返す
     *
     * @param array $comments
     * @param string $personId
     * @param CarbonImmutable $deadlineDay
     * @return boolean
     */
    private function isNothingComment(array $comments, string $personId, CarbonImmutable $deadlineDay):bool
    {
        $result = true;

        foreach($comments as $comment) {

            if($comment->userId !== $personId) {
                continue;
            }

            $createdTime = new CarbonImmutable($comment->createdTime, $this->tz);

            if($createdTime->gt($deadlineDay)) {
                $result = false;
                break;
            }
        }
        return $result;
    }

    /**
     * 一定期間kptページの編集がなく、かつ振り返りコメントもされてない場合アラートメッセージを設定する。
     *
     * @param array $texts
     * @param Collection $notionKptPages
     * @param string $notionUserName
     * @return array
     */
    private function setTextNoEditAndNoComment(array $texts, Collection $notionKptPages, string $notionUserName):array
    {
        $now = CarbonImmutable::now($this->tz);
        $deadlineDay = $now->subDays($this->deadlineDays);
        $isTextExist = false;
        $text = '';

        foreach($notionKptPages as $notionKptPage) {
            $lastEditedTime = new CarbonImmutable($notionKptPage->lastEditedTime, $this->tz);
            $isNothingComment = $this->isNothingComment($notionKptPage->comments, $notionKptPage->personId, $deadlineDay);

            if($lastEditedTime->lt($deadlineDay) && $isNothingComment) {
                $isTextExist = true;
                $text .= "```<$notionKptPage->notionUrl|{$notionKptPage->kpt}>```";
            }
        }

        if ($isTextExist) {
            $texts[] = $text .
            . ":warning: {$this->deadlineDays} 日以上 KPT ページが編集されていない、もしくは、自分の振り返りコメントがないようです \n"
            . ":arrow_right: *振り返りを実施しましょう！*";
        }

        return $texts;
    }


    /**
     * Slackユーザーを取得する
     *
     * @return array
     */
    private function getSlackUserIds():array
    {
        $result = [];

        if(!$this->enableSlackMention) {
            return $result;
        }

        $slackUseIds = explode(",", config('slack.slack_user_ids'));
        $notionUsers = explode(",", config('notion.notion_users'));

        if(empty($slackUseIds) || empty($notionUsers)) {
            return $result;
        }

        if(count($slackUseIds) !== count($notionUsers)) {
            return $result;
        }

        foreach($notionUsers as $index => $notionUser) {
            $result[$notionUser] = $slackUseIds[$index];
        }

        return $result;
    }

    private function addUserName(string $notionUserName, string $text, array $slackUseIds):string
    {
        if(!$this->enableSlackMention || empty($slackUseIds)) {
            return "*■{$notionUserName}* \n" . $text;
        }

        if(array_key_exists($notionUserName, $slackUseIds)) {
            $mention = $slackUseIds[$notionUserName];
            return "<@{$mention}> \n" . $text;
        }
        return "*■{$notionUserName}* \n" . $text;
    }
}
