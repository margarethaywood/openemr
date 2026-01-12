<?php
/**
 * MIPS Quality Measure 509 - Melanoma Continuity of Care
 * 
 * DENOMINATOR REPORT ONLY
 * This report identifies patients who meet the denominator criteria.
 * 
 * Report Denominator Criteria:
 * - Diagnosis of melanoma (C43%) or melanoma in situ (D03%) between 2020-01-01 and 2025-12-31
 * - Any qualifying encounter during the performance period (2025-01-01 to 2025-12-31) with qualifying CPT codes
 */

// Include OpenEMR required files
require_once("../globals.php");
require_once("$srcdir/sql.inc.php");

// Performance period dates
$performancePeriodStart = '2025-01-01';
$performancePeriodEnd = '2025-12-31';

// Diagnosis date range (expanded to include historical diagnoses)
$diagnosisDateStart = '2020-01-01';
$diagnosisDateEnd = '2025-12-31';

// Qualifying encounter CPT codes for 2025
$encounterCodes = array(
    '99202', '99203', '99204', '99205', '99211', '99212', '99213', '99214', '99215',
    '99242', '99243', '99244', '99245'
);

// Build the SQL query with encounter codes
$encounterCodesStr = "'" . implode("','", $encounterCodes) . "'";

$sql = "
SELECT DISTINCT
    p.pid,
    p.lname AS last_name,
    p.fname AS first_name,
    p.mname AS middle_name,
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
     AND fe_dx.date BETWEEN '$diagnosisDateStart' AND '$diagnosisDateEnd'
     AND b_dx.activity = 1
     ORDER BY fe_dx.date DESC
     LIMIT 10
    ) AS melanoma_diagnosis_history
FROM 
    patient_data p
INNER JOIN 
    billing b_melanoma ON p.pid = b_melanoma.pid
INNER JOIN 
    form_encounter fe_melanoma ON b_melanoma.encounter = fe_melanoma.encounter
LEFT JOIN 
    form_encounter fe ON p.pid = fe.pid 
    AND fe.date BETWEEN '$performancePeriodStart' AND '$performancePeriodEnd'
LEFT JOIN 
    billing b ON fe.encounter = b.encounter 
    AND fe.pid = b.pid
    AND b.code IN ($encounterCodesStr)
    AND b.activity = 1
LEFT JOIN 
    users u ON fe.provider_id = u.id
WHERE 
    (b_melanoma.code LIKE 'C43%' OR b_melanoma.code LIKE 'D03%')
    AND fe_melanoma.date BETWEEN '$diagnosisDateStart' AND '$diagnosisDateEnd'
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

// Check if CSV export is requested
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="MIPS_509_Denominator_Report_' . date('Y-m-d') . '.csv"');
    
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
        'Billing Code',
        'Melanoma Diagnosis History',
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
            $row['billing_code'],
            $row['melanoma_diagnosis_history'],
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
    <title>MIPS 509 Melanoma Continuity of Care - Numerator Report</title>
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
    </style>
</head>
<body>
    
    <div class="report-info">
    <p><strong>Measure Description:</strong> MIPS 509: Melanoma Tracking</p>   
    <p><strong>Report Date:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        <p><strong>Performance Period:</strong> <?php echo $performancePeriodStart; ?> to <?php echo $performancePeriodEnd; ?></p>
        <p><strong>Diagnosis Date Range:</strong> <?php echo $diagnosisDateStart; ?> to <?php echo $diagnosisDateEnd; ?></p>
        <p><a href="?export=csv" class="export-btn">Export to CSV</a></p>
    </div>
    
    <div class="note">
        <strong>Note:</strong> This report identifies ALL patients with a diagnosis of melanoma (C43%) or melanoma in situ (D03%) 
        documented between 2020-01-01 and 2025-12-31. If the patient had a qualifying encounter during the performance period (2025), 
        those encounter details are shown. The diagnosis history column also displays any instances of Z85.820 (Personal history of malignant melanoma of skin) for reference. 
        Manual review is required to verify numerator compliance with recall system requirements.
    </div>
    
    <div class="summary">Total Patients in Numerator: <?php echo count($results); ?></div>
    
    <?php if (count($results) > 0): ?>
        <table>
            <tr>
                <th>Patient ID</th>
                <th>Patient Name</th>
                <th>DOB</th>
                <th>Age</th>
                <th>Encounter Date</th>
                <th>Encounter ID</th>
                <th>Billing Code</th>
                <th>Melanoma Diagnosis History</th>
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
                <td><?php echo htmlspecialchars($row['billing_code']); ?></td>
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
            <li>All patients with diagnosis of melanoma (ICD-10: C43%) or melanoma in situ (ICD-10: D03%) between 2020-01-01 and 2025-12-31</li>
            <li>Qualifying encounters during performance period (2025-01-01 to 2025-12-31) are shown if they exist</li>
            <li>Patients without qualifying encounters in 2025 are still included if they have melanoma diagnosis in the 5-year period</li>
        </ol>
        
    </div>
</body>
</html>