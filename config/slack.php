<?php

return [
    'slack_test_webhook' => env('SLACK_TEST_WEBHOOK'),
    'slack_kpt_notification_webhook' => env('SLACK_KPT_NOTIFICATION_WEBHOOK'),
    'slack_user_ids'=> env('SLACK_USER_IDS', ""),
    'enable_slack_mention'=> env('ENABLE_SLACK_MENTION', false),
];
