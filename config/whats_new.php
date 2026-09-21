<?php

return [

    /*
    |--------------------------------------------------------------------------
    | What's new
    |--------------------------------------------------------------------------
    |
    | Every release, newest first. The popup shows only the first of them,
    | once, to each person the first time they visit after it ships; the full
    | list stays readable at /whats-new.
    |
    | Adding a release to the top is what brings the popup back: its
    | `version` is compared against `users.whats_new_seen`, so a new one
    | shows to everybody exactly once. `features` are things that are new,
    | `fixes` are things that used to go wrong and no longer do.
    |
    */

    'title' => "What's new",

    'lede' => 'A few things have landed since you were last here.',

    'releases' => [
        [
            'version' => '2026.09.2',
            'date' => '2026-09-21',
            'features' => [
                [
                    'title' => 'The company, drawn as a chart',
                    'description' => 'Every department, team and person on one chart, with each person sitting under whoever they report to. The people team can move somebody to a new manager by dragging them on the chart, and that is the record.',
                ],
                [
                    'title' => 'Pay rates set person by person',
                    'description' => 'Payroll can put an individual on their own rates rather than the grade they sit in, for the cases where the grade does not fit.',
                ],
                [
                    'title' => 'Every leave request in one register',
                    'description' => 'The people team now has a register of every request in the company, whatever state it is in, with who raised it, who decided it and when.',
                ],
                [
                    'title' => 'A declined request comes back to you',
                    'description' => 'Leave that is turned down no longer simply ends. It returns to you with the reason it was declined, to amend and send again.',
                ],
                [
                    'title' => 'Approvals skip your own line',
                    'description' => 'A head of department no longer waits on themselves. Where you would have been your own approver, the request goes straight to the next person in the chain.',
                ],
                [
                    'title' => 'Shown round a page the first time',
                    'description' => 'The first time you open a page you have not seen before, a short tour points out what is on it. It runs once, and you can leave it at any point.',
                ],
                [
                    'title' => 'Notices and what is coming, beside every page',
                    'description' => 'Company notices and the things booked ahead of you now sit alongside whatever page you are on, rather than only on the dashboard. Notices also have a page of their own.',
                ],
                [
                    'title' => "A person's whole record on one page",
                    'description' => 'Everything held about somebody, employment, pay, leave, attendance and documents, now reads as one page rather than a set of tabs to hunt through.',
                ],
                [
                    'title' => 'Terminations go through HR',
                    'description' => 'A head of department can put a termination to the people team rather than arranging it off the system. What is recorded includes why the person left and whether a resignation came with notice, and leavers drop out of the company figures.',
                ],
                [
                    'title' => 'The handbook, and what counts as an offence',
                    'description' => 'The staff handbook is published where anybody can read it, together with the disciplinary policy: what counts as an offence, and what follows from it.',
                ],
                [
                    'title' => 'Working out of the office',
                    'description' => 'Say that you are working somewhere other than your site for the day. It is not leave, and it does not come off your allowance.',
                ],
                [
                    'title' => 'Ask before you are late',
                    'description' => 'A late arrival can now be raised the evening before rather than on the morning it happens.',
                ],
                [
                    'title' => 'Requests nobody has decided get chased',
                    'description' => 'An approver who has left a request sitting is reminded about it, so nothing waits indefinitely on somebody who forgot.',
                ],
                [
                    'title' => 'Everybody reports to somebody',
                    'description' => 'Every person now has a manager on their record. It is what the approval chain and the company chart are both built from.',
                ],
                [
                    'title' => 'What is owed to the pension administrator',
                    'description' => 'Payroll can work out what goes to the pension administrator each month, per person and in total.',
                ],
                [
                    'title' => 'Work anniversaries',
                    'description' => 'We write to you on the anniversary of your first day, as we already do on your birthday.',
                ],
                [
                    'title' => 'The attendance report as a spreadsheet',
                    'description' => 'The attendance report can now leave as a spreadsheet, carrying the same filters you had on screen.',
                ],
                [
                    'title' => 'Payslips as a PDF',
                    'description' => 'Any payslip can now be downloaded as a PDF, for when you need a copy to send rather than print.',
                ],
                [
                    'title' => 'One year of leave at a time',
                    'description' => 'The leave page shows a single year, with a switcher for the others. Your balance, the tally and the list of requests now all answer for the same twelve months, and the list can be narrowed by status and type.',
                ],
                [
                    'title' => 'A tidier sidebar',
                    'description' => 'Your payslips now sit under My work beside the rest of your own pages, and a heading with only one page behind it shows that page directly instead of making you open it. For the people team, Payroll sits under People, Who\'s away under Attendance, and incident reports under Conduct with the desk that handles them.',
                ],
            ],
            'fixes' => [
                [
                    'title' => 'Admins are kept to the admin side',
                    'description' => 'Administrators are no longer sent to staff pages they have nothing on: their own payslips, profile, the noticeboard, the chart and the handbook. Each has an admin counterpart, and the sidebar now only offers that one. Their dashboard no longer repeats what the admin console already shows, and the announcements page in the admin area lists every notice, drafts included, rather than the staff noticeboard.',
                ],
                [
                    'title' => 'Requests no longer wait on somebody who has left',
                    'description' => 'A request that was waiting on a leaver to approve it used to wait forever. Their name now comes off any stage they had not ruled on, and a department they headed is left vacant rather than stuck. Whoever they had agreed to cover for is told by email.',
                ],
                [
                    'title' => 'The department warning opens on the right people',
                    'description' => 'The banner saying some people are in no department now opens the staff list narrowed to exactly those people, rather than the whole company.',
                ],
                [
                    'title' => 'Password reset emails no longer hold up the page',
                    'description' => 'Asking for a reset link returns straight away; the email follows a moment later rather than being sent while you wait.',
                ],
            ],
        ],
        [
            'version' => '2026.09.1',
            'date' => '2026-09-05',
            'features' => [
                [
                    'title' => 'A sidebar you can find things in',
                    'description' => 'The administration pages now sit in groups — People, Attendance, Finance and System — rather than one long run. A group opens on the page you are on, and stays shut if you would rather it were.',
                ],
                [
                    'title' => 'Cover reaches whoever you named',
                    'description' => 'Anyone can be asked to hold your desk while you are away. Being named now reaches them even where they have not finished their own profile, so your leave is no longer held up by somebody else\'s paperwork.',
                ],
                [
                    'title' => 'Leave for somebody who owes cover',
                    'description' => 'Agreeing to cover a colleague still stops you booking those same days off yourself. An approver filing on your behalf is now the way through that, as it already was for a closed period.',
                ],
            ],
            'fixes' => [],
        ],
        [
            'version' => '2026.09',
            'date' => '2026-09-05',
            'features' => [
                [
                    'title' => 'Import from a spreadsheet',
                    'description' => 'Administrators can now load sites, staff, HR records, salaries and past attendance in bulk from a CSV. Every sheet comes with a template and a reference, and a file is checked and reported on before anything is written.',
                ],
                [
                    'title' => 'Payslips',
                    'description' => 'Your pay, month by month, with every line of it shown: what you earned, what came off, and what reached your account. Each one prints on its own if you need a copy.',
                ],
                [
                    'title' => 'Report an incident',
                    'description' => 'Raise something that happened to you, something you saw, or the conduct of a colleague, with a file attached if you have one. It is read only by the people team, and your name is never shown to the person you report or to anyone else in the company.',
                ],
                [
                    'title' => 'Leave is counted in working days',
                    'description' => 'It always was, but the pages now say so. Your allowance and everything booked against it are working days at your site: weekends and non-working days are never deducted.',
                ],
            ],
            'fixes' => [],
        ],
        [
            'version' => '2026.08',
            'date' => '2026-08-20',
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
                    'title' => 'Restricted periods',
                    'description' => 'The business can close a stretch of the calendar to leave — a stock count, a year-end close. The leave form says so before you book, and administrators can let certain marital statuses or types of leave through.',
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
            'fixes' => [],
        ],
    ],

];
