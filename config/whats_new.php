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
    | A note that only concerns some people carries an `audience`: a list
    | of permission values (`payroll.manage`), or `staff` (anyone who clocks
    | in), `approvers`, `heads` (of a department) or `admins`. Matching any
    | one entry is enough; a note without an audience is for everybody, and
    | a release with nothing left for somebody is not shown to them at all.
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
                    'audience' => ['payroll.manage'],
                    'description' => 'Payroll can put an individual on their own rates rather than the grade they sit in, for the cases where the grade does not fit.',
                ],
                [
                    'title' => 'Every leave request in one register',
                    'audience' => ['leave.register'],
                    'description' => 'The people team now has a register of every request in the company, whatever state it is in, with who raised it, who decided it and when.',
                ],
                [
                    'title' => 'A declined request comes back to you',
                    'audience' => ['staff'],
                    'description' => 'Leave that is turned down no longer simply ends. It returns to you with the reason it was declined, to amend and send again.',
                ],
                [
                    'title' => 'Approvals skip your own line',
                    'audience' => ['approvers'],
                    'description' => 'A head of department no longer waits on themselves. Where you would have been your own approver, the request goes straight to the next person in the chain.',
                ],
                [
                    'title' => 'Shown round a page the first time',
                    'description' => 'The first time you open a page you have not seen before, a short tour points out what is on it. It runs once, and you can leave it at any point.',
                ],
                [
                    'title' => 'Notices and what is coming, beside every page',
                    'audience' => ['staff'],
                    'description' => 'Company notices and the things booked ahead of you now sit alongside whatever page you are on, rather than only on the dashboard. Notices also have a page of their own.',
                ],
                [
                    'title' => "A person's whole record on one page",
                    'audience' => ['staff.manage'],
                    'description' => 'Everything held about somebody, employment, pay, leave, attendance and documents, now reads as one page rather than a set of tabs to hunt through.',
                ],
                [
                    'title' => 'Terminations go through HR',
                    'audience' => ['heads', 'staff.manage'],
                    'description' => 'A head of department can put a termination to the people team rather than arranging it off the system. What is recorded includes why the person left and whether a resignation came with notice, and leavers drop out of the company figures.',
                ],
                [
                    'title' => 'The handbook, and what counts as an offence',
                    'audience' => ['staff', 'policies.manage'],
                    'description' => 'The staff handbook is published where anybody can read it, together with the disciplinary policy: what counts as an offence, and what follows from it.',
                ],
                [
                    'title' => 'Working out of the office',
                    'audience' => ['staff'],
                    'description' => 'Say that you are working somewhere other than your site for the day. It is not leave, and it does not come off your allowance.',
                ],
                [
                    'title' => 'Ask before you are late',
                    'audience' => ['staff'],
                    'description' => 'A late arrival can now be raised the evening before rather than on the morning it happens.',
                ],
                [
                    'title' => 'Requests nobody has decided get chased',
                    'audience' => ['approvers'],
                    'description' => 'An approver who has left a request sitting is reminded about it, so nothing waits indefinitely on somebody who forgot.',
                ],
                [
                    'title' => 'Everybody reports to somebody',
                    'description' => 'Every person now has a manager on their record. It is what the approval chain and the company chart are both built from.',
                ],
                [
                    'title' => 'What is owed to the pension administrator',
                    'audience' => ['payroll.manage'],
                    'description' => 'Payroll can work out what goes to the pension administrator each month, per person and in total.',
                ],
                [
                    'title' => 'Work anniversaries',
                    'audience' => ['staff'],
                    'description' => 'We write to you on the anniversary of your first day, as we already do on your birthday.',
                ],
                [
                    'title' => 'The attendance report as a spreadsheet',
                    'audience' => ['attendance.report'],
                    'description' => 'The attendance report can now leave as a spreadsheet, carrying the same filters you had on screen.',
                ],
                [
                    'title' => 'Payslips as a PDF',
                    'audience' => ['staff', 'payroll.manage'],
                    'description' => 'Any payslip can now be downloaded as a PDF, for when you need a copy to send rather than print.',
                ],
                [
                    'title' => 'One year of leave at a time',
                    'audience' => ['staff'],
                    'description' => 'The leave page shows a single year, with a switcher for the others. Your balance, the tally and the list of requests now all answer for the same twelve months, and the list can be narrowed by status and type.',
                ],
                [
                    'title' => 'A tidier sidebar',
                    'description' => 'Your payslips now sit under My work beside the rest of your own pages, and a heading with only one page behind it shows that page directly instead of making you open it. For the people team, Payroll sits under People, Who\'s away under Attendance, and incident reports under Conduct with the desk that handles them.',
                ],
                [
                    'title' => 'Public holidays',
                    'audience' => ['staff'],
                    'description' => 'The days the company is off now show beside every page as they come up, and on your dashboard on the day itself. They are never taken from your leave, and the leave form says which ones it has left out.',
                ],
                [
                    'title' => 'Set the year\'s public holidays',
                    'audience' => ['locations.manage', 'attendance.report'],
                    'description' => 'Add the days the company is off under Attendance, then Public holidays. Every site is off on them whatever its own week says, so they come off the days everyone is expected in on the attendance report and nobody is marked absent for them.',
                ],
                [
                    'title' => 'Policies open in your browser',
                    'audience' => ['staff', 'policies.manage'],
                    'description' => 'Policies are now published as PDFs, and open in a new tab to be read rather than downloading, so there is no copy sitting in your downloads to go out of date.',
                ],
                [
                    'title' => 'Invitations instead of sign-up',
                    'audience' => ['staff.manage'],
                    'description' => 'Nobody signs themselves up any more. Adding somebody, by hand or from a spreadsheet, emails them a link to choose their own password, so nobody has to type or pass on a password for anyone else. The staff list shows who has not got in yet, with a button to send the link again.',
                ],
                [
                    'title' => 'A guided start',
                    'audience' => ['staff'],
                    'description' => 'Anyone with details still to give is walked through them a step at a time: about you, then family, then your bank account. Only the first step is required, and anything skipped can be filled in later from My profile.',
                ],
                [
                    'title' => 'An administrator has the final say on out of office',
                    'audience' => ['staff', 'admins'],
                    'description' => 'A day out of the office still goes to your team lead and head of department first, and then to an administrator. It is only agreed once an administrator has approved it.',
                ],
            ],
            'fixes' => [
                [
                    'title' => 'Admins are kept to the admin side',
                    'audience' => ['admins'],
                    'description' => 'Administrators are no longer sent to staff pages they have nothing on: their own payslips, profile, the noticeboard, the chart and the handbook. Each has an admin counterpart, and the sidebar now only offers that one. Their dashboard no longer repeats what the admin console already shows, and the announcements page in the admin area lists every notice, drafts included, rather than the staff noticeboard.',
                ],
                [
                    'title' => 'Requests no longer wait on somebody who has left',
                    'description' => 'A request that was waiting on a leaver to approve it used to wait forever. Their name now comes off any stage they had not ruled on, and a department they headed is left vacant rather than stuck. Whoever they had agreed to cover for is told by email.',
                ],
                [
                    'title' => 'The department warning opens on the right people',
                    'audience' => ['staff.manage'],
                    'description' => 'The banner saying some people are in no department now opens the staff list narrowed to exactly those people, rather than the whole company.',
                ],
                [
                    'title' => 'Password reset emails no longer hold up the page',
                    'description' => 'Asking for a reset link returns straight away; the email follows a moment later rather than being sent while you wait.',
                ],
                [
                    'title' => 'Errors in plain words',
                    'description' => 'A page you cannot open, or a link that has run out, now says so on a page of the app\'s own, in words you can act on, rather than a technical message laid over what you were doing. A form left open too long takes you back to it to try again.',
                ],
                [
                    'title' => 'New starters can put these notes away',
                    'audience' => ['staff'],
                    'description' => 'Closing these notes or a page tour used to fail for anybody still finishing their profile. It works now, and somebody who has just joined is not shown notes about changes from before they arrived.',
                ],
                [
                    'title' => 'Invitation emails fit the person reading them',
                    'audience' => ['staff.manage'],
                    'description' => 'Invitations and password reset emails no longer offer a link to notification settings the reader cannot reach and could not use to switch them off.',
                ],
            ],
        ],
        [
            'version' => '2026.09.1',
            'date' => '2026-09-05',
            'features' => [
                [
                    'title' => 'A sidebar you can find things in',
                    'audience' => ['admin.dashboard'],
                    'description' => 'The administration pages now sit in groups — People, Attendance, Finance and System — rather than one long run. A group opens on the page you are on, and stays shut if you would rather it were.',
                ],
                [
                    'title' => 'Cover reaches whoever you named',
                    'audience' => ['staff'],
                    'description' => 'Anyone can be asked to hold your desk while you are away. Being named now reaches them even where they have not finished their own profile, so your leave is no longer held up by somebody else\'s paperwork.',
                ],
                [
                    'title' => 'Leave for somebody who owes cover',
                    'audience' => ['staff'],
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
                    'audience' => ['data.import'],
                    'description' => 'Administrators can now load sites, staff, HR records, salaries and past attendance in bulk from a CSV. Every sheet comes with a template and a reference, and a file is checked and reported on before anything is written.',
                ],
                [
                    'title' => 'Payslips',
                    'audience' => ['staff'],
                    'description' => 'Your pay, month by month, with every line of it shown: what you earned, what came off, and what reached your account. Each one prints on its own if you need a copy.',
                ],
                [
                    'title' => 'Report an incident',
                    'description' => 'Raise something that happened to you, something you saw, or the conduct of a colleague, with a file attached if you have one. It is read only by the people team, and your name is never shown to the person you report or to anyone else in the company.',
                ],
                [
                    'title' => 'Leave is counted in working days',
                    'audience' => ['staff'],
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
                    'audience' => ['staff'],
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
                    'audience' => ['staff'],
                    'description' => 'We will write to you on your birthday. Nobody else is told; it is your day to share if you want to.',
                ],
                [
                    'title' => 'Restricted periods',
                    'audience' => ['staff', 'request-settings.manage'],
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
