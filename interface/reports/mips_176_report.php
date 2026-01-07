<?php
/**
 * MIPS Quality Measure 176 - Tuberculosis Screening Prior to First Course 
 * of Biologic and/or Immune Response Modifier Therapy
 * 
 * DENOMINATOR REPORT ONLY
 * This report identifies patients who meet the denominator criteria.
 * 
 * Denominator Criteria:
 * - Patients aged >= 18 years on date of encounter
 * - Patient encounter during the performance period with qualifying CPT/HCPCS codes
 * - Patient receiving first-time biologic and/or immune response modifier therapy (G2182)
 */

// Include OpenEMR required files
require_once("../globals.php");
require_once("$srcdir/sql.inc.php");

// Performance period dates (modify as needed)
$performancePeriodStart = '2025-01-01';
$performancePeriodEnd = '2025-12-31';

// Qualifying encounter CPT/HCPCS codes for 2025
$encounterCodes = array(
    // Office/outpatient visits - new patient
    '99202', '99203', '99204', '99205',
    // Office/outpatient visits - established patient
    '99212', '99213', '99214', '99215',
    // Home visits - new patient
    '99341', '99342', '99344', '99345',
    // Home visits - established patient
    '99347', '99348', '99349', '99350',
    // Other evaluation codes
    '99424', '99426',
    // HCPCS codes
    'G0402', 'G0468'
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
    TIMESTAMPDIFF(YEAR, p.DOB, fe.date) AS age_at_encounter,
    fe.encounter AS encounter_id,
    fe.date AS encounter_date,
    fe.facility_id,
    fac.name AS facility_name,
    b.code AS billing_code,
    b.code_type,
    b.code_text AS code_description,
    CONCAT(u.lname, ', ', u.fname) AS provider_name
FROM 
    patient_data p
INNER JOIN 
    form_encounter fe ON p.pid = fe.pid
INNER JOIN 
    billing b ON fe.encounter = b.encounter AND fe.pid = b.pid
LEFT JOIN 
    facility fac ON fe.facility_id = fac.id
LEFT JOIN 
    users u ON fe.provider_id = u.id
WHERE 
    fe.date BETWEEN '$performancePeriodStart' AND '$performancePeriodEnd'
    AND TIMESTAMPDIFF(YEAR, p.DOB, fe.date) >= 18
    AND b.code IN ($encounterCodesStr)
    AND b.activity = 1
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
    header('Content-Disposition: attachment; filename="MIPS_176_Denominator_Report_' . date('Y-m-d') . '.csv"');
    
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
        'Code Type',
        'Code Description',
        'Provider Name',
        'Facility Name'
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
            $row['code_type'],
            $row['code_description'],
            $row['provider_name'],
            $row['facility_name']
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
    <title>MIPS 176 TB Screening - Denominator Report</title>
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
    <h1>MIPS Quality Measure 176 - Tuberculosis Screening</h1>
    <h2>Denominator Report</h2>
    
    <div class="report-info">
        <p><strong>Report Date:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        <p><strong>Performance Period:</strong> <?php echo $performancePeriodStart; ?> to <?php echo $performancePeriodEnd; ?></p>
        <p><strong>Measure Description:</strong> Tuberculosis Screening Prior to First Course of Biologic and/or Immune Response Modifier Therapy</p>
        <p><a href="?export=csv" class="export-btn">Export to CSV</a></p>
    </div>
    
    <div class="note">
        <strong>Note:</strong> This report identifies patients aged 18+ who had qualifying encounters during the performance period. 
        Manual review is required to verify: (1) first-time biologic/immune response modifier therapy was initiated, 
        (2) TB screening was performed and results interpreted within 12 months prior to therapy initiation, and (3) any documented exceptions.
    </div>
    
    <div class="summary">Total Patients in Denominator: <?php echo count($results); ?></div>
    
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
                <th>Code Description</th>
                <th>Provider</th>
                <th>Facility</th>
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
                <td><?php echo htmlspecialchars($row['code_description']); ?></td>
                <td><?php echo htmlspecialchars($row['provider_name']); ?></td>
                <td><?php echo htmlspecialchars($row['facility_name']); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No patients found meeting the denominator criteria for the specified performance period.</p>
    <?php endif; ?>
    
    <div style="margin-top: 30px; padding: 15px; background: #e7f3ff; border-left: 4px solid #2196F3;">
        <h3>Next Steps for Manual Review:</h3>
        <ol>
            <li>Verify patient received first-time biologic and/or immune response modifier therapy (G2182) during the performance period</li>
            <li>For qualifying patients, verify TB screening was performed and results interpreted within 12 months prior to biologic therapy initiation</li>
            <li>Check for documentation of medical reasons for not screening (denominator exceptions)</li>
            <li>Document performance met (M1003), exception (M1004), or performance not met (M1005)</li>
        </ol>
    </div>
</body>
</html>