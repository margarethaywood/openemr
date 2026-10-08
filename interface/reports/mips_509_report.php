<?php
/**
 * MIPS Quality Measure 509 - Melanoma: Tracking and Evaluation of Recurrence
 *
 * DENOMINATOR REPORT + MEASURE CODE TRACKING
 * This report identifies patients who meet the denominator criteria and shows
 * when each Measure 509 quality data code was entered on an encounter fee sheet.
 *
 * Report Denominator Criteria:
 * - Diagnosis of melanoma (C43%) or melanoma in situ (D03%) between 2021-01-01 and 2026-12-31
 * - Any qualifying encounter during the performance period (2026-01-01 to 2026-12-31) with qualifying CPT codes
 *
 * Measure Code Tracking (2026 spec, v10.0):
 *   Denominator : excision CPTs (M1386 reference coding) used in place of M1386
 *   (M1386, M1387 and M1426 are not tracked in this report)
 *   Criteria 1  : M1388 (Met), M1392 (Exception), M1390 (Not Met)
 *   Criteria 2  : M1391 (Met - recurrence), M1392 (Exception), M1393 (Not Met - no recurrence)
 *   M1386 reference coding (excision CPTs): 11600-11606, 11620-11626, 11640-11646, 17311-17315
 */

// Include OpenEMR required files
require_once("../globals.php");
require_once("$srcdir/sql.inc.php");

// Performance period dates
$performancePeriodStart = '2026-01-01';
$performancePeriodEnd = '2026-12-31';
// form_encounter.date is a DATETIME; use end-of-day so 12/31 encounters are not dropped
$performancePeriodStartTs = $performancePeriodStart . ' 00:00:00';
$performancePeriodEndTs = $performancePeriodEnd . ' 23:59:59';

// Diagnosis date range (expanded to include historical diagnoses)
$diagnosisDateStart = '2021-01-01';
$diagnosisDateEnd = '2026-12-31';
$diagnosisDateStartTs = $diagnosisDateStart . ' 00:00:00';
$diagnosisDateEndTs = $diagnosisDateEnd . ' 23:59:59';

// Qualifying encounter CPT codes for 2026
$encounterCodes = array(
    '99202', '99203', '99204', '99205', '99211', '99212', '99213', '99214', '99215',
    '99242', '99243', '99244', '99245'
);

// Measure 509 quality data codes.
// window: 'lookback' = searched across the 5-year window, 'performance' = performance period only
$measureCodes = array(
    'M1388' => array('label' => 'Crit 1 Met: exam for recurrence performed', 'window' => 'performance'),
    'M1392' => array('label' => 'Crit 1 & 2 Exception: refusal / lost to follow-up', 'window' => 'performance'),
    'M1390' => array('label' => 'Crit 1 Not Met: no documented exam', 'window' => 'performance'),
    'M1391' => array('label' => 'Crit 2 Met (inverse): recurrent melanoma diagnosed', 'window' => 'performance'),
    'M1393' => array('label' => 'Crit 2 Not Met (inverse): no recurrence', 'window' => 'performance'),
);

// Reference coding for M1386 (excisional surgery) - searched across the 5-year window
$excisionCodes = array(
    '11600', '11601', '11602', '11603', '11604', '11606',
    '11620', '11621', '11622', '11623', '11624', '11626',
    '11640', '11641', '11642', '11643', '11644', '11646',
    '17311', '17312', '17313', '17314', '17315'
);

// Build the SQL query with encounter codes
$encounterCodesStr = "'" . implode("','", $encounterCodes) . "'";

$sql = "
SELECT DISTINCT
    p.pid,
    p.lname AS last_name,
    p.fname AS first_name,
    p.mname AS middle_name,
    p.sex,
    p.DOB AS date_of_birth,
    fe.encounter AS encounter_id,
    fe.date AS encounter_date,
    TIMESTAMPDIFF(YEAR, p.DOB, fe.date) AS age_at_encounter,
    fe.facility_id,
    b.code AS billing_code,
    CONCAT(u.lname, ', ', u.fname) AS provider_name,
    (SELECT GROUP_CONCAT(DISTINCT CONCAT(b_dx.code, ' (', DATE_FORMAT(fe_dx.date, '%Y-%m-%d'), ')') SEPARATOR ', ')
     FROM billing b_dx
     INNER JOIN form_encounter fe_dx ON b_dx.encounter = fe_dx.encounter
     WHERE b_dx.pid = p.pid
     AND (b_dx.code LIKE 'C43%' OR b_dx.code LIKE 'D03%' OR b_dx.code = 'Z85.820' OR b_dx.code LIKE 'Z85.820%')
     AND fe_dx.date BETWEEN '$diagnosisDateStartTs' AND '$diagnosisDateEndTs'
     AND b_dx.activity = 1
    ) AS melanoma_diagnosis_history
FROM
    patient_data p
INNER JOIN
    billing b_melanoma ON p.pid = b_melanoma.pid
INNER JOIN
    form_encounter fe_melanoma ON b_melanoma.encounter = fe_melanoma.encounter
    AND fe_melanoma.pid = b_melanoma.pid
LEFT JOIN
    form_encounter fe ON p.pid = fe.pid
    AND fe.date BETWEEN '$performancePeriodStartTs' AND '$performancePeriodEndTs'
LEFT JOIN
    billing b ON fe.encounter = b.encounter
    AND fe.pid = b.pid
    AND b.code IN ($encounterCodesStr)
    AND b.activity = 1
LEFT JOIN
    users u ON fe.provider_id = u.id
WHERE
    (b_melanoma.code LIKE 'C43%' OR b_melanoma.code LIKE 'D03%')
    AND fe_melanoma.date BETWEEN '$diagnosisDateStartTs' AND '$diagnosisDateEndTs'
    AND b_melanoma.activity = 1
    AND p.deceased_date IS NULL
ORDER BY
    p.lname, p.fname, fe.date DESC
";

// Execute query
$results = array();
$res = sqlStatement($sql);

while ($row = sqlFetchArray($res)) {
    $results[] = $row;
}

// ---------------------------------------------------------------------------
// Measure code tracking: pull every 509 quality data code / excision CPT that
// was entered on a fee sheet for the patients in this report.
// ---------------------------------------------------------------------------

// One entry per patient for the tracking table
$patients = array();
foreach ($results as $row) {
    if (!isset($patients[$row['pid']])) {
        $patients[$row['pid']] = array(
            'pid' => $row['pid'],
            'last_name' => $row['last_name'],
            'first_name' => $row['first_name'],
            'date_of_birth' => $row['date_of_birth'],
        );
    }
}

// $codeHistory[pid][code] = list of entries, $encounterCodeHistory[encounter] = list of entries
$codeHistory = array();
$encounterCodeHistory = array();

if (!empty($patients)) {
    $pidList = implode(',', array_map('intval', array_keys($patients)));
    $trackedCodes = array_merge(array_keys($measureCodes), $excisionCodes);
    $trackedCodesStr = "'" . implode("','", $trackedCodes) . "'";

    // billing.date = when the line was added to the fee sheet; billing.user = who added it
    $trackSql = "
        SELECT
            b.pid,
            b.code,
            b.code_type,
            b.encounter,
            b.date AS entered_date,
            fe.date AS encounter_date,
            CONCAT(eu.lname, ', ', eu.fname) AS entered_by
        FROM billing b
        INNER JOIN form_encounter fe ON fe.encounter = b.encounter AND fe.pid = b.pid
        LEFT JOIN users eu ON eu.id = b.user
        WHERE b.activity = 1
        AND b.pid IN ($pidList)
        AND b.code IN ($trackedCodesStr)
        AND fe.date BETWEEN ? AND ?
        ORDER BY fe.date ASC, b.date ASC
    ";

    $trackRes = sqlStatement($trackSql, array($diagnosisDateStartTs, $performancePeriodEndTs));

    while ($t = sqlFetchArray($trackRes)) {
        $code = strtoupper(trim($t['code']));
        // Quality data codes only count during the performance period (excision CPTs use the 5-year lookback)
        if (isset($measureCodes[$code]) && $measureCodes[$code]['window'] === 'performance') {
            if ($t['encounter_date'] < $performancePeriodStartTs) {
                continue;
            }
        }
        $t['code'] = $code;
        $codeHistory[$t['pid']][$code][] = $t;
        $encounterCodeHistory[$t['encounter']][] = $t;
    }
}

/**
 * Format one fee sheet entry: code and encounter date, e.g. "M1388 2026-02-11"
 */
function formatCodeEntry($e)
{
    return $e['code'] . ' ' . substr($e['encounter_date'], 0, 10);
}

/** Excision CPT entry: just the code and encounter date, e.g. "11602 (2026-03-14)" */
function formatExcisionEntry($e)
{
    return $e['code'] . ' (' . substr($e['encounter_date'], 0, 10) . ')';
}

/** All entries for a patient across a set of codes, formatted, oldest first */
function getEntries($codeHistory, $pid, $codes, $formatter = 'formatCodeEntry')
{
    $entries = array();
    foreach ($codes as $code) {
        if (!empty($codeHistory[$pid][$code])) {
            foreach ($codeHistory[$pid][$code] as $e) {
                $entries[] = $e;
            }
        }
    }
    usort($entries, function ($a, $b) {
        return strcmp($a['encounter_date'] . $a['entered_date'], $b['encounter_date'] . $b['entered_date']);
    });
    return array_map($formatter, $entries);
}

function hasCode($codeHistory, $pid, $code)
{
    return !empty($codeHistory[$pid][$code]);
}

/** Criteria 1: most advantageous code wins (Met > Exception > Not Met) */
function getCriteria1Status($codeHistory, $pid)
{
    if (hasCode($codeHistory, $pid, 'M1388')) {
        return 'Met (M1388)';
    }
    if (hasCode($codeHistory, $pid, 'M1392')) {
        return 'Exception (M1392)';
    }
    if (hasCode($codeHistory, $pid, 'M1390')) {
        return 'Not Met (M1390)';
    }
    return 'Not Reported';
}

/** Criteria 2 (inverse measure): recurrence result first, then exception */
function getCriteria2Status($codeHistory, $pid)
{
    if (hasCode($codeHistory, $pid, 'M1391')) {
        return 'Recurrence (M1391)';
    }
    if (hasCode($codeHistory, $pid, 'M1393')) {
        return 'No Recurrence (M1393)';
    }
    if (hasCode($codeHistory, $pid, 'M1392')) {
        return 'Exception (M1392)';
    }
    return 'Not Reported';
}

/** Codes entered on one specific encounter's fee sheet */
function getEncounterEntries($encounterCodeHistory, $encounter)
{
    if (empty($encounter) || empty($encounterCodeHistory[$encounter])) {
        return array();
    }
    $out = array();
    foreach ($encounterCodeHistory[$encounter] as $e) {
        $out[] = formatCodeEntry($e);
    }
    return $out;
}

/** Escape a list of entries for an HTML cell, one per line */
function htmlList($entries)
{
    if (empty($entries)) {
        return '<span class="none">&mdash;</span>';
    }
    return implode('<br>', array_map('htmlspecialchars', $entries));
}

// Build the patient-level tracking rows and summary counts
$crit1Counts = array('Met (M1388)' => 0, 'Exception (M1392)' => 0, 'Not Met (M1390)' => 0, 'Not Reported' => 0);
$crit2Counts = array('Recurrence (M1391)' => 0, 'No Recurrence (M1393)' => 0, 'Exception (M1392)' => 0, 'Not Reported' => 0);
$missingExcision = 0;

foreach ($patients as $pid => &$pt) {
    $pt['excision_cpts'] = getEntries($codeHistory, $pid, $excisionCodes, 'formatExcisionEntry');
    $pt['crit1_codes'] = getEntries($codeHistory, $pid, array('M1388', 'M1392', 'M1390'));
    $pt['crit2_codes'] = getEntries($codeHistory, $pid, array('M1391', 'M1392', 'M1393'));
    $pt['crit1_status'] = getCriteria1Status($codeHistory, $pid);
    $pt['crit2_status'] = getCriteria2Status($codeHistory, $pid);

    $crit1Counts[$pt['crit1_status']]++;
    $crit2Counts[$pt['crit2_status']]++;
    if (empty($pt['excision_cpts'])) {
        $missingExcision++;
    }
}
unset($pt);

$uniquePatientCount = count($patients);

// ---------------------------------------------------------------------------
// CSV exports
//   ?export=csv           -> encounter detail (one row per encounter)
//   ?export=csv_tracking  -> measure code tracking (one row per patient)
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="MIPS_509_Denominator_Report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    fputcsv($output, array(
        'Patient ID',
        'Last Name',
        'First Name',
        'Middle Name',
        'Sex',
        'Date of Birth',
        'Age at Encounter',
        'Encounter Date',
        'Encounter ID',
        'Billing Code',
        'Measure Codes on This Fee Sheet',
        'Criteria 1 Status',
        'Criteria 2 Status',
        'Melanoma Diagnosis History',
        'Provider Name',
        'Facility ID'
    ));

    foreach ($results as $row) {
        fputcsv($output, array(
            $row['pid'],
            $row['last_name'],
            $row['first_name'],
            $row['middle_name'],
            $row['sex'],
            $row['date_of_birth'],
            $row['age_at_encounter'],
            $row['encounter_date'],
            $row['encounter_id'],
            $row['billing_code'],
            implode('; ', getEncounterEntries($encounterCodeHistory, $row['encounter_id'])),
            $patients[$row['pid']]['crit1_status'],
            $patients[$row['pid']]['crit2_status'],
            $row['melanoma_diagnosis_history'],
            $row['provider_name'],
            $row['facility_id']
        ));
    }

    fclose($output);
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv_tracking') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="MIPS_509_Measure_Code_Tracking_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    fputcsv($output, array(
        'Patient ID',
        'Last Name',
        'First Name',
        'Date of Birth',
        'Excision CPTs (Encounter Date)',
        'Criteria 1 Codes (M1388/M1392/M1390)',
        'Criteria 1 Status',
        'Criteria 2 Codes (M1391/M1392/M1393)',
        'Criteria 2 Status'
    ));

    foreach ($patients as $pt) {
        fputcsv($output, array(
            $pt['pid'],
            $pt['last_name'],
            $pt['first_name'],
            $pt['date_of_birth'],
            implode('; ', $pt['excision_cpts']),
            implode('; ', $pt['crit1_codes']),
            $pt['crit1_status'],
            implode('; ', $pt['crit2_codes']),
            $pt['crit2_status']
        ));
    }

    fclose($output);
    exit;
}

// Generate HTML report output
?>
<!DOCTYPE html>
<html>
<head>
    <title>MIPS 509 Melanoma: Tracking and Evaluation of Recurrence - Denominator &amp; Measure Code Tracking</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        h2 { color: #333; margin-top: 40px; }
        .report-info { background: #f0f0f0; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .report-info p { margin: 5px 0; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th { background-color: #4CAF50; color: white; padding: 12px; text-align: left; }
        td { border: 1px solid #ddd; padding: 8px; vertical-align: top; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        tr:hover { background-color: #ddd; }
        .summary { margin-top: 20px; font-weight: bold; font-size: 16px; }
        .note { background: #fff3cd; padding: 10px; margin: 10px 0; border-left: 4px solid #ffc107; }
        .export-btn { display: inline-block; padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; margin-top: 10px; margin-right: 8px; }
        .export-btn:hover { background: #45a049; }
        .none { color: #999; }
        .status-met { color: #1b7f2a; font-weight: bold; }
        .status-exception { color: #8a6d00; font-weight: bold; }
        .status-notmet { color: #b02a2a; font-weight: bold; }
        .status-missing { color: #b02a2a; font-style: italic; }
        .flag { color: #b02a2a; font-weight: bold; }
        .counts td, .counts th { padding: 6px 12px; }
        .counts { width: auto; }
        .codes { font-size: 12px; white-space: nowrap; }
    </style>
</head>
<body>

    <div class="report-info">
        <p><strong>Measure Description:</strong> MIPS 509: Melanoma: Tracking and Evaluation of Recurrence</p>
        <p><strong>Report Date:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        <p><strong>Performance Period:</strong> <?php echo $performancePeriodStart; ?> to <?php echo $performancePeriodEnd; ?></p>
        <p><strong>Diagnosis / Excision Lookback Range:</strong> <?php echo $diagnosisDateStart; ?> to <?php echo $diagnosisDateEnd; ?></p>
        <p>
            <a href="?export=csv" class="export-btn">Export Encounter Detail to CSV</a>
            <a href="?export=csv_tracking" class="export-btn">Export Measure Code Tracking to CSV</a>
        </p>
    </div>

    <div class="note">
        <strong>Note:</strong> This report identifies ALL patients with a diagnosis of melanoma (C43%) or melanoma in situ (D03%)
        documented between <?php echo $diagnosisDateStart; ?> and <?php echo $diagnosisDateEnd; ?>. If the patient had a qualifying encounter during the performance period,
        those encounter details are shown. The diagnosis history column also displays any instances of Z85.820 (Personal history of malignant melanoma of skin) for reference.
        The Measure Code Tracking section shows each 509 quality data code and excision CPT entered on a fee sheet, with its encounter date.
        Excision CPTs are searched across the 5-year lookback;
        all other codes are limited to the performance period.
        Manual review is required to verify numerator compliance with recall system requirements.
    </div>

    <div class="summary">
        Unique Patients in Denominator Report: <?php echo $uniquePatientCount; ?>
        &nbsp;|&nbsp; Encounter Rows: <?php echo count($results); ?>
        &nbsp;|&nbsp; Patients Without Excision CPT: <?php echo $missingExcision; ?>
    </div>

    <?php if ($uniquePatientCount > 0): ?>
        <table class="counts">
            <tr><th>Criteria 1 (Exam for Recurrence)</th><th>Patients</th><th>Criteria 2 (Recurrence, Inverse)</th><th>Patients</th></tr>
            <?php
            $c1 = array_keys($crit1Counts);
            $c2 = array_keys($crit2Counts);
            for ($i = 0; $i < max(count($c1), count($c2)); $i++): ?>
            <tr>
                <td><?php echo htmlspecialchars($c1[$i] ?? ''); ?></td>
                <td><?php echo isset($c1[$i]) ? $crit1Counts[$c1[$i]] : ''; ?></td>
                <td><?php echo htmlspecialchars($c2[$i] ?? ''); ?></td>
                <td><?php echo isset($c2[$i]) ? $crit2Counts[$c2[$i]] : ''; ?></td>
            </tr>
            <?php endfor; ?>
        </table>
    <?php endif; ?>

    <?php
    /** CSS class for a status label */
    function statusClass($status)
    {
        if (strpos($status, 'Not Reported') === 0) {
            return 'status-missing';
        }
        if (strpos($status, 'Exception') === 0) {
            return 'status-exception';
        }
        if (strpos($status, 'Not Met') === 0 || strpos($status, 'Recurrence') === 0) {
            return 'status-notmet';
        }
        return 'status-met';
    }
    ?>

    <h2>Measure Code Tracking by Patient</h2>

    <?php if ($uniquePatientCount > 0): ?>
        <table>
            <tr>
                <th>Patient ID</th>
                <th>Patient Name</th>
                <th>DOB</th>
                <th>Excision CPTs</th>
                <th>Criteria 1 Codes</th>
                <th>Criteria 1 Status</th>
                <th>Criteria 2 Codes</th>
                <th>Criteria 2 Status</th>
            </tr>

            <?php foreach ($patients as $pt): ?>
            <tr>
                <td><?php echo htmlspecialchars($pt['pid']); ?></td>
                <td><?php echo htmlspecialchars($pt['last_name'] . ', ' . $pt['first_name']); ?></td>
                <td><?php echo htmlspecialchars($pt['date_of_birth']); ?></td>
                <td class="codes"><?php echo htmlList($pt['excision_cpts']); ?></td>
                <td class="codes"><?php echo htmlList($pt['crit1_codes']); ?></td>
                <td class="<?php echo statusClass($pt['crit1_status']); ?>"><?php echo htmlspecialchars($pt['crit1_status']); ?></td>
                <td class="codes"><?php echo htmlList($pt['crit2_codes']); ?></td>
                <td class="<?php echo statusClass($pt['crit2_status']); ?>"><?php echo htmlspecialchars($pt['crit2_status']); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No patients found meeting the denominator criteria for the specified performance period.</p>
    <?php endif; ?>

    <h2>Encounter Detail</h2>

    <?php if (count($results) > 0): ?>
        <table>
            <tr>
                <th>Patient ID</th>
                <th>Patient Name</th>
                <th>Sex</th>
                <th>DOB</th>
                <th>Age</th>
                <th>Encounter Date</th>
                <th>Encounter ID</th>
                <th>Billing Code</th>
                <th>Measure Codes on This Fee Sheet</th>
                <th>Melanoma Diagnosis History</th>
                <th>Provider</th>
                <th>Facility ID</th>
            </tr>

            <?php foreach ($results as $row): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['pid']); ?></td>
                <td><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></td>
                <td><?php echo htmlspecialchars($row['sex']); ?></td>
                <td><?php echo htmlspecialchars($row['date_of_birth']); ?></td>
                <td><?php echo htmlspecialchars($row['age_at_encounter']); ?></td>
                <td><?php echo htmlspecialchars($row['encounter_date']); ?></td>
                <td><?php echo htmlspecialchars($row['encounter_id']); ?></td>
                <td><?php echo htmlspecialchars($row['billing_code']); ?></td>
                <td class="codes"><?php echo htmlList(getEncounterEntries($encounterCodeHistory, $row['encounter_id'])); ?></td>
                <td><?php echo htmlspecialchars($row['melanoma_diagnosis_history']); ?></td>
                <td><?php echo htmlspecialchars($row['provider_name']); ?></td>
                <td><?php echo htmlspecialchars($row['facility_id']); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No patients found meeting the denominator criteria for the specified performance period.</p>
    <?php endif; ?>

    <div style="margin-top: 30px; padding: 15px; background: #e7f3ff; border-left: 4px solid #2196F3;">
        <h3>Denominator Criteria:</h3>
        <ol>
            <li>All patients with diagnosis of melanoma (ICD-10: C43%) or melanoma in situ (ICD-10: D03%) between <?php echo $diagnosisDateStart; ?> and <?php echo $diagnosisDateEnd; ?></li>
            <li>Qualifying encounters during performance period (<?php echo $performancePeriodStart; ?> to <?php echo $performancePeriodEnd; ?>) are shown if they exist</li>
            <li>Patients without qualifying encounters in the performance period are still included if they have melanoma diagnosis in the 5-year period</li>
        </ol>

        <h3>Measure 509 Quality Data Codes Tracked:</h3>
        <ul>
            <?php foreach ($measureCodes as $code => $info): ?>
                <li><strong><?php echo $code; ?></strong> &mdash; <?php echo htmlspecialchars($info['label']); ?>
                    (<?php echo $info['window'] === 'lookback' ? '5-year lookback' : 'performance period'; ?>)</li>
            <?php endforeach; ?>
            <li><strong>Excision CPTs</strong> (M1386 reference coding, used in place of M1386) &mdash; <?php echo implode(', ', $excisionCodes); ?> (5-year lookback)</li>
        </ul>
        <p>Criteria 1 status uses the most advantageous code if more than one is entered (M1388 &gt; M1392 &gt; M1390).
           Criteria 2 is an inverse measure; M1393 (no recurrence) is the better clinical outcome.</p>
    </div>
</body>
</html>