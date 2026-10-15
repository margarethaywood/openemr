<?php
/**
 * MIPS Quality Measure 178 - Rheumatoid Arthritis (RA): Functional Status Assessment
 * 2026 Specifications (MIPS CQM, Version 10.0, December 2025)
 *
 * DENOMINATOR REPORT
 * This report identifies patients who meet the denominator criteria and checks for functional status assessments.
 *
 * Denominator Criteria (2026):
 * - Age >= 18 years on date of encounter
 * - Diagnosis of rheumatoid arthritis (RA) using ICD-10-CM codes
 * - At least one qualifying encounter with an RA diagnosis during the performance period, AND
 *   an additional qualifying encounter with an RA diagnosis (performance period or prior performance
 *   period) at least 90 days before or after that performance-period encounter
 * - Qualifying CPT/HCPCS encounter codes
 * - Telehealth encounters ARE eligible (measure is telehealth eligible)
 * - Performance period: 2026-01-01 to 2026-12-31
 * - Prior period for 90-day rule: 2025-01-01 to 2025-12-31
 *
 * Numerator (2026):
 * - Functional status assessment performed at least once DURING THE PERFORMANCE PERIOD using an
 *   ACR-preferred tool (PROMIS PF10a, HAQ-II, or MD-HAQ). Prior-period assessments do not count.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Include OpenEMR required files
require_once("../globals.php");
require_once("$srcdir/sql.inc.php");

// Reporting year and period dates
$reportingYear = 2026;
$priorYear = $reportingYear - 1;
$performancePeriodStart = "$reportingYear-01-01";
$performancePeriodEnd = "$reportingYear-12-31";
$priorPeriodStart = "$priorYear-01-01";
$priorPeriodEnd = "$priorYear-12-31";

// Datetime bounds so encounters/assessments recorded later in the day on Dec 31 are included
$rangeStart = "$priorPeriodStart 00:00:00";
$rangeEnd = "$performancePeriodEnd 23:59:59";
$perfRangeStart = "$performancePeriodStart 00:00:00";

// RA diagnosis codes (ICD-10-CM) - 2026 list
$raDxCodes = array(
    'M05.A', 'M05.00', 'M05.011', 'M05.012', 'M05.019', 'M05.021', 'M05.022', 'M05.029', 'M05.031', 'M05.032', 'M05.039',
    'M05.041', 'M05.042', 'M05.049', 'M05.051', 'M05.052', 'M05.059', 'M05.061', 'M05.062', 'M05.069', 'M05.071',
    'M05.072', 'M05.079', 'M05.09', 'M05.10', 'M05.111', 'M05.112', 'M05.119', 'M05.121', 'M05.122', 'M05.129',
    'M05.131', 'M05.132', 'M05.139', 'M05.141', 'M05.142', 'M05.149', 'M05.151', 'M05.152', 'M05.159', 'M05.161',
    'M05.162', 'M05.169', 'M05.171', 'M05.172', 'M05.179', 'M05.19', 'M05.20', 'M05.211', 'M05.212', 'M05.219',
    'M05.221', 'M05.222', 'M05.229', 'M05.231', 'M05.232', 'M05.239', 'M05.241', 'M05.242', 'M05.249', 'M05.251',
    'M05.252', 'M05.259', 'M05.261', 'M05.262', 'M05.269', 'M05.271', 'M05.272', 'M05.279', 'M05.29', 'M05.30',
    'M05.311', 'M05.312', 'M05.319', 'M05.321', 'M05.322', 'M05.329', 'M05.331', 'M05.332', 'M05.339', 'M05.341',
    'M05.342', 'M05.349', 'M05.351', 'M05.352', 'M05.359', 'M05.361', 'M05.362', 'M05.369', 'M05.371', 'M05.372',
    'M05.379', 'M05.39', 'M05.40', 'M05.411', 'M05.412', 'M05.419', 'M05.421', 'M05.422', 'M05.429', 'M05.431',
    'M05.432', 'M05.439', 'M05.441', 'M05.442', 'M05.449', 'M05.451', 'M05.452', 'M05.459', 'M05.461', 'M05.462',
    'M05.469', 'M05.471', 'M05.472', 'M05.479', 'M05.49', 'M05.50', 'M05.511', 'M05.512', 'M05.519', 'M05.521',
    'M05.522', 'M05.529', 'M05.531', 'M05.532', 'M05.539', 'M05.541', 'M05.542', 'M05.549', 'M05.551', 'M05.552',
    'M05.559', 'M05.561', 'M05.562', 'M05.569', 'M05.571', 'M05.572', 'M05.579', 'M05.59', 'M05.60', 'M05.611',
    'M05.612', 'M05.619', 'M05.621', 'M05.622', 'M05.629', 'M05.631', 'M05.632', 'M05.639', 'M05.641', 'M05.642',
    'M05.649', 'M05.651', 'M05.652', 'M05.659', 'M05.661', 'M05.662', 'M05.669', 'M05.671', 'M05.672', 'M05.679',
    'M05.69', 'M05.7A', 'M05.70', 'M05.711', 'M05.712', 'M05.719', 'M05.721', 'M05.722', 'M05.729', 'M05.731',
    'M05.732', 'M05.739', 'M05.741', 'M05.742', 'M05.749', 'M05.751', 'M05.752', 'M05.759', 'M05.761', 'M05.762',
    'M05.769', 'M05.771', 'M05.772', 'M05.779', 'M05.79', 'M05.8A', 'M05.80', 'M05.811', 'M05.812', 'M05.819',
    'M05.821', 'M05.822', 'M05.829', 'M05.831', 'M05.832', 'M05.839', 'M05.841', 'M05.842', 'M05.849', 'M05.851',
    'M05.852', 'M05.859', 'M05.861', 'M05.862', 'M05.869', 'M05.871', 'M05.872', 'M05.879', 'M05.89', 'M05.9',
    'M06.0A', 'M06.00', 'M06.011', 'M06.012', 'M06.019', 'M06.021', 'M06.022', 'M06.029', 'M06.031', 'M06.032',
    'M06.039', 'M06.041', 'M06.042', 'M06.049', 'M06.051', 'M06.052', 'M06.059', 'M06.061', 'M06.062', 'M06.069',
    'M06.071', 'M06.072', 'M06.079', 'M06.08', 'M06.09', 'M06.20', 'M06.211', 'M06.212', 'M06.219', 'M06.221',
    'M06.222', 'M06.229', 'M06.231', 'M06.232', 'M06.239', 'M06.241', 'M06.242', 'M06.249', 'M06.251', 'M06.252',
    'M06.259', 'M06.261', 'M06.262', 'M06.269', 'M06.271', 'M06.272', 'M06.279', 'M06.28', 'M06.29', 'M06.30',
    'M06.311', 'M06.312', 'M06.319', 'M06.321', 'M06.322', 'M06.329', 'M06.331', 'M06.332', 'M06.339', 'M06.341',
    'M06.342', 'M06.349', 'M06.351', 'M06.352', 'M06.359', 'M06.361', 'M06.362', 'M06.369', 'M06.371', 'M06.372',
    'M06.379', 'M06.38', 'M06.39', 'M06.8A', 'M06.80', 'M06.811', 'M06.812', 'M06.819', 'M06.821', 'M06.822',
    'M06.829', 'M06.831', 'M06.832', 'M06.839', 'M06.841', 'M06.842', 'M06.849', 'M06.851', 'M06.852', 'M06.859',
    'M06.861', 'M06.862', 'M06.869', 'M06.871', 'M06.872', 'M06.879', 'M06.88', 'M06.89', 'M06.9'
);

// Qualifying encounter CPT/HCPCS codes - 2026 list (98000-98016 are telehealth E/M codes)
$encounterCodes = array(
    '98000', '98001', '98002', '98003', '98004', '98005', '98006', '98007', '98008',
    '98009', '98010', '98011', '98012', '98013', '98014', '98015', '98016',
    '99202', '99203', '99204', '99205', '99212', '99213', '99214', '99215',
    '99341', '99342', '99344', '99345', '99347', '99348', '99349', '99350',
    '99424', '99426', 'G0402', 'G0468'
);

// Build SQL-friendly strings
$raDxCodesStr = "'" . implode("','", $raDxCodes) . "'";
$encounterCodesStr = "'" . implode("','", $encounterCodes) . "'";

/**
 * Get functional status assessments (rule_patient_data, item = 'act_rafunc') for a patient
 * recorded during the performance period. Only these satisfy the numerator.
 */
function getRaFunctionalAssessments($pid, $perfStart, $perfEnd)
{
    $assessmentQuery = "
    SELECT
        DATE(date) AS assessment_date,
        result
    FROM rule_patient_data
    WHERE pid = ?
    AND item = 'act_rafunc'
    AND date BETWEEN ? AND ?
    ORDER BY date
    ";

    $assessmentRes = sqlStatement($assessmentQuery, array($pid, $perfStart, $perfEnd));
    $details = array();

    while ($assessRow = sqlFetchArray($assessmentRes)) {
        $details[] = $assessRow['assessment_date'] . ' (Result: ' . ($assessRow['result'] ?: 'N/A') . ')';
    }

    return $details;
}

/**
 * Main query to find patients with RA encounters
 * This query finds all unique patients with qualifying encounters
 */
$sql = "
SELECT DISTINCT
    p.pid,
    p.pubpid,
    p.lname AS last_name,
    p.fname AS first_name,
    p.mname AS middle_name,
    p.DOB AS date_of_birth,
    p.sex
FROM
    patient_data p
INNER JOIN
    form_encounter fe ON p.pid = fe.pid
    AND fe.date BETWEEN '$rangeStart' AND '$rangeEnd'
INNER JOIN
    billing b ON fe.encounter = b.encounter
    AND fe.pid = b.pid
    AND b.code IN ($encounterCodesStr)
    AND b.activity = 1
WHERE
    p.deceased_date IS NULL
    AND TIMESTAMPDIFF(YEAR, p.DOB, fe.date) >= 18
    AND EXISTS (
        SELECT 1
        FROM billing b_dx
        WHERE b_dx.pid = p.pid
        AND b_dx.code IN ($raDxCodesStr)
        AND b_dx.activity = 1
    )
ORDER BY
    p.lname, p.fname
";

// Execute main query
$res = sqlStatement($sql);

$results = array();
$denominatorCount = 0;
$excludedCount = 0;
$patientsWithAssessments = 0;

while ($row = sqlFetchArray($res)) {
    $pid = $row['pid'];

    // Get all qualifying RA encounters for this patient in both periods (telehealth included)
    $encounterQuery = "
    SELECT DISTINCT
        fe.encounter,
        DATE(fe.date) as encounter_date,
        fe.provider_id,
        CONCAT(u.lname, ', ', u.fname) AS provider_name,
        (SELECT GROUP_CONCAT(DISTINCT b_dx.code SEPARATOR ', ')
         FROM billing b_dx
         WHERE b_dx.encounter = fe.encounter
         AND b_dx.pid = fe.pid
         AND b_dx.code IN ($raDxCodesStr)
         AND b_dx.activity = 1
        ) AS ra_diagnosis_codes,
        (SELECT GROUP_CONCAT(DISTINCT b_cpt.code SEPARATOR ', ')
         FROM billing b_cpt
         WHERE b_cpt.encounter = fe.encounter
         AND b_cpt.pid = fe.pid
         AND b_cpt.code IN ($encounterCodesStr)
         AND b_cpt.activity = 1
        ) AS encounter_codes
    FROM
        form_encounter fe
    INNER JOIN
        billing b ON fe.encounter = b.encounter
        AND fe.pid = b.pid
        AND b.code IN ($encounterCodesStr)
        AND b.activity = 1
    LEFT JOIN
        users u ON fe.provider_id = u.id
    WHERE
        fe.pid = ?
        AND fe.date BETWEEN '$rangeStart' AND '$rangeEnd'
        AND TIMESTAMPDIFF(YEAR, ?, fe.date) >= 18
        AND EXISTS (
            SELECT 1
            FROM billing b_dx
            WHERE b_dx.encounter = fe.encounter
            AND b_dx.pid = fe.pid
            AND b_dx.code IN ($raDxCodesStr)
            AND b_dx.activity = 1
        )
    ORDER BY fe.date
    ";

    $encounterRes = sqlStatement($encounterQuery, array($pid, $row['date_of_birth']));

    $encounters = array();
    $perfPeriodEncounters = array();

    while ($encRow = sqlFetchArray($encounterRes)) {
        $encounters[] = array(
            'encounter' => $encRow['encounter'],
            'date' => $encRow['encounter_date'],
            'provider' => $encRow['provider_name'],
            'ra_codes' => $encRow['ra_diagnosis_codes'],
            'cpt_codes' => $encRow['encounter_codes']
        );

        // Track performance period encounters separately
        if ($encRow['encounter_date'] >= $performancePeriodStart && $encRow['encounter_date'] <= $performancePeriodEnd) {
            $perfPeriodEncounters[] = $encRow['encounter_date'];
        }
    }

    if (count($encounters) == 0) {
        continue; // No qualifying encounters
    }

    // Denominator logic per 2026 spec:
    // 1) at least one RA encounter in the performance period, and
    // 2) an additional RA encounter (performance or prior period) at least 90 days
    //    before or after one of those performance-period encounters.
    $exclusionReason = '';
    if (count($perfPeriodEncounters) == 0) {
        $exclusionReason = "No qualifying RA encounter in $reportingYear performance period";
    } else {
        $has90DaySeparation = false;
        foreach ($perfPeriodEncounters as $perfDate) {
            $perfDt = new DateTime($perfDate);
            foreach ($encounters as $enc) {
                // Calendar-day difference (DateTime avoids DST off-by-one issues)
                if ($perfDt->diff(new DateTime($enc['date']))->days >= 90) {
                    $has90DaySeparation = true;
                    break 2;
                }
            }
        }
        if (!$has90DaySeparation) {
            $exclusionReason = "No additional RA encounter at least 90 days from a $reportingYear encounter";
        }
    }

    $assessments = getRaFunctionalAssessments($pid, $perfRangeStart, $rangeEnd);

    // Numerator only credits assessments performed during the performance period
    $hasAssessment = count($assessments) > 0;

    if ($exclusionReason !== '') {
        $excludedCount++;
        $status = 'EXCLUDED';
    } else {
        $denominatorCount++;
        $status = 'INCLUDED';
        if ($hasAssessment) {
            $patientsWithAssessments++;
        }
    }

    $results[] = array(
        'pid' => $pid,
        'pubpid' => $row['pubpid'],
        'last_name' => $row['last_name'],
        'first_name' => $row['first_name'],
        'middle_name' => $row['middle_name'],
        'date_of_birth' => $row['date_of_birth'],
        'sex' => $row['sex'],
        'denominator_status' => $status,
        'exclusion_reason' => $exclusionReason,
        'encounter_count' => count($encounters),
        'perf_period_encounter_count' => count($perfPeriodEncounters),
        'encounter_dates' => implode('; ', array_column($encounters, 'date')),
        'ra_codes' => implode('; ', array_unique(array_filter(array_column($encounters, 'ra_codes')))),
        'assessment_found' => $hasAssessment ? 'YES' : 'NO',
        'assessment_details_current' => implode('; ', $assessments)
    );
}

// Check if CSV export is requested
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="MIPS_178_RA_Functional_Status_Report_' . $reportingYear . '_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    // Write header row
    fputcsv($output, array(
        'Patient ID',
        'Patient Chart ID',
        'Last Name',
        'First Name',
        'Middle Name',
        'Date of Birth',
        'Sex',
        'Denominator Status',
        'Exclusion Reason',
        'Total Encounters (Both Periods)',
        'Performance Period Encounters',
        'Encounter Dates',
        'RA ICD-10 Codes',
        "Assessment Found in $reportingYear (act_rafunc)",
        "Assessments $reportingYear (performance period)"
    ));

    // Write data rows
    foreach ($results as $row) {
        fputcsv($output, array(
            $row['pid'],
            $row['pubpid'],
            $row['last_name'],
            $row['first_name'],
            $row['middle_name'],
            $row['date_of_birth'],
            $row['sex'],
            $row['denominator_status'],
            $row['exclusion_reason'],
            $row['encounter_count'],
            $row['perf_period_encounter_count'],
            $row['encounter_dates'],
            $row['ra_codes'],
            $row['assessment_found'],
            $row['assessment_details_current']
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
    <title>MIPS 178 Rheumatoid Arthritis Functional Status Assessment - <?php echo $reportingYear; ?> Denominator Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        .report-info { background: #f0f0f0; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .report-info p { margin: 5px 0; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; font-size: 12px; }
        th { background-color: #4CAF50; color: white; padding: 10px 8px; text-align: left; font-size: 11px; }
        td { border: 1px solid #ddd; padding: 6px; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        tr:hover { background-color: #ddd; }
        .summary { margin-top: 20px; background: #e8f5e9; padding: 15px; border-radius: 5px; }
        .summary-item { margin: 8px 0; font-size: 15px; }
        .note { background: #fff3cd; padding: 10px; margin: 10px 0; border-left: 4px solid #ffc107; }
        .export-btn { display: inline-block; padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; margin-top: 10px; }
        .export-btn:hover { background: #45a049; }
        .status-included { color: #2e7d32; font-weight: bold; }
        .status-excluded { color: #c62828; font-weight: bold; }
        .assessment-yes { color: #2e7d32; font-weight: bold; }
        .assessment-no { color: #d84315; font-weight: bold; }
        .info-box { background: #e7f3ff; padding: 15px; margin: 20px 0; border-left: 4px solid #2196F3; }
        .info-box h3 { margin-top: 0; }
        .info-box ul { margin: 10px 0; }
        .info-box li { margin: 5px 0; }
        .year-label { font-weight: bold; color: #1976d2; }
        .section-heading { margin-top: 35px; margin-bottom: 0; padding: 8px 12px; border-radius: 5px 5px 0 0; font-size: 18px; }
        .section-heading + table { margin-top: 0; }
        .section-eligible { background: #e8f5e9; color: #2e7d32; border-left: 4px solid #4CAF50; }
        .section-excluded { background: #ffebee; color: #c62828; border-left: 4px solid #c62828; }
        .section-excluded + table th { background-color: #9e9e9e; }
    </style>
</head>
<body>

    <div class="report-info">
        <p><strong>Measure:</strong> MIPS 178 - RA: Functional Status Assessment (<?php echo $reportingYear; ?> MIPS CQM Specifications)</p>
        <p><strong>Report Date:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        <p><strong>Performance Period:</strong> <?php echo $performancePeriodStart; ?> to <?php echo $performancePeriodEnd; ?></p>
        <p><strong>Prior Period (for 90-day rule):</strong> <?php echo $priorPeriodStart; ?> to <?php echo $priorPeriodEnd; ?></p>
        <p><a href="?export=csv" class="export-btn">Export to CSV</a></p>
    </div>

    <div class="note">
        <strong>Note:</strong> This report identifies patients with rheumatoid arthritis who meet the <?php echo $reportingYear; ?> denominator criteria
        (age >= 18, a qualifying RA encounter in <?php echo $reportingYear; ?>, plus an additional RA encounter at least 90 days before or after it)
        and checks whether they have a functional status assessment recorded as rule item 'act_rafunc' during the
        <?php echo $reportingYear; ?> performance period. Telehealth encounters are included. Assessments from
        before the performance period do not satisfy the numerator and are not shown.
    </div>

    <div class="summary">
        <div class="summary-item"><strong>Total Patients Evaluated:</strong> <?php echo count($results); ?></div>
        <div class="summary-item"><strong>Patients in Denominator (Included):</strong> <?php echo $denominatorCount; ?></div>
        <div class="summary-item"><strong>Denominator Patients with <?php echo $reportingYear; ?> Assessment:</strong> <?php echo $patientsWithAssessments; ?></div>
        <div class="summary-item"><strong>Excluded Patients:</strong> <?php echo $excludedCount; ?></div>
        <?php if ($denominatorCount > 0): ?>
        <div class="summary-item"><strong>Assessment Rate:</strong> <?php echo number_format(($patientsWithAssessments / $denominatorCount) * 100, 2); ?>%</div>
        <?php endif; ?>
    </div>

    <?php
    // Split results into eligible (denominator) and excluded sections
    $eligibleResults = array_filter($results, function ($r) {
        return $r['denominator_status'] == 'INCLUDED';
    });
    $excludedResults = array_filter($results, function ($r) {
        return $r['denominator_status'] != 'INCLUDED';
    });

    $sections = array(
        array(
            'title' => 'Eligible Patients (Denominator)',
            'class' => 'section-eligible',
            'rows' => $eligibleResults,
            'showReason' => false,
            'empty' => 'No patients met the denominator criteria for the specified performance period.'
        ),
        array(
            'title' => 'Excluded Patients',
            'class' => 'section-excluded',
            'rows' => $excludedResults,
            'showReason' => true,
            'empty' => 'No patients were excluded.'
        )
    );
    ?>

    <?php foreach ($sections as $section): ?>
    <h2 class="section-heading <?php echo $section['class']; ?>">
        <?php echo htmlspecialchars($section['title']); ?> (<?php echo count($section['rows']); ?>)
    </h2>

    <?php if (count($section['rows']) > 0): ?>
        <table>
            <tr>
                <th>Patient ID</th>
                <th>Chart ID</th>
                <th>Patient Name</th>
                <th>DOB</th>
                <th>Sex</th>
                <?php if ($section['showReason']): ?>
                <th>Exclusion Reason</th>
                <?php endif; ?>
                <th>Total Enc.</th>
                <th>Perf. Period Enc.</th>
                <th>Encounter Dates</th>
                <th>RA ICD-10 Codes</th>
                <th>Assessment Found (<?php echo $reportingYear; ?>)</th>
                <th>Assessments <?php echo $reportingYear; ?></th>
            </tr>

            <?php foreach ($section['rows'] as $row): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['pid']); ?></td>
                <td><?php echo htmlspecialchars($row['pubpid']); ?></td>
                <td><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></td>
                <td><?php echo htmlspecialchars($row['date_of_birth']); ?></td>
                <td><?php echo htmlspecialchars($row['sex']); ?></td>
                <?php if ($section['showReason']): ?>
                <td class="status-excluded" style="font-size: 11px;"><?php echo htmlspecialchars($row['exclusion_reason']); ?></td>
                <?php endif; ?>
                <td><?php echo htmlspecialchars($row['encounter_count']); ?></td>
                <td><?php echo htmlspecialchars($row['perf_period_encounter_count']); ?></td>
                <td style="font-size: 11px;"><?php echo htmlspecialchars($row['encounter_dates']); ?></td>
                <td style="font-size: 11px;"><?php echo htmlspecialchars($row['ra_codes']); ?></td>
                <td class="<?php echo $row['assessment_found'] == 'YES' ? 'assessment-yes' : 'assessment-no'; ?>">
                    <?php echo htmlspecialchars($row['assessment_found']); ?>
                </td>
                <td style="font-size: 10px;"><?php echo htmlspecialchars($row['assessment_details_current'] ?: 'None'); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p><?php echo htmlspecialchars($section['empty']); ?></p>
    <?php endif; ?>
    <?php endforeach; ?>

    <div class="info-box">
        <h3>Denominator Criteria (<?php echo $reportingYear; ?>):</h3>
        <ul>
            <li>Age >= 18 years on date of encounter</li>
            <li>Diagnosis of rheumatoid arthritis (RA) - ICD-10-CM codes M05.*, M06.* (including M05.A)</li>
            <li>At least one qualifying RA encounter during the <?php echo $reportingYear; ?> performance period</li>
            <li>An additional qualifying RA encounter (<?php echo $priorYear; ?> or <?php echo $reportingYear; ?>) at least 90 days before or after a <?php echo $reportingYear; ?> RA encounter</li>
            <li>Qualifying CPT/HCPCS encounter codes (98000-98016, 99202-99205, 99212-99215, 99341-99350, 99424, 99426, G0402, G0468)</li>
            <li>Telehealth encounters are eligible for this measure</li>
        </ul>

        <h3>Assessment Tracking:</h3>
        <ul>
            <li>This report checks for entries in <strong>rule_patient_data</strong> table with <strong>item = 'act_rafunc'</strong></li>
            <li>Only assessments dated in <?php echo $reportingYear; ?> are checked; earlier assessments do not count toward the numerator</li>
            <li>The assessment must use an ACR-preferred tool: PROMIS Physical Function 10-item (PROMIS PF10a), HAQ-II, or MD-HAQ</li>
            <li>Submission coding: Performance Met = 1170F; Performance Not Met = 1170F-8P</li>
        </ul>

        <h3>Next Steps:</h3>
        <ul>
            <li>Review patients in denominator who show "Assessment Found (<?php echo $reportingYear; ?>): NO"</li>
            <li>Verify that functional status assessments are being recorded with proper result values</li>
            <li>Ensure clinical staff are using an ACR-preferred tool (PROMIS PF10a, HAQ-II, or MD-HAQ)</li>
            <li>Export to CSV for further analysis and reporting</li>
        </ul>
    </div>
</body>
</html>