{{--
    The payslip as a document rather than as a page.

    Written plainly on purpose: dompdf renders a small, old subset of CSS, so
    there is no flexbox, no grid, no custom properties and no Tailwind here.
    Tables and inline widths are what survive the trip, and this file does not
    share a stylesheet with the screen so a change to the app's theme can never
    quietly break a payslip somebody is filing.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip {{ $payslip->periodLabel() }} — {{ $employee['name'] }}</title>
    <style>
        @page { margin: 34px 40px; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #101828;
            margin: 0;
        }

        h1 { font-size: 17px; margin: 3px 0 0; letter-spacing: -0.3px; }

        .eyebrow {
            font-size: 8px;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: #5b6779;
            margin: 0;
        }

        .muted { color: #5b6779; }
        .faint { color: #8996a8; }
        .right { text-align: right; }
        .num { font-family: DejaVu Sans Mono, monospace; }

        table { width: 100%; border-collapse: collapse; }

        .rule td { border-bottom: 1px solid #d3dae6; padding-bottom: 12px; }

        .facts td {
            padding: 9px 0;
            border-bottom: 1px solid #e5eaf2;
            vertical-align: top;
            width: 50%;
        }

        .label { font-size: 8px; color: #8996a8; display: block; margin-bottom: 2px; }

        .col { width: 50%; vertical-align: top; }
        .col-left { padding-right: 14px; }
        .col-right { padding-left: 14px; }

        .heading {
            font-size: 8px;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #8996a8;
            padding-bottom: 6px;
        }

        .lines td { padding: 3px 0; }
        .lines .basis { font-size: 8px; color: #8996a8; padding: 0 0 4px; }

        .total td {
            border-top: 1px solid #d3dae6;
            padding-top: 6px;
            font-weight: bold;
        }

        .net {
            background: #e3e8f0;
            padding: 11px 14px;
            margin-top: 18px;
        }

        .net .amount { font-size: 15px; font-weight: bold; }

        .note { font-size: 8.5px; color: #8996a8; margin-top: 14px; line-height: 1.5; }
    </style>
</head>
<body>

<table class="rule">
    <tr>
        <td>
            <p class="eyebrow">{{ $company }}</p>
            <h1>Payslip</h1>
            <p class="muted" style="margin: 3px 0 0;">{{ $payslip->periodLabel() }}</p>
        </td>
        <td class="right faint" style="vertical-align: bottom;">
            @if ($issued)
                Issued {{ $issued }}
            @endif
        </td>
    </tr>
</table>

<table class="facts">
    <tr>
        <td>
            <span class="label">Employee</span>
            <strong>{{ $employee['name'] }}</strong>
        </td>
        <td>
            <span class="label">Staff ID</span>
            {{ $employee['employee_id'] ?? '—' }}
        </td>
    </tr>
    <tr>
        <td>
            <span class="label">Position</span>
            {{ $employee['position'] ?? '—' }}@if ($employee['department'])<span class="muted"> · {{ $employee['department'] }}</span>@endif
        </td>
        <td>
            <span class="label">Paid into</span>
            @if ($employee['bank_name'])
                {{ $employee['bank_name'] }}
                @if ($employee['account_tail'])
                    <span class="num muted">{{ $employee['account_tail'] }}</span>
                @endif
            @else
                <span class="muted">No bank details on file</span>
            @endif
        </td>
    </tr>
</table>

<table style="margin-top: 16px;">
    <tr>
        <td class="col col-left">
            <table>
                <tr><td class="heading" colspan="2">Earnings</td></tr>
            </table>

            <table class="lines">
                @foreach ($payslip->earnings as $line)
                    <tr>
                        <td class="muted">{{ $line['label'] }}</td>
                        <td class="right num">{{ $show($line['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td>Gross pay</td>
                    <td class="right num">{{ $show($payslip->gross_pay) }}</td>
                </tr>
            </table>
        </td>

        <td class="col col-right">
            <table>
                <tr><td class="heading" colspan="2">Deductions</td></tr>
            </table>

            <table class="lines">
                @forelse ($payslip->deductions as $line)
                    <tr>
                        <td class="muted">{{ $line['label'] }}</td>
                        <td class="right num">{{ $show($line['amount']) }}</td>
                    </tr>
                    @if (! empty($line['basis']))
                        <tr><td class="basis" colspan="2">{{ $line['basis'] }}</td></tr>
                    @endif
                @empty
                    <tr>
                        <td class="muted" colspan="2">Nothing was deducted this month.</td>
                    </tr>
                @endforelse
                <tr class="total">
                    <td>Total deductions</td>
                    <td class="right num">{{ $show($payslip->total_deductions) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<div class="net">
    <table>
        <tr>
            <td><strong>Take-home pay</strong></td>
            <td class="right num amount">{{ $show($payslip->net_pay) }}</td>
        </tr>
    </table>
</div>

@if ($payslip->employer_pension > 0)
    <p class="note">
        Your employer also paid {{ $show($payslip->employer_pension) }} into your
        pension this month. That is on top of your pay, not taken from it.
    </p>
@endif

<p class="note">
    If anything here looks wrong, raise it with the people team before the next
    payroll closes.
</p>

</body>
</html>
