<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function generate_salary_slip_pdf($data, $month_name, $year)
{
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Helvetica');

    $dompdf = new Dompdf($options);

    $net = number_format($data['net_salary'] ?? 0, 2);
    $basic = number_format($data['basic_salary'] ?? 0, 2);
    $ot = number_format($data['ot_amount'] ?? 0, 2);
    $bonus = number_format($data['bonus_amount'] ?? 0, 2);
    $deduction = number_format($data['deduction_amount'] ?? 0, 2);
    $gross = number_format($data['gross_salary'] ?? 0, 2);
    $allowance = number_format($data['allowance_amount'] ?? 0, 2);
    $tax = number_format($data['tax_amount'] ?? 0, 2);
    $leave_ded = number_format($data['leave_deduction'] ?? 0, 2);
    $awol_ded = number_format($data['awol_deduction'] ?? 0, 2);
    $present = $data['present_days'] ?? 0;
    $half = $data['half_days'] ?? 0;
    $late = $data['late_days'] ?? 0;
    $absent = $data['absent_days'] ?? 0;
    $paid_leave = $data['paid_leave_days'] ?? 0;
    $unpaid_leave = $data['unpaid_leave_days'] ?? 0;
    $ot_hours = number_format($data['overtime_hours'] ?? 0, 1);
    $working_days = $data['working_days'] ?? 0;
    $position = $data['position_name'] ?? ($data['department'] ?? 'General');
    $payroll_status = $data['status'] ?? 'Generated';
    $late_ded_fmt = number_format($data['late_deduction'] ?? 0, 2);
    $unpaid_leave_ded_fmt = number_format($data['unpaid_leave_deduction'] ?? 0, 2);
    $leave_ded_fmt = number_format(($data['leave_deduction'] ?? 0), 2);
    $absent_ded_fmt = number_format(($data['absent_deduction'] ?? 0), 2);
    $half_ded_fmt = number_format(($data['half_day_deduction'] ?? 0), 2);
    $paid_leave_ded = number_format(($data['paid_leave_deduction'] ?? 0), 2);
    $total_deductions = number_format(($data['deduction_amount'] ?? 0) + ($data['late_deduction'] ?? 0) + ($data['unpaid_leave_deduction'] ?? 0) + ($data['tax_amount'] ?? 0), 2);

    $html = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { margin: 20px 30px; }
body {
    font-family: "Helvetica", "Arial", sans-serif;
    color: #1e293b;
    margin: 0;
    padding: 0;
}
.slip-wrapper {
    width: 100%;
    max-width: 700px;
    margin: 0 auto;
}
.header {
    text-align: center;
    padding-bottom: 12px;
    border-bottom: 3px solid #1e293b;
    margin-bottom: 16px;
}
.header h1 {
    font-size: 24px;
    color: #1e293b;
    margin: 0 0 4px;
    letter-spacing: 1px;
}
.header .sub {
    font-size: 11px;
    color: #64748b;
    margin: 0;
}
.header .contact {
    font-size: 9px;
    color: #94a3b8;
    margin: 4px 0 0;
}
.title-bar {
    background: #1e293b;
    color: #ffffff;
    text-align: center;
    padding: 10px 0;
    font-size: 16px;
    font-weight: bold;
    letter-spacing: 1px;
    margin-bottom: 16px;
}
.info-box {
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 12px 16px;
    margin-bottom: 16px;
}
.info-grid {
    width: 100%;
    border-collapse: collapse;
}
.info-grid td {
    padding: 4px 0;
    font-size: 11px;
}
.info-grid .label {
    color: #64748b;
    width: 50%;
}
.info-grid .value {
    font-weight: bold;
    color: #1e293b;
}
.attendance-bar {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 10px 16px;
    margin-bottom: 16px;
}
.attendance-bar h3 {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin: 0 0 8px;
}
.att-grid {
    width: 100%;
    border-collapse: collapse;
}
.att-grid td {
    text-align: center;
    padding: 4px 8px;
    font-size: 10px;
}
.att-grid .att-num {
    font-weight: bold;
    font-size: 14px;
    color: #1e293b;
}
.att-grid .att-label {
    color: #64748b;
    font-size: 9px;
    text-transform: uppercase;
}
.table-section {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 16px;
}
.table-section th {
    background: #2563eb;
    color: #ffffff;
    padding: 8px 12px;
    text-align: left;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.table-section td {
    padding: 7px 12px;
    font-size: 11px;
    border-bottom: 1px solid #e2e8f0;
}
.table-section tr:nth-child(even) td {
    background: #f8fafc;
}
.table-section .amount {
    text-align: right;
    font-family: "Courier New", monospace;
}
.table-section .total-row td {
    font-weight: bold;
    border-top: 2px solid #1e293b;
    background: #f1f5f9;
}
.earnings { width: 48%; float: left; }
.deductions { width: 48%; float: right; }
.clearfix { clear: both; }
.net-box {
    background: #1e293b;
    color: #ffffff;
    padding: 14px 20px;
    border-radius: 6px;
    margin-bottom: 16px;
    text-align: center;
}
.net-box .net-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.8;
}
.net-box .net-amount {
    font-size: 26px;
    font-weight: bold;
    color: #fbbf24;
}
.badge {
    display: inline-block;
    background: #16a34a;
    color: #ffffff;
    padding: 5px 20px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: bold;
    margin-bottom: 16px;
}
.signature {
    border-top: 1px solid #cbd5e1;
    padding-top: 12px;
    margin-top: 8px;
}
.signature-table {
    width: 100%;
    border-collapse: collapse;
}
.signature-table td {
    width: 33%;
    text-align: center;
    font-size: 9px;
    color: #64748b;
}
.signature-table .line {
    width: 120px;
    border-top: 1px solid #1e293b;
    margin: 0 auto 4px;
}
.footer {
    text-align: center;
    font-size: 8px;
    color: #94a3b8;
    margin-top: 12px;
    padding-top: 8px;
    border-top: 1px solid #e2e8f0;
}
</style>
</head>
<body>
<div class="slip-wrapper">

    <div class="header">
        <h1>HNIN AKARI NWE</h1>
        <p class="sub">Payroll Management System</p>
        <p class="contact">Yangon, Myanmar &nbsp;|&nbsp; info@hninakarinwe.com</p>
    </div>

    <div class="title-bar">SALARY SLIP &mdash; ' . $month_name . ' ' . $year . '</div>

    <div class="info-box">
        <table class="info-grid">
            <tr>
                <td class="label">Employee Name</td>
                <td class="value">' . htmlspecialchars($data['name'] ?? '') . '</td>
                <td class="label">Employee Code</td>
                <td class="value">' . htmlspecialchars($data['employee_code'] ?? '') . '</td>
            </tr>
            <tr>
                <td class="label">Department</td>
                <td class="value">' . htmlspecialchars($data['department'] ?? 'General') . '</td>
                <td class="label">Position</td>
                <td class="value">' . htmlspecialchars($data['position_name'] ?? $position) . '</td>
            </tr>
            <tr>
                <td class="label">Pay Period</td>
                <td class="value">' . $month_name . ' ' . $year . '</td>
                <td class="label">Status</td>
                <td class="value">' . $payroll_status . '</td>
            </tr>
        </table>
    </div>

    <div class="attendance-bar">
        <h3>Attendance Summary</h3>
        <table class="att-grid">
            <tr>
                <td><div class="att-num">' . ($data['working_days'] ?? 0) . '</div><div class="att-label">Working Days</div></td>
                <td><div class="att-num" style="color:#16a34a">' . ($data['present_days'] ?? 0) . '</div><div class="att-label">Present</div></td>
                <td><div class="att-num" style="color:#0d9488">' . ($data['half_days'] ?? 0) . '</div><div class="att-label">Half Day</div></td>
                <td><div class="att-num" style="color:#d97706">' . ($data['late_days'] ?? 0) . '</div><div class="att-label">Late</div></td>
                <td><div class="att-num" style="color:#dc2626">' . ($data['absent_days'] ?? 0) . '</div><div class="att-label">Absent</div></td>
                <td><div class="att-num" style="color:#7c3aed">' . $ot_hours . 'h</div><div class="att-label">OT Hours</div></td>
                <td><div class="att-num" style="color:#2563eb">' . ($paid_leave) . '</div><div class="att-label">Paid Leave</div></td>
                <td><div class="att-num" style="color:#f97316">' . ($unpaid_leave) . '</div><div class="att-label">Unpaid Leave</div></td>
            </tr>
        </table>
    </div>

    <div class="earnings">
        <table class="table-section">
            <thead>
                <tr><th>EARNINGS</th><th class="amount">Amount</th></tr>
            </thead>
            <tbody>
                <tr><td>Basic Salary</td><td class="amount">$' . $basic . '</td></tr>
                <tr><td>Allowance</td><td class="amount">$' . $allowance . '</td></tr>
                <tr><td>Overtime Pay</td><td class="amount">$' . $ot . '</td></tr>
                <tr><td>Bonuses</td><td class="amount">$' . $bonus . '</td></tr>
                <tr class="total-row"><td>GROSS SALARY</td><td class="amount">$' . $gross . '</td></tr>
                <tr><td>Paid Leave (Full Pay)</td><td class="amount">$0.00</td></tr>
            </tbody>
        </table>
    </div>

    <div class="deductions">
        <table class="table-section">
            <thead>
                <tr><th>DEDUCTIONS</th><th class="amount">Amount</th></tr>
            </thead>
            <tbody>
                <tr><td>Absent Deduction</td><td class="amount">-$' . ($absent_ded_fmt !== '0.00' ? $absent_ded_fmt : '0.00') . '</td></tr>
                <tr><td>Half-Day Deduction</td><td class="amount">-$' . ($half_ded_fmt !== '0.00' ? $half_ded_fmt : '0.00') . '</td></tr>
                <tr><td>Late Deduction</td><td class="amount">-$' . $late_ded_fmt . '</td></tr>
                <tr><td>Unpaid Leave Ded.</td><td class="amount">-$' . $unpaid_leave_ded_fmt . '</td></tr>' . ($awol_ded !== '0.00' ? '
                <tr><td>Unpaid Absence Deduction</td><td class="amount">-$' . $awol_ded . '</td></tr>' : '') . '
                <tr><td>Other Deductions</td><td class="amount">-$' . $deduction . '</td></tr>
                <tr><td>Tax</td><td class="amount">-$' . $tax . '</td></tr>
                <tr class="total-row"><td>TOTAL DEDUCTIONS</td><td class="amount">-$' . $total_deductions . '</td></tr>
            </tbody>
        </table>
    </div>

    <div class="clearfix"></div>

    <div class="net-box">
        <div class="net-label">Net Salary</div>
        <div class="net-amount">$' . $net . '</div>
    </div>

    <div style="text-align:center; margin-bottom: 16px;">
        <span class="badge">&#10003; ' . strtoupper($payroll_status === 'Paid' ? 'PAYMENT CONFIRMED' : 'STATUS: ' . $payroll_status) . '</span>
    </div>

    <div class="signature">
        <table class="signature-table">
            <tr>
                <td><div class="line"></div>Employee&rsquo;s Signature</td>
                <td><div class="line"></div>Authorized Signature</td>
                <td><div class="line"></div>Date: ' . date('d/m/Y') . '</td>
            </tr>
        </table>
    </div>

    <div class="footer">
        This is a computer-generated document and does not require a signature.<br>
        For inquiries, contact HR Department at hr@hninakarinwe.com<br>
        &copy; ' . date('Y') . ' Hnin AKari nwe. All rights reserved.
    </div>

</div>
</body>
</html>';

    $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');

    $dompdf->render();

    return $dompdf->output();
}
