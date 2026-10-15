<?php
/**
 * MIPS Quality Measure 410 - Psoriasis: Clinical Response to Systemic Medications
 *
 * DENOMINATOR REPORT + MEASURE CODE TRACKING (2026 MIPS CQM spec, v10.0)
 *
 * Report Denominator Criteria:
 * - All patients, regardless of age
 * - Diagnosis of psoriasis vulgaris (ICD-10-CM L40.0) ONLY: patients with any other
 *   psoriasis diagnosis (L40.1 - L40.9) are excluded
 * - Qualifying encounter (CPT/HCPCS) during the performance period
 * - Encounter reason contains a systemic medication keyword (2026 reference list),
 *   OR G9764 is on the same fee sheet
 *
 * Measure Code Tracking:
 *   Denominator : G9764 (treated with a systemic medication for psoriasis vulgaris)
 *   Numerator   : G9649 (Met), G9765 (Exception), G9651 (Not Met)
 *   Status      : the most recent quality data code is used (per the spec)
 *   Reference   : earliest systemic medication encounter since the lookback start,
 *                 flagged if under 6 months before the last visit (see G9765)
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

// Lookback used to find when systemic treatment started (reference only)
$medLookbackMonths = 12;
$minTreatmentMonths = 6; // G9765 covers patients treated for less than 6 consecutive months
$lookbackStart = date('Y-m-d', strtotime($performancePeriodStart . ' -' . $medLookbackMonths . ' months'));
$lookbackStartTs = $lookbackStart . ' 00:00:00';

// Qualifying encounter CPT/HCPCS codes for 2026 (* = non-covered by Medicare, still counted)
$encounterCodes = array(
    '98000', '98001', '98002', '98003', '98004', '98005', '98006', '98007', '98008',
    '98009', '98010', '98011', '98012', '98013', '98014', '98015', '98016',
    '99202', '99203', '99204', '99205', '99212', '99213', '99214', '99215',
    '99242', '99243', '99244', '99245',
    '99341', '99342', '99344', '99345', '99347', '99348', '99349', '99350',
    '99424', '99426', 'G0438', 'G0439'
);

// Measure 410 quality data codes
$measureCodes = array(
    'G9764' => 'Denominator: patient treated with a systemic medication for psoriasis vulgaris',
    'G9649' => 'Met: psoriasis assessment tool documented meeting a benchmark (PGA <= 2, BSA < 3%, PASI < 3, or DLQI <= 5)',
    'G9765' => 'Exception: declined change in medication, alternatives unavailable, contraindications, or treated less than 6 consecutive months',
    'G9651' => 'Not Met: assessment tool documented not meeting any benchmark, or not documented',
);
$numeratorCodes = array(
    'G9649' => 'Met',
    'G9765' => 'Exception',
    'G9651' => 'Not Met',
);

// 2026 reference medications (generic and brand), matched case-insensitively.
// Generic names also match biosimilar suffixes (e.g. adalimumab-aaty).
$medicationTerms = array(
    // generic
    'abatacept', 'acitretin', 'adalimumab', 'apremilast', 'bimekizumab', 'brodalumab',
    'certolizumab', 'cyclosporine', 'deucravacitinib', 'etanercept', 'golimumab',
    'guselkumab', 'infliximab', 'ixekizumab', 'methotrexate', 'risankizumab',
    'secukinumab', 'spesolimab', 'tildrakizumab', 'tofacitinib', 'upadacitinib',
    'ustekinumab',
    // brand
    'abrilada', 'amjevita', 'avsola', 'bimzelx', 'cimzia', 'cosentyx', 'cyltezo',
    'enbrel', 'erelzi', 'gengraf', 'hadlima', 'hulio', 'humira', 'hyrimoz', 'idacio',
    'ilumya', 'inflectra', 'jylamvo', 'neoral', 'orencia', 'otezla', 'otrexup',
    'pyzchiva', 'rasuvo', 'reditrex', 'remicade', 'renflexis', 'rheumatrex', 'rinvoq',
    'sandimmune', 'selarsdi', 'siliq', 'simlandi', 'simponi', 'skyrizi', 'soriatane',
    'sotyktu', 'spevigo', 'stelara', 'taltz', 'tremfya', 'trexall', 'wezlana',
    'xatmep', 'xeljanz', 'yuflyma', 'yusimry'
);

// Psoriasis vulgaris and the other psoriasis codes that exclude a patient
$psoriasisVulgarisCondition = "(%s.code LIKE 'L40.0%%' OR %s.code = 'L400')";
$otherPsoriasisPrefixes = array('1', '2', '3', '4', '5', '8', '9');

/** Build "(LOWER(col) LIKE '%term%' OR ...)" for the medication keywords */
function medicationCondition($column, $terms)
{
    $parts = array();
    foreach ($terms as $term) {
        $parts[] = "LOWER($column) LIKE '%" . addslashes(strtolower($term)) . "%'";
    }
    return '(' . implode("\n        OR ", $parts) . ')';
}

/** Build the "other psoriasis diagnosis" condition for a billing alias */
function otherPsoriasisCondition($alias, $prefixes)
{
    $parts = array();
    foreach ($prefixes as $d) {
        $parts[] = "$alias.code LIKE 'L40.$d%' OR $alias.code LIKE 'L40$d%'";
    }
    return '(' . implode("\n            OR ", $parts) . ')';
}

$encounterCodesStr = "'" . implode("','", $encounterCodes) . "'";
$medicationConditionFe = medicationCondition('fe.reason', $medicationTerms);
$vulgarisDx = sprintf($psoriasisVulgarisCondition, 'b_dx', 'b_dx');
$vulgarisHist = sprintf($psoriasisVulgarisCondition, 'b_h', 'b_h');
$otherPsoriasisDx = otherPsoriasisCondition('b_other', $otherPsoriasisPrefixes);

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
    (SELECT GROUP_CONCAT(DISTINCT CONCAT(b_h.code, ' (', DATE_FORMAT(fe_h.date, '%Y-%m-%d'), ')')
                         ORDER BY fe_h.date DESC SEPARATOR ', ')
     FROM billing b_h
     INNER JOIN form_encounter fe_h ON b_h.encounter = fe_h.encounter AND b_h.pid = fe_h.pid
     WHERE b_h.pid = p.pid
     AND $vulgarisHist
     AND b_h.activity = 1
     AND fe_h.date BETWEEN '$lookbackStartTs' AND '$performancePeriodEndTs'
    ) AS psoriasis_diagnosis_history
FROM
    patient_data p
INNER JOIN
    form_encounter fe ON p.pid = fe.pid
INNER JOIN
    billing b ON fe.encounter = b.encounter AND fe.pid = b.pid
WHERE
    fe.date BETWEEN '$performancePeriodStartTs' AND '$performancePeriodEndTs'
    AND b.code IN ($encounterCodesStr)
    AND b.activity = 1
    AND p.deceased_date IS NULL
    AND EXISTS (
        SELECT 1
        FROM billing b_dx
        WHERE b_dx.pid = p.pid
        AND $vulgarisDx
        AND b_dx.activity = 1
    )
    AND NOT EXISTS (
        SELECT 1
        FROM billing b_other
        WHERE b_other.pid = p.pid
        AND b_other.activity = 1
        AND $otherPsoriasisDx
    )
    AND (
        $medicationConditionFe
        OR EXISTS (
            SELECT 1
            FROM billing b_g
            WHERE b_g.encounter = fe.encounter
            AND b_g.pid = fe.pid
            AND b_g.code = 'G9764'
            AND b_g.activity = 1
        )
    )
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

// One entry per patient; first/last qualifying 2026 systemic medication encounter
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
            'first_visit' => $encDate,
            'last_visit' => $encDate,
        );
    } else {
        if ($encDate < $patients[$pid]['first_visit']) {
            $patients[$pid]['first_visit'] = $encDate;
        }
        if ($encDate > $patients[$pid]['last_visit']) {
            $patients[$pid]['last_visit'] = $encDate;
        }
    }
}

// $codeHistory[pid][code] = entries, $encounterCodeHistory[encounter] = entries
$codeHistory = array();
$encounterCodeHistory = array();
// $medMentions[pid] = encounter dates (any visit type) with a medication keyword in the reason
$medMentions = array();

if (!empty($patients)) {
    $pidList = implode(',', array_map('intval', array_keys($patients)));
    $trackedCodesStr = "'" . implode("','", array_keys($measureCodes)) . "'";

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

    // Any encounter mentioning a systemic medication, to estimate when treatment started
    $mentionSql = "
        SELECT fe.pid, fe.date AS encounter_date
        FROM form_encounter fe
        WHERE fe.pid IN ($pidList)
        AND fe.date BETWEEN ? AND ?
        AND $medicationConditionFe
        ORDER BY fe.date ASC
    ";
    $mentionRes = sqlStatement($mentionSql, array($lookbackStartTs, $performancePeriodEndTs));
    while ($m = sqlFetchArray($mentionRes)) {
        $medMentions[$m['pid']][] = substr($m['encounter_date'], 0, 10);
    }
}

/** Format one fee sheet entry as "CODE YYYY-MM-DD" */
function formatCodeEntry($e)
{
    return $e['code'] . ' ' . $e['encounter_day'];
}

/**
 * Raw entries for a patient across a set of codes, oldest first,
 * limited to encounter dates between $from and $to (YYYY-MM-DD, inclusive).
 */
function getRawEntries($codeHistory, $pid, $codes, $from, $to)
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
        return strcmp($a['encounter_date'], $b['encounter_date']);
    });
    return $entries;
}

/**
 * Numerator: the most recent quality data code is used (per the 2026 spec).
 * If the most recent encounter has more than one different numerator code,
 * the patient is flagged so it can be corrected.
 */
function getNumeratorStatus($rawEntries, $numeratorCodes)
{
    if (empty($rawEntries)) {
        return 'Not Reported';
    }
    $last = end($rawEntries);
    $codesOnLast = array();
    foreach ($rawEntries as $e) {
        if ($e['encounter'] == $last['encounter']) {
            $codesOnLast[$e['code']] = true;
        }
    }
    if (count($codesOnLast) > 1) {
        return 'Conflicting codes entered';
    }
    return $numeratorCodes[$last['code']] . ' (' . $last['code'] . ')';
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
    if (strpos($status, 'Not Reported') === 0 || strpos($status, 'Conflicting') === 0) {
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
$numCounts = array(
    'Met (G9649)' => 0,
    'Exception (G9765)' => 0,
    'Not Met (G9651)' => 0,
    'Conflicting codes entered' => 0,
    'Not Reported' => 0,
);
$g9764NumCounts = $numCounts; // same counts, limited to patients with G9764 (the spec's denominator)
$missingG9764 = 0;
$underSixMonths = 0;

foreach ($patients as $pid => &$pt) {
    $pt['g9764'] = array_map('formatCodeEntry',
        getRawEntries($codeHistory, $pid, array('G9764'), $performancePeriodStart, $performancePeriodEnd));

    $numRaw = getRawEntries($codeHistory, $pid, array_keys($numeratorCodes), $performancePeriodStart, $performancePeriodEnd);
    $pt['num_codes'] = array_map('formatCodeEntry', $numRaw);
    $pt['num_status'] = getNumeratorStatus($numRaw, $numeratorCodes);

    // Earliest systemic medication evidence since the lookback start: keyword encounter or G9764
    $starts = isset($medMentions[$pid]) ? $medMentions[$pid] : array();
    $gAll = getRawEntries($codeHistory, $pid, array('G9764'), $lookbackStart, $performancePeriodEnd);
    if (!empty($gAll)) {
        $starts[] = $gAll[0]['encounter_day'];
    }
    $pt['med_since'] = empty($starts) ? $pt['first_visit'] : min($starts);
    $sixMonthMark = date('Y-m-d', strtotime($pt['med_since'] . ' +' . $minTreatmentMonths . ' months'));
    $pt['under_six_months'] = ($pt['last_visit'] < $sixMonthMark);

    $numCounts[$pt['num_status']]++;
    if (empty($pt['g9764'])) {
        $missingG9764++;
    } else {
        $g9764NumCounts[$pt['num_status']]++;
    }
    if ($pt['under_six_months']) {
        $underSixMonths++;
    }
}
unset($pt);

$uniquePatientCount = count($patients);

// Rates per the spec's sample calculation, using patients with G9764 as the denominator
$g9764Denominator = $uniquePatientCount - $missingG9764;
$completenessNumerator = $g9764NumCounts['Met (G9649)'] + $g9764NumCounts['Exception (G9765)'] + $g9764NumCounts['Not Met (G9651)'];
$dataCompleteness = $g9764Denominator > 0 ? round(100 * $completenessNumerator / $g9764Denominator, 2) . '%' : 'n/a';
$performanceDenominator = $completenessNumerator - $g9764NumCounts['Exception (G9765)'];
$performanceRate = $performanceDenominator > 0 ? round(100 * $g9764NumCounts['Met (G9649)'] / $performanceDenominator, 2) . '%' : 'n/a';

// ---------------------------------------------------------------------------
// CSV exports
//   ?export=csv           -> encounter detail (one row per encounter)
//   ?export=csv_tracking  -> measure code tracking (one row per patient)
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="MIPS_410_Denominator_Report_' . date('Y-m-d') . '.csv"');

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
        'Psoriasis Vulgaris Diagnosis History'
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
    header('Content-Disposition: attachment; filename="MIPS_410_Measure_Code_Tracking_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    fputcsv($output, array(
        'Patient ID',
        'Last Name',
        'First Name',
        'Date of Birth',
        'Last 2026 Systemic Med Visit',
        'Systemic Med Since',
        'Under 6 Months at Last Visit',
        'G9764 (Denominator)',
        'Numerator Codes (G9649/G9765/G9651)',
        'Numerator Status',
        'Psoriasis Vulgaris Diagnosis History'
    ));

    foreach ($patients as $pt) {
        fputcsv($output, array(
            $pt['pid'],
            $pt['last_name'],
            $pt['first_name'],
            $pt['date_of_birth'],
            $pt['last_visit'],
            $pt['med_since'],
            $pt['under_six_months'] ? 'Yes' : 'No',
            implode('; ', $pt['g9764']),
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
    <title>MIPS 410 Psoriasis Clinical Response to Systemic Medications</title>
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
        <p><strong>Measure Description:</strong> MIPS 410: Psoriasis Clinical Response to Systemic Meds</p>
        <p><strong>Report Date:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        <p><strong>Performance Period:</strong> <?php echo $performancePeriodStart; ?> to <?php echo $performancePeriodEnd; ?></p>
        <p><strong>Systemic Medication Lookback:</strong> <?php echo $lookbackStart; ?> onward (used for "Systemic Med Since")</p>
        <p>
            <a href="?export=csv" class="export-btn">Export Encounter Detail to CSV</a>
            <a href="?export=csv_tracking" class="export-btn">Export Measure Code Tracking to CSV</a>
        </p>
    </div>

    <div class="note">
        <strong>Note:</strong> Measure specifications only counts systemic medications prescribed by the provier being evaluated, so confirm the prescriber before entering G9764.<br>
        The "Systemic Med Since" column shows the earliest systemic medication encounter found since <?php echo $lookbackStart; ?>.
        Patients flagged under <?php echo $minTreatmentMonths; ?> months at their last visit may qualify for the exception (G9765)
        if they did not meet a benchmark; check the chart to confirm the treatment start and any gaps.
    </div>

    <div class="summary">
        Unique Patients in Denominator Report: <?php echo $uniquePatientCount; ?>
        &nbsp;|&nbsp; Encounter Rows: <?php echo count($results); ?>
        &nbsp;|&nbsp; Patients Missing G9764: <?php echo $missingG9764; ?>
        &nbsp;|&nbsp; Under <?php echo $minTreatmentMonths; ?> Months on Systemic Med: <?php echo $underSixMonths; ?>
    </div>

    <?php if ($uniquePatientCount > 0): ?>
        <table class="counts">
            <tr><th>Numerator Status</th><th>All Report Patients</th><th>Patients with G9764</th></tr>
            <?php foreach ($numCounts as $label => $count): ?>
            <tr>
                <td class="<?php echo statusClass($label); ?>"><?php echo htmlspecialchars($label); ?></td>
                <td><?php echo $count; ?></td>
                <td><?php echo $g9764NumCounts[$label]; ?></td>
            </tr>
            <?php endforeach; ?>
            <tr>
                <td><strong>Data Completeness</strong> (G9764 patients)</td>
                <td></td>
                <td><?php echo $dataCompleteness; ?></td>
            </tr>
            <tr>
                <td><strong>Performance Rate</strong> (G9764 patients)</td>
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
                <th>Last <?php echo substr($performancePeriodStart, 0, 4); ?> Systemic Med Visit</th>
                <th>Systemic Med Since</th>
                <th>G9764 (Denominator)</th>
                <th>Numerator Codes</th>
                <th>Numerator Status</th>
            </tr>

            <?php foreach ($patients as $pt): ?>
            <tr>
                <td><?php echo patientLink($pt['pid']); ?></td>
                <td><?php echo htmlspecialchars($pt['last_name'] . ', ' . $pt['first_name']); ?></td>
                <td><?php echo htmlspecialchars($pt['date_of_birth']); ?></td>
                <td><?php echo htmlspecialchars($pt['last_visit']); ?></td>
                <td class="codes">
                    <?php echo htmlspecialchars($pt['med_since']); ?>
                    <?php if ($pt['under_six_months']): ?>
                        <br><span class="flag">Under <?php echo $minTreatmentMonths; ?> months</span>
                    <?php endif; ?>
                </td>
                <td class="codes"><?php echo empty($pt['g9764']) ? '<span class="status-missing">Not entered</span>' : htmlList($pt['g9764']); ?></td>
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
                <th>Psoriasis Vulgaris Diagnosis History</th>
            </tr>

            <?php foreach ($results as $row): ?>
            <tr>
                <td><?php echo patientLink($row['pid']); ?></td>
                <td><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></td>
                <td><?php echo htmlspecialchars($row['sex'] ?? ''); ?></td>
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
            <li>All patients, regardless of age</li>
            <li>Diagnosis of psoriasis vulgaris (ICD-10-CM: L40.0) ONLY &mdash; patients with any other psoriasis diagnosis (L40.1&ndash;L40.9) are excluded</li>
            <li>Qualifying encounter during the performance period (<?php echo $performancePeriodStart; ?> to <?php echo $performancePeriodEnd; ?>):
                <?php echo implode(', ', $encounterCodes); ?></li>
            <li>Patient treated with a systemic medication for psoriasis vulgaris, prescribed by the provider being evaluated (G9764).
                This report finds candidates by encounter reason keyword (2026 reference medication list) or G9764 on the fee sheet.</li>
        </ol>

        <h3>Measure 410 Quality Data Codes Tracked:</h3>
        <ul>
            <?php foreach ($measureCodes as $code => $label): ?>
                <li><strong><?php echo $code; ?></strong> &mdash; <?php echo htmlspecialchars($label); ?> (performance period)</li>
            <?php endforeach; ?>
        </ul>

        <h3>Systemic Medications Searched:</h3>
        <p><?php echo htmlspecialchars(implode(', ', $medicationTerms)); ?></p>

        <p>Numerator status uses the most recent code entered, as the spec directs. If the most recent visit has more than one numerator code,
           the patient shows as "Conflicting codes entered" so it can be corrected.
           Data Completeness = (Met + Exception + Not Met) / patients with G9764. Performance Rate = Met / (Met + Not Met).</p>
    </div>
</body>
</html>