<?php

return [

    /*
    |--------------------------------------------------------------------------
    | What's new
    |--------------------------------------------------------------------------
    |
    | The release notes shown once, in a popup, to each person the first time
    | they visit after a release. Bumping `version` is what brings the popup
    | back: it is compared against `users.whats_new_seen`, so a bump shows the
    | new list to everybody exactly once.
    |
    */

    'version' => '2026.08',

    'title' => "What's new",

    'lede' => 'A few things have landed since you were last here.',

    'features' => [
        [
            'title' => 'Requests raised for you',
            'description' => 'An approver can now file leave or lateness on behalf of someone who cannot get to the app. It still runs the usual approval chain, and it still belongs to the person it is for.',
        ],
        [
            'title' => 'Email when a request needs you',
            'description' => 'Cover requests and approvals now reach you by email rather than waiting to be noticed in the inbox, and everyone hears back when their request is decided.',
        ],
        [
            'title' => 'Company announcements',
            'description' => 'Administrators can publish a notice to the whole company. It lands on the dashboard and in everyone\'s inbox at the same time.',
        ],
        [
            'title' => "Who's away",
            'description' => 'A company-wide leave roster: who is out today, when they are back, and what is booked ahead. It sits in the sidebar, and the count also shows on your dashboard.',
        ],
        [
            'title' => 'Birthday greetings',
            'description' => 'We will write to you on your birthday. Nobody else is told; it is your day to share if you want to.',
        ],
        [
            'title' => 'Push notifications',
            'description' => 'Turn push on and the same alerts reach you in the browser, even with the tab closed.',
        ],
        [
            'title' => 'Notification settings',
            'description' => 'Everything above is yours to switch off, per topic and per channel, under Notifications in your account menu.',
        ],
    ],

];
