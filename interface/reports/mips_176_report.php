<?php
/**
 * MIPS Quality Measure 176 - Tuberculosis Screening Prior to First Course
 * of Biologic and/or Immune Response Modifier Therapy
 *
 * DENOMINATOR REPORT + MEASURE CODE TRACKING (2026 MIPS CQM spec, v10.0)
 *
 * Report Denominator Criteria:
 * - Patients aged >= 18 years on date of encounter
 * - Qualifying encounter (CPT/HCPCS) during the performance period
 * - Encounter reason contains a biologic/immune response modifier keyword (Table 1),
 *   OR G2182 is on the same fee sheet
 * - Psoriasis diagnosis (ICD-10 L40%) during the performance period. The practice
 *   prescribes biologics only for L40 patients, so this defines the eligible population.
 *
 * Measure Code Tracking:
 *   Denominator : G2182 (first-time biologic / immune response modifier therapy)
 *   Numerator   : M1003 (Met), M1004 (Exception), M1005 (Not Met)
 *   Reference   : TB test CPTs 86480, 86481, 86580 (12 months before therapy start)
 *   First course: flags any therapy keyword encounter or G2182 in the 15 months
 *                 before the patient's first 2026 therapy encounter
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

// Spec lookbacks
$priorTherapyLookbackMonths = 15; // no prior biologic within 15 months of the new start
$tbLookbackMonths = 12;           // TB test within 12 months before the new start
$lookbackStart = date('Y-m-d', strtotime($performancePeriodStart . ' -' . $priorTherapyLookbackMonths . ' months'));
$lookbackStartTs = $lookbackStart . ' 00:00:00';


// Qualifying encounter CPT/HCPCS codes for 2026
$encounterCodes = array(
    '98000', '98001', '98002', '98003', '98004', '98005', '98006', '98007', '98008',
    '98009', '98010', '98011', '98012', '98013', '98014', '98015', '98016',
    '99202', '99203', '99204', '99205', '99212', '99213', '99214', '99215',
    '99341', '99342', '99344', '99345', '99347', '99348', '99349', '99350',
    '99424', '99426', 'G0402', 'G0468'
);

// Measure 176 quality data codes
// window: 'performance' = performance period only
$measureCodes = array(
    'G2182' => array('label' => 'Denominator: first-time biologic / immune response modifier therapy', 'window' => 'performance'),
    'M1003' => array('label' => 'Met: TB screening performed and results interpreted within 12 months prior', 'window' => 'performance'),
    'M1004' => array('label' => 'Exception: medical reason for not screening / interpreting results', 'window' => 'performance'),
    'M1005' => array('label' => 'Not Met: TB screening not performed or results not interpreted', 'window' => 'performance'),
);

// TB test CPTs (reference only - not part of the spec's coding)
$tbTestCodes = array(
    '86480' => 'IGRA (QuantiFERON)',
    '86481' => 'IGRA (T-SPOT.TB)',
    '86580' => 'TST / PPD',
);

// Table 1 therapies (2026), generic and brand names, matched case-insensitively.
// Generic names also match their biosimilar suffixes (e.g. adalimumab-aaty).
$therapyTerms = array(
    // generic
    'abatacept', 'adalimumab', 'anakinra', 'baricitinib', 'brodalumab', 'canakinumab',
    'certolizumab', 'etanercept', 'golimumab', 'guselkumab', 'infliximab', 'ixekizumab',
    'risankizumab', 'rituximab', 'sarilumab', 'secukinumab', 'tildrakizumab', 'tocilizumab',
    'tofacitinib', 'upadacitinib', 'ustekinumab',
    // brand
    'orencia', 'humira', 'idacio', 'yuflyma', 'hyrimoz', 'cyltezo', 'abrilada', 'yusimry',
    'amjevita', 'hadlima', 'simlandi', 'hulio', 'kineret', 'olumiant', 'siliq', 'ilaris',
    'cimzia', 'enbrel', 'simponi', 'tremfya', 'remicade', 'renflexis', 'avsola', 'inflectra',
    'taltz', 'skyrizi', 'rituxan', 'kevzara', 'cosentyx', 'ilumya', 'actemra', 'tyenne',
    'tofidence', 'xeljanz', 'rinvoq', 'stelara', 'pyzchiva', 'selarsdi', 'wezlana',
    // general keywords
    'biologic', 'modifier'
);

/** Build "(LOWER(col) LIKE '%term%' OR ...)" for the therapy keywords */
function therapyCondition($column, $terms)
{
    $parts = array();
    foreach ($terms as $term) {
        $parts[] = "LOWER($column) LIKE '%" . addslashes(strtolower($term)) . "%'";
    }
    return '(' . implode("\n        OR ", $parts) . ')';
}

$encounterCodesStr = "'" . implode("','", $encounterCodes) . "'";
$therapyConditionFe = therapyCondition('fe.reason', $therapyTerms);

// Biologics are prescribed only for psoriasis (L40%), so require that diagnosis.
// This also screens out false keyword matches (e.g. "modifier") on unrelated visits.
$psoriasisFilter = "
    AND EXISTS (
        SELECT 1
        FROM billing b_dx
        INNER JOIN form_encounter fe_dx ON b_dx.encounter = fe_dx.encounter AND b_dx.pid = fe_dx.pid
        WHERE b_dx.pid = p.pid
        AND b_dx.code LIKE 'L40%'
        AND b_dx.activity = 1
        AND fe_dx.date BETWEEN '$performancePeriodStartTs' AND '$performancePeriodEndTs'
    )";

$sql = "
SELECT DISTINCT
    p.pid,
    p.lname AS last_name,
    p.fname AS first_name,
    p.mname AS middle_name,
    p.sex,
    p.DOB AS date_of_birth,
    TIMESTAMPDIFF(YEAR, p.DOB, fe.date) AS age_at_encounter,
    fe.encounter AS encounter_id,
    fe.date AS encounter_date,
    b.code AS billing_code,
    (SELECT GROUP_CONCAT(DISTINCT CONCAT(b_dx.code, ' (', DATE_FORMAT(fe_dx.date, '%Y-%m-%d'), ')') SEPARATOR ', ')
     FROM billing b_dx
     INNER JOIN form_encounter fe_dx ON b_dx.encounter = fe_dx.encounter AND b_dx.pid = fe_dx.pid
     WHERE b_dx.pid = p.pid
     AND b_dx.code LIKE 'L40%'
     AND b_dx.activity = 1
     AND fe_dx.date BETWEEN '$performancePeriodStartTs' AND '$performancePeriodEndTs'
    ) AS psoriasis_diagnosis_history
FROM
    patient_data p
INNER JOIN
    form_encounter fe ON p.pid = fe.pid
INNER JOIN
    billing b ON fe.encounter = b.encounter AND fe.pid = b.pid
WHERE
    fe.date BETWEEN '$performancePeriodStartTs' AND '$performancePeriodEndTs'
    AND TIMESTAMPDIFF(YEAR, p.DOB, fe.date) >= 18
    AND b.code IN ($encounterCodesStr)
    AND b.activity = 1
    AND p.deceased_date IS NULL
    AND (
        $therapyConditionFe
        OR EXISTS (
            SELECT 1
            FROM billing b_g
            WHERE b_g.encounter = fe.encounter
            AND b_g.pid = fe.pid
            AND b_g.code = 'G2182'
            AND b_g.activity = 1
        )
    )
    $psoriasisFilter
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
// Patient-level tracking
// ---------------------------------------------------------------------------

// One entry per patient; therapy_start = earliest qualifying 2026 therapy encounter
$patients = array();
foreach ($results as $row) {
    $pid = $row['pid'];
    $encDate = substr($row['encounter_date'], 0, 10);
    if (!isset($patients[$pid])) {
        $patients[$pid] = array(
            'pid' => $pid,
            'last_name' => $row['last_name'],
            'first_name' => $row['first_name'],
            'date_of_birth' => $row['date_of_birth'],
            'psoriasis_diagnosis_history' => $row['psoriasis_diagnosis_history'],
            'therapy_start' => $encDate,
        );
    } elseif ($encDate < $patients[$pid]['therapy_start']) {
        $patients[$pid]['therapy_start'] = $encDate;
    }
}

// $codeHistory[pid][code] = entries, $encounterCodeHistory[encounter] = entries
$codeHistory = array();
$encounterCodeHistory = array();
// $therapyMentions[pid] = list of encounter dates with a therapy keyword in the reason
$therapyMentions = array();

if (!empty($patients)) {
    $pidList = implode(',', array_map('intval', array_keys($patients)));
    $trackedCodes = array_merge(array_keys($measureCodes), array_keys($tbTestCodes));
    $trackedCodesStr = "'" . implode("','", $trackedCodes) . "'";

    $trackSql = "
        SELECT
            b.pid,
            b.code,
            b.encounter,
            fe.date AS encounter_date
        FROM billing b
        INNER JOIN form_encounter fe ON fe.encounter = b.encounter AND fe.pid = b.pid
        WHERE b.activity = 1
        AND b.pid IN ($pidList)
        AND b.code IN ($trackedCodesStr)
        AND fe.date BETWEEN ? AND ?
        ORDER BY fe.date ASC
    ";
    $trackRes = sqlStatement($trackSql, array($lookbackStartTs, $performancePeriodEndTs));

    while ($t = sqlFetchArray($trackRes)) {
        $t['code'] = strtoupper(trim($t['code']));
        $t['encounter_day'] = substr($t['encounter_date'], 0, 10);
        $codeHistory[$t['pid']][$t['code']][] = $t;
        if ($t['encounter_date'] >= $performancePeriodStartTs) {
            $encounterCodeHistory[$t['encounter']][] = $t;
        }
    }

    // Any encounter (not only E/M) mentioning a therapy, for the 15-month first-course check
    $mentionSql = "
        SELECT fe.pid, fe.encounter, fe.date AS encounter_date
        FROM form_encounter fe
        WHERE fe.pid IN ($pidList)
        AND fe.date BETWEEN ? AND ?
        AND $therapyConditionFe
        ORDER BY fe.date ASC
    ";
    $mentionRes = sqlStatement($mentionSql, array($lookbackStartTs, $performancePeriodEndTs));
    while ($m = sqlFetchArray($mentionRes)) {
        $therapyMentions[$m['pid']][] = substr($m['encounter_date'], 0, 10);
    }
}

/** Format one fee sheet entry as "CODE YYYY-MM-DD" */
function formatCodeEntry($e)
{
    return $e['code'] . ' ' . $e['encounter_day'];
}

/**
 * Entries for a patient across a set of codes, oldest first,
 * limited to encounter dates between $from and $to (YYYY-MM-DD, inclusive).
 */
function getEntries($codeHistory, $pid, $codes, $from, $to)
{
    $entries = array();
    foreach ($codes as $code) {
        if (!empty($codeHistory[$pid][$code])) {
            foreach ($codeHistory[$pid][$code] as $e) {
                if ($e['encounter_day'] >= $from && $e['encounter_day'] <= $to) {
                    $entries[] = $e;
                }
            }
        }
    }
    usort($entries, function ($a, $b) {
        return strcmp($a['encounter_day'], $b['encounter_day']);
    });
    return array_map('formatCodeEntry', $entries);
}

/** Numerator: most advantageous code wins (Met > Exception > Not Met) */
function getNumeratorStatus($entries)
{
    $joined = ' ' . implode(' ', $entries);
    if (strpos($joined, ' M1003') !== false) {
        return 'Met (M1003)';
    }
    if (strpos($joined, ' M1004') !== false) {
        return 'Exception (M1004)';
    }
    if (strpos($joined, ' M1005') !== false) {
        return 'Not Met (M1005)';
    }
    return 'Not Reported';
}

/** Codes entered on one specific encounter's fee sheet */
function getEncounterEntries($encounterCodeHistory, $encounter)
{
    if (empty($encounter) || empty($encounterCodeHistory[$encounter])) {
        return array();
    }
    return array_map('formatCodeEntry', $encounterCodeHistory[$encounter]);
}

/** Escape a list of entries for an HTML cell, one per line */
function htmlList($entries)
{
    if (empty($entries)) {
        return '<span class="none">&mdash;</span>';
    }
    return implode('<br>', array_map('htmlspecialchars', $entries));
}

/** CSS class for a status label */
function statusClass($status)
{
    if (strpos($status, 'Not Reported') === 0) {
        return 'status-missing';
    }
    if (strpos($status, 'Exception') === 0) {
        return 'status-exception';
    }
    if (strpos($status, 'Not Met') === 0) {
        return 'status-notmet';
    }
    return 'status-met';
}

/** Patient ID cell that opens the patient's dashboard */
function patientLink($pid)
{
    $pid = (int)$pid;
    $href = $GLOBALS['webroot'] . '/interface/patient_file/summary/demographics.php?set_pid=' . $pid;
    return '<a class="pid-link" href="' . htmlspecialchars($href) . '" target="_blank" '
        . 'onclick="return goPid(' . $pid . ');" title="Open patient dashboard">' . $pid . '</a>';
}

// Build the patient-level tracking rows and summary counts
$numCounts = array('Met (M1003)' => 0, 'Exception (M1004)' => 0, 'Not Met (M1005)' => 0, 'Not Reported' => 0);
$g2182NumCounts = $numCounts; // same counts, limited to patients with G2182 (the spec's denominator)
$missingG2182 = 0;
$possiblePriorTherapy = 0;

foreach ($patients as $pid => &$pt) {
    // First-course start: earliest of the first 2026 therapy encounter and the first 2026 G2182
    $g2182Period = getEntries($codeHistory, $pid, array('G2182'), $performancePeriodStart, $performancePeriodEnd);
    if (!empty($g2182Period)) {
        $firstG = substr($g2182Period[0], 6, 10);
        if ($firstG < $pt['therapy_start']) {
            $pt['therapy_start'] = $firstG;
        }
    }
    $start = $pt['therapy_start'];
    $dayBefore = date('Y-m-d', strtotime($start . ' -1 day'));
    $priorFrom = date('Y-m-d', strtotime($start . ' -' . $priorTherapyLookbackMonths . ' months'));
    $tbFrom = date('Y-m-d', strtotime($start . ' -' . $tbLookbackMonths . ' months'));

    $pt['g2182'] = $g2182Period;

    // Prior therapy within 15 months before the start: keyword encounters or an earlier G2182
    $prior = array();
    if (!empty($therapyMentions[$pid])) {
        foreach (array_unique($therapyMentions[$pid]) as $d) {
            if ($d >= $priorFrom && $d <= $dayBefore) {
                $prior[] = 'Reason ' . $d;
            }
        }
    }
    foreach (getEntries($codeHistory, $pid, array('G2182'), $priorFrom, $dayBefore) as $e) {
        $prior[] = $e;
    }
    $pt['prior_therapy'] = $prior;

    $pt['tb_tests'] = getEntries($codeHistory, $pid, array_keys($tbTestCodes), $tbFrom, $performancePeriodEnd);
    $pt['num_codes'] = getEntries($codeHistory, $pid, array('M1003', 'M1004', 'M1005'), $performancePeriodStart, $performancePeriodEnd);
    $pt['num_status'] = getNumeratorStatus($pt['num_codes']);

    $numCounts[$pt['num_status']]++;
    if (empty($pt['g2182'])) {
        $missingG2182++;
    } else {
        $g2182NumCounts[$pt['num_status']]++;
    }
    if (!empty($prior)) {
        $possiblePriorTherapy++;
    }
}
unset($pt);

$uniquePatientCount = count($patients);

// Rates per the spec's sample calculation, using patients with G2182 as the denominator
$g2182Denominator = $uniquePatientCount - $missingG2182;
$completenessNumerator = $g2182NumCounts['Met (M1003)'] + $g2182NumCounts['Exception (M1004)'] + $g2182NumCounts['Not Met (M1005)'];
$dataCompleteness = $g2182Denominator > 0 ? round(100 * $completenessNumerator / $g2182Denominator, 2) . '%' : 'n/a';
$performanceDenominator = $completenessNumerator - $g2182NumCounts['Exception (M1004)'];
$performanceRate = $performanceDenominator > 0 ? round(100 * $g2182NumCounts['Met (M1003)'] / $performanceDenominator, 2) . '%' : 'n/a';

// ---------------------------------------------------------------------------
// CSV exports
//   ?export=csv           -> encounter detail (one row per encounter)
//   ?export=csv_tracking  -> measure code tracking (one row per patient)
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="MIPS_176_Denominator_Report_' . date('Y-m-d') . '.csv"');

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
        'Numerator Status',
        'Psoriasis Diagnosis History'
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
            $patients[$row['pid']]['num_status'],
            $row['psoriasis_diagnosis_history']
        ));
    }

    fclose($output);
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv_tracking') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="MIPS_176_Measure_Code_Tracking_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    fputcsv($output, array(
        'Patient ID',
        'Last Name',
        'First Name',
        'Date of Birth',
        'First 2026 Therapy Encounter',
        'Prior Therapy (15 mo before start)',
        'G2182 (Denominator)',
        'TB Test CPTs (12 mo before start onward)',
        'Numerator Codes (M1003/M1004/M1005)',
        'Numerator Status',
        'Psoriasis Diagnosis History'
    ));

    foreach ($patients as $pt) {
        fputcsv($output, array(
            $pt['pid'],
            $pt['last_name'],
            $pt['first_name'],
            $pt['date_of_birth'],
            $pt['therapy_start'],
            implode('; ', $pt['prior_therapy']),
            implode('; ', $pt['g2182']),
            implode('; ', $pt['tb_tests']),
            implode('; ', $pt['num_codes']),
            $pt['num_status'],
            $pt['psoriasis_diagnosis_history']
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
    <title>MIPS 176 TB Screening Prior to Biologic Therapy</title>
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
        .pid-link { color: #1a5fb4; font-weight: bold; text-decoration: underline; cursor: pointer; }
    </style>
    <script>
        // Open the patient's dashboard. Uses the OpenEMR tab UI when this report runs inside it,
        // otherwise falls back to the legacy frame layout or a new browser tab.
        function goPid(pid) {
            var url = <?php echo json_encode($GLOBALS['webroot'] . '/interface/patient_file/summary/demographics.php?set_pid='); ?> + encodeURIComponent(pid);
            try {
                if (typeof top.restoreSession === 'function') {
                    top.restoreSession();
                }
                if (typeof top.navigateTab === 'function') {
                    top.navigateTab(url, 'pat', function () {
                        if (typeof top.activateTabByName === 'function') {
                            top.activateTabByName('pat', true);
                        }
                    });
                    return false;
                }
                if (top.RTop) {
                    top.RTop.location = url;
                    return false;
                }
            } catch (e) {
                // fall through to new tab
            }
            window.open(url, '_blank');
            return false;
        }
    </script>
</head>
<body>

    <div class="report-info">
        <p><strong>Measure Description:</strong> MIPS 176: Tuberculosis Screening Prior to First Course of Biologic and/or Immune Response
Modifier Therapy</p>
        <p><strong>Report Date:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        <p><strong>Performance Period:</strong> <?php echo $performancePeriodStart; ?> to <?php echo $performancePeriodEnd; ?></p>
        <p><strong>Prior Therapy / TB Test Lookback:</strong> <?php echo $priorTherapyLookbackMonths; ?> months / <?php echo $tbLookbackMonths; ?> months before each patient's first <?php echo substr($performancePeriodStart, 0, 4); ?> therapy encounter</p>
        <p>
            <a href="?export=csv" class="export-btn">Export Encounter Detail to CSV</a>
            <a href="?export=csv_tracking" class="export-btn">Export Measure Code Tracking to CSV</a>
        </p>
    </div>

    <div class="note">
        <strong>Note:</strong> This report identifies patients aged 18+ with a qualifying encounter during the performance period
        whose encounter reason contains a biologic/immune response modifier keyword or whose fee sheet has G2182, and who have a psoriasis diagnosis (L40%) during the performance period.
        The spec counts a patient only if this is a <em>first</em> course: no biologic/immune response modifier in the 15 months before the new start.
        The Prior Therapy column flags patients with a therapy keyword encounter or G2182 in that window; review these before entering G2182.
        TB test CPTs are shown for reference only. IGRA and TST results usually live in lab results rather than on the fee sheet, so a blank column does not mean no test was done.
    </div>

    <div class="summary">
        Unique Patients in Denominator Report: <?php echo $uniquePatientCount; ?>
        &nbsp;|&nbsp; Encounter Rows: <?php echo count($results); ?>
        &nbsp;|&nbsp; Patients Missing G2182: <?php echo $missingG2182; ?>
        &nbsp;|&nbsp; Possible Prior Therapy (15 mo): <?php echo $possiblePriorTherapy; ?>
    </div>

    <?php if ($uniquePatientCount > 0): ?>
        <table class="counts">
            <tr><th>Numerator Status</th><th>All Report Patients</th><th>Patients with G2182</th></tr>
            <?php foreach ($numCounts as $label => $count): ?>
            <tr>
                <td class="<?php echo statusClass($label); ?>"><?php echo htmlspecialchars($label); ?></td>
                <td><?php echo $count; ?></td>
                <td><?php echo $g2182NumCounts[$label]; ?></td>
            </tr>
            <?php endforeach; ?>
            <tr>
                <td><strong>Data Completeness</strong> (G2182 patients)</td>
                <td></td>
                <td><?php echo $dataCompleteness; ?></td>
            </tr>
            <tr>
                <td><strong>Performance Rate</strong> (G2182 patients)</td>
                <td></td>
                <td><?php echo $performanceRate; ?></td>
            </tr>
        </table>
    <?php endif; ?>

    <h2>Measure Code Tracking by Patient</h2>

    <?php if ($uniquePatientCount > 0): ?>
        <table>
            <tr>
                <th>Patient ID</th>
                <th>Patient Name</th>
                <th>DOB</th>
                <th>First <?php echo substr($performancePeriodStart, 0, 4); ?> Therapy Encounter</th>
                <th>Prior Therapy (15 mo)</th>
                <th>G2182 (Denominator)</th>
                <th>TB Test CPTs</th>
                <th>Numerator Codes</th>
                <th>Numerator Status</th>
            </tr>

            <?php foreach ($patients as $pt): ?>
            <tr>
                <td><?php echo patientLink($pt['pid']); ?></td>
                <td><?php echo htmlspecialchars($pt['last_name'] . ', ' . $pt['first_name']); ?></td>
                <td><?php echo htmlspecialchars($pt['date_of_birth']); ?></td>
                <td><?php echo htmlspecialchars($pt['therapy_start']); ?></td>
                <td class="codes">
                    <?php if (empty($pt['prior_therapy'])): ?>
                        <span class="none">&mdash;</span>
                    <?php else: ?>
                        <span class="flag"><?php echo htmlList($pt['prior_therapy']); ?></span>
                    <?php endif; ?>
                </td>
                <td class="codes"><?php echo empty($pt['g2182']) ? '<span class="status-missing">Not entered</span>' : htmlList($pt['g2182']); ?></td>
                <td class="codes"><?php echo htmlList($pt['tb_tests']); ?></td>
                <td class="codes"><?php echo htmlList($pt['num_codes']); ?></td>
                <td class="<?php echo statusClass($pt['num_status']); ?>"><?php echo htmlspecialchars($pt['num_status']); ?></td>
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
                <th>Psoriasis Diagnosis History</th>
            </tr>

            <?php foreach ($results as $row): ?>
            <tr>
                <td><?php echo patientLink($row['pid']); ?></td>
                <td><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></td>
                <td><?php echo htmlspecialchars($row['sex']); ?></td>
                <td><?php echo htmlspecialchars($row['date_of_birth']); ?></td>
                <td><?php echo htmlspecialchars($row['age_at_encounter']); ?></td>
                <td><?php echo htmlspecialchars($row['encounter_date']); ?></td>
                <td><?php echo htmlspecialchars($row['encounter_id']); ?></td>
                <td><?php echo htmlspecialchars($row['billing_code']); ?></td>
                <td class="codes"><?php echo htmlList(getEncounterEntries($encounterCodeHistory, $row['encounter_id'])); ?></td>
                <td><?php echo htmlspecialchars($row['psoriasis_diagnosis_history'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No patients found meeting the denominator criteria for the specified performance period.</p>
    <?php endif; ?>

    <div style="margin-top: 30px; padding: 15px; background: #e7f3ff; border-left: 4px solid #2196F3;">
        <h3>Denominator Criteria:</h3>
        <ol>
            <li>Patients aged &gt;= 18 years on date of encounter</li>
            <li>Qualifying encounter during the performance period (<?php echo $performancePeriodStart; ?> to <?php echo $performancePeriodEnd; ?>):
                <?php echo implode(', ', $encounterCodes); ?></li>
            <li>Patient receiving first-time biologic and/or immune response modifier therapy (G2182): prescribed during the performance period
                with no such prescription in the <?php echo $priorTherapyLookbackMonths; ?> months before the new start. This report finds candidates by
                encounter reason keyword (Table 1 medications) or G2182 on the fee sheet.</li>
            <li>Diagnosis of psoriasis (ICD-10: L40%) during the performance period (biologics are prescribed only for L40 patients)</li>
        </ol>

        <h3>Measure 176 Quality Data Codes Tracked:</h3>
        <ul>
            <?php foreach ($measureCodes as $code => $info): ?>
                <li><strong><?php echo $code; ?></strong> &mdash; <?php echo htmlspecialchars($info['label']); ?> (performance period)</li>
            <?php endforeach; ?>
            <?php foreach ($tbTestCodes as $code => $label): ?>
                <li><strong><?php echo $code; ?></strong> &mdash; <?php echo htmlspecialchars($label); ?> (reference only; <?php echo $tbLookbackMonths; ?> months before therapy start onward)</li>
            <?php endforeach; ?>
        </ul>
        <p>Numerator status uses the most advantageous code if more than one is entered (M1003 &gt; M1004 &gt; M1005).
           Data Completeness = (Met + Exception + Not Met) / patients with G2182. Performance Rate = Met / (Met + Not Met).</p>
    </div>
</body>
</html>