<?php
/**
 * MIPS Quality Measure 440 - Skin Cancer: Biopsy Reporting Time – Pathologist to Clinician
 * 
 * DENOMINATOR REPORT ONLY
 * This report identifies pathology reports that meet the denominator criteria.
 * 
 * Denominator Criteria:
 * - All pathology reports with diagnoses of cutaneous BCC, SCC, or melanoma (including in situ)
 * - Patient procedure during the performance period (CPT: 88304, 88305)
 * - Performance period: 2025-01-01 to 2025-12-24
 * 
 * Denominator Exclusions:
 * - Pathologists providing second opinion (G9784)
 * - Pathologist is same clinician who performed biopsy (G9939)
 * - Telehealth services (modifiers GQ, GT, POS 02, POS 10)
 */

// Include OpenEMR required files
require_once("../globals.php");
require_once("$srcdir/sql.inc.php");

// Performance period dates
$performancePeriodStart = '2025-01-01';
$performancePeriodEnd = '2025-12-24';

// Qualifying biopsy procedure CPT codes
$procedureCodes = array('11102', '11103', '11104', '11601', '11602', '11641', '11642', '11621', '11622');

// Diagnosis codes for cutaneous basal cell carcinoma or squamous cell carcinoma
$bccSccCodes = array(
    'C44.01', 'C44.02', 'C44.111', 'C44.1121', 'C44.1122', 'C44.1191', 'C44.1192',
    'C44.121', 'C44.1221', 'C44.1222', 'C44.1291', 'C44.1292', 'C44.211', 'C44.212',
    'C44.219', 'C44.221', 'C44.222', 'C44.229', 'C44.310', 'C44.311', 'C44.319',
    'C44.320', 'C44.321', 'C44.329', 'C44.41', 'C44.42', 'C44.510', 'C44.511',
    'C44.519', 'C44.520', 'C44.521', 'C44.529', 'C44.611', 'C44.612', 'C44.619',
    'C44.621', 'C44.622', 'C44.629', 'C44.711', 'C44.712', 'C44.719', 'C44.721',
    'C44.722', 'C44.729', 'C44.81', 'C44.82', 'C44.91', 'C44.92', 'D00.01',
    'D04.0', 'D04.10', 'D04.111', 'D04.112', 'D04.121', 'D04.122', 'D04.20',
    'D04.21', 'D04.22', 'D04.30', 'D04.39', 'D04.4', 'D04.5', 'D04.60',
    'D04.61', 'D04.62', 'D04.70', 'D04.71', 'D04.72', 'D04.8', 'D04.9'
);

// Diagnosis codes for melanoma
$melanomaCodes = array(
    'C43.0', 'C43.10', 'C43.111', 'C43.112', 'C43.121', 'C43.122', 'C43.20',
    'C43.21', 'C43.22', 'C43.30', 'C43.31', 'C43.39', 'C43.4', 'C43.51',
    'C43.52', 'C43.59', 'C43.60', 'C43.61', 'C43.62', 'C43.70', 'C43.71',
    'C43.72', 'C43.8', 'C43.9', 'D03.0', 'D03.10', 'D03.111', 'D03.112',
    'D03.121', 'D03.122', 'D03.20', 'D03.21', 'D03.22', 'D03.30', 'D03.39',
    'D03.4', 'D03.51', 'D03.52', 'D03.59', 'D03.60', 'D03.61', 'D03.62',
    'D03.70', 'D03.71', 'D03.72', 'D03.8', 'D03.9'
);

// Other malignant diagnosis codes
$otherMalignantCodes = array(
    'C06.0', 'C06.1', 'C06.2', 'C06.80', 'C06.89', 'C06.9', 'C44.90', 'C44.99',
    'C46.0', 'C46.1', 'C49.0', 'C49.10', 'C49.11', 'C49.12', 'C49.20', 'C49.21',
    'C49.22', 'C49.3', 'C49.4', 'C49.5', 'C49.6', 'C49.8', 'C49.9'
);

// Combine all diagnosis codes
$allDiagnosisCodes = array_merge($bccSccCodes, $melanomaCodes, $otherMalignantCodes);

// Build SQL strings
$procedureCodesStr = "'" . implode("','", $procedureCodes) . "'";
$diagnosisCodesStr = "'" . implode("','", $allDiagnosisCodes) . "'";

$sql = "
SELECT DISTINCT
    p.pid,
    p.lname AS last_name,
    p.fname AS first_name,
    p.mname AS middle_name,
    p.DOB AS date_of_birth,
    TIMESTAMPDIFF(YEAR, p.DOB, fe.date) AS age_at_encounter,
    fe.encounter AS encounter_id,
    fe.date AS encounter_date,
    fe.facility_id,
    b_proc.code AS procedure_code,
    b_proc.code_text AS procedure_description,
    CONCAT(u.lname, ', ', u.fname) AS provider_name,
    (SELECT GROUP_CONCAT(DISTINCT CONCAT(b_dx.code, ' (', DATE_FORMAT(fe_dx.date, '%Y-%m-%d'), ')') SEPARATOR ', ')
     FROM billing b_dx
     INNER JOIN form_encounter fe_dx ON b_dx.encounter = fe_dx.encounter
     WHERE b_dx.pid = p.pid
     AND b_dx.code IN ($diagnosisCodesStr)
     AND b_dx.activity = 1
     ORDER BY fe_dx.date DESC
     LIMIT 10
    ) AS skin_cancer_diagnosis_history
FROM 
    patient_data p
INNER JOIN 
    form_encounter fe ON p.pid = fe.pid
    AND fe.date BETWEEN '$performancePeriodStart' AND '$performancePeriodEnd'
INNER JOIN 
    billing b_proc ON fe.encounter = b_proc.encounter 
    AND fe.pid = b_proc.pid
    AND b_proc.code IN ($procedureCodesStr)
    AND b_proc.activity = 1
LEFT JOIN 
    facility fac ON fe.facility_id = fac.id
LEFT JOIN 
    users u ON fe.provider_id = u.id
WHERE 
    p.deceased_date IS NULL
    AND EXISTS (
        SELECT 1
        FROM billing b_dx
        WHERE b_dx.pid = p.pid
        AND b_dx.encounter = fe.encounter
        AND b_dx.code IN ($diagnosisCodesStr)
        AND b_dx.activity = 1
    )
    AND NOT EXISTS (
        SELECT 1
        FROM billing b_mod
        WHERE b_mod.encounter = fe.encounter
        AND b_mod.pid = p.pid
        AND (b_mod.modifier IN ('GQ', 'GT') OR b_mod.code IN ('G9784', 'G9939'))
        AND b_mod.activity = 1
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

// Check if CSV export is requested
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="MIPS_440_Denominator_Report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Write header row
    fputcsv($output, array(
        'Patient ID',
        'Last Name',
        'First Name',
        'Middle Name',
        'Date of Birth',
        'Age at Encounter',
        'Encounter Date',
        'Encounter ID',
        'Procedure Code',
        'Procedure Description',
        'Skin Cancer Diagnosis History',
        'Provider Name',
        'Facility ID'
    ));
    
    // Write data rows
    foreach ($results as $row) {
        fputcsv($output, array(
            $row['pid'],
            $row['last_name'],
            $row['first_name'],
            $row['middle_name'],
            $row['date_of_birth'],
            $row['age_at_encounter'],
            $row['encounter_date'],
            $row['encounter_id'],
            $row['procedure_code'],
            $row['procedure_description'],
            $row['skin_cancer_diagnosis_history'],
            $row['provider_name'],
            $row['facility_id']
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
    <title>MIPS 440 Skin Cancer Biopsy Reporting - Denominator Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        .report-info { background: #f0f0f0; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .report-info p { margin: 5px 0; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th { background-color: #4CAF50; color: white; padding: 12px; text-align: left; }
        td { border: 1px solid #ddd; padding: 8px; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        tr:hover { background-color: #ddd; }
        .summary { margin-top: 20px; font-weight: bold; font-size: 16px; }
        .note { background: #fff3cd; padding: 10px; margin: 10px 0; border-left: 4px solid #ffc107; }
        .export-btn { display: inline-block; padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; margin-top: 10px; }
        .export-btn:hover { background: #45a049; }
        .diagnosis-codes { font-size: 11px; max-width: 300px; word-wrap: break-word; }
    </style>
</head>
<body>
    
    <div class="report-info">
        <p><strong>Measure:</strong> MIPS 440: Skin Cancer: Biopsy Reporting Time – Pathologist to Clinician</p>   
        <p><strong>Report Date:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        <p><strong>Performance Period:</strong> <?php echo $performancePeriodStart; ?> to <?php echo $performancePeriodEnd; ?></p>
        <p><strong>Measure Description:</strong> Percentage of biopsies with diagnosis of cutaneous BCC, SCC, or melanoma in which pathologist communicates results to clinician within 7 days from tissue specimen receipt</p>
        <p><a href="?export=csv" class="export-btn">Export to CSV</a></p>
    </div>
    
    <div class="note">
        <strong>Note:</strong> This report identifies pathology reports (CPT 88304, 88305) with diagnoses of cutaneous basal cell carcinoma, 
        squamous cell carcinoma, or melanoma (including in situ) during the performance period. Manual review is required to:
        <ul>
            <li>Verify date tissue specimen was received by pathologist</li>
            <li>Verify date pathology report was sent to biopsying clinician</li>
            <li>Calculate if report was sent within 7 days of specimen receipt</li>
            <li>Document any denominator exceptions (wide local excisions or re-excisions: M1166)</li>
        </ul>
    </div>
    
    <div class="summary">Total Pathology Reports in Denominator: <?php echo count($results); ?></div>
    
    <?php if (count($results) > 0): ?>
        <table>
            <tr>
                <th>Patient ID</th>
                <th>Patient Name</th>
                <th>DOB</th>
                <th>Age</th>
                <th>Encounter Date</th>
                <th>Encounter ID</th>
                <th>Procedure Code</th>
                <th>Procedure Description</th>
                <th>Diagnosis History</th>
                <th>Provider</th>
                <th>Facility ID</th>
            </tr>
            
            <?php foreach ($results as $row): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['pid']); ?></td>
                <td><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></td>
                <td><?php echo htmlspecialchars($row['date_of_birth']); ?></td>
                <td><?php echo htmlspecialchars($row['age_at_encounter']); ?></td>
                <td><?php echo htmlspecialchars($row['encounter_date']); ?></td>
                <td><?php echo htmlspecialchars($row['encounter_id']); ?></td>
                <td><?php echo htmlspecialchars($row['procedure_code']); ?></td>
                <td><?php echo htmlspecialchars($row['procedure_description']); ?></td>
                <td class="diagnosis-codes"><?php echo htmlspecialchars($row['skin_cancer_diagnosis_history']); ?></td>
                <td><?php echo htmlspecialchars($row['provider_name']); ?></td>
                <td><?php echo htmlspecialchars($row['facility_id']); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No pathology reports found meeting the denominator criteria for the specified performance period.</p>
    <?php endif; ?>
    
    <div style="margin-top: 30px; padding: 15px; background: #e7f3ff; border-left: 4px solid #2196F3;">
        <h3>Denominator Criteria:</h3>
        <ol>
            <li>Biopsy procedure codes: 11102, 11103, 11601, 11602, 11641, 11642, 11621, 11622</li>
            <li>Diagnosis of cutaneous BCC, SCC, or melanoma (including in situ disease)</li>
            <li>Patient encounter during performance period (01/01/2025 to 12/24/2025)</li>
        </ol>
        
        <h3>Denominator Exclusions (automatically excluded from this report):</h3>
        <ul>
            <li>Pathologists providing second opinion on a biopsy (G9784)</li>
            <li>Pathologist is same clinician who performed the biopsy (G9939)</li>
            <li>Telehealth services (modifiers GQ, GT, POS 02, POS 10)</li>
        </ul>
        
        <h3>Next Steps for Manual Review:</h3>
        <ol>
            <li>For each pathology report, document:
                <ul>
                    <li>Date tissue specimen received by pathologist</li>
                    <li>Date pathology report sent to biopsying clinician</li>
                </ul>
            </li>
            <li>Calculate time difference - Performance Met if report sent within 7 days (G9785)</li>
            <li>Check for Denominator Exception: Wide local excisions or re-excisions (M1166)</li>
            <li>Document Performance Not Met if report sent after 7 days (G9786)</li>
        </ol>
        
        <h3>Diagnosis Code Categories:</h3>
        <ul>
            <li><strong>Cutaneous BCC/SCC:</strong> C44.x, D00.01, D04.x series</li>
            <li><strong>Melanoma:</strong> C43.x, D03.x series</li>
            <li><strong>Other Malignant:</strong> C06.x, C46.x, C49.x series</li>
        </ul>
    </div>
</body>
</html>