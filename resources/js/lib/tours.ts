/**
 * The walkthroughs.
 *
 * Each step points at something already on the page by `data-tour` attribute,
 * so a tour can never describe a button that is not there: if the anchor is
 * missing the step is skipped rather than pointing at empty space.
 *
 * Keyed by the Inertia page component, which is what decides whether a tour
 * belongs on the page somebody has just opened.
 */
export type TourStep = {
    /** The `data-tour` value to spotlight. Omitted for a step that is just text. */
    anchor?: string;
    title: string;
    body: string;
};

export type Tour = {
    /** Stored against the person once they have been through it. */
    id: string;
    steps: TourStep[];
};

export const tours: Record<string, Tour> = {
    Dashboard: {
        id: 'dashboard',
        steps: [
            {
                title: 'Welcome',
                body: 'A quick walk round, about thirty seconds. You can stop at any point, and start it again from the help button in the top bar.',
            },
            {
                anchor: 'clock',
                title: 'Clocking in and out',
                body: 'This is where your day starts and ends. It checks you are at your site before it records anything, so turn location on when your phone asks.',
            },
            {
                anchor: 'nav-my-work',
                title: 'Asking for things',
                body: 'Your attendance, leave, lateness and days worked away from the office all live here. Each request goes to your team lead and your head of department before anybody else sees it.',
            },
            {
                anchor: 'nav-finance',
                title: 'Your payslips',
                body: 'Payslips appear here once the month has been signed off. They print, and the figures are frozen as they were paid.',
            },
            {
                anchor: 'notice-rail',
                title: 'What is going on',
                body: 'Company notices and what is coming up: birthdays, who is away, and days people are working elsewhere.',
            },
        ],
    },
    Leave: {
        id: 'leave',
        steps: [
            {
                anchor: 'leave-balances',
                title: 'What you have left',
                body: 'Your allowance for the year, and what each type of leave has already taken out of it.',
            },
            {
                anchor: 'leave-raise',
                title: 'Booking time off',
                body: 'Name a colleague to cover your desk and the approver who decides. Cover is agreed first, then it goes up the line.',
            },
        ],
    },
    Lateness: {
        id: 'lateness',
        steps: [
            {
                title: 'Say it ahead of the morning',
                body: 'A late arrival is raised before the working day starts, not explained afterwards. The page says when filing closes.',
            },
        ],
    },
    OutOfOffice: {
        id: 'out-of-office',
        steps: [
            {
                title: 'Working, elsewhere',
                body: 'From home, or out on company business. Nothing comes off your leave, and the roster shows you as working rather than away.',
            },
        ],
    },
    Payslip: {
        id: 'payslip',
        steps: [
            {
                title: 'Reading your payslip',
                body: 'Earnings on one side, deductions on the other, each with the basis it was worked out on. The print button gives you a clean copy.',
            },
        ],
    },
};

export function tourFor(component: string): Tour | null {
    return tours[component] ?? null;
}
