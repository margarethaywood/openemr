<?php
/**
 * MIPS Quality Measure 410 - Psoriasis: Clinical Response to Systemic Medications
 * 
 * DENOMINATOR REPORT ONLY
 * This report identifies patients who meet the denominator criteria.
 * 
 * Denominator Criteria:
 * - All patients (no age restriction)
 * - Diagnosis of psoriasis vulgaris (ICD-10-CM: L40.0)
 * - Patient encounter during the performance period with qualifying CPT/HCPCS codes
 * - Performance period: 2025-01-01 to 2025-12-31
 */

// Include OpenEMR required files
require_once("../globals.php");
require_once("$srcdir/sql.inc.php");

// Performance period dates
$performancePeriodStart = '2025-01-01';
$performancePeriodEnd = '2025-12-31';

// Qualifying encounter CPT/HCPCS codes
$encounterCodes = array(
    // Office/outpatient visits
    '98000', '98001', '98002', '98003', '98004', '98005', '98006', '98007', 
    '98008', '98009', '98010', '98011', '98012', '98013', '98014', '98015', '98016',
    // Office/outpatient visits - new patient
    '99202', '99203', '99204', '99205',
    // Office/outpatient visits - established patient
    '99212', '99213', '99214', '99215',
    // Office consultations (may be inactive but included per spec)
    '99242', '99243', '99244', '99245',
    // Home visits - new patient
    '99341', '99342', '99344', '99345',
    // Home visits - established patient
    '99347', '99348', '99349', '99350',
    // Other evaluation codes
    '99424', '99426',
    // HCPCS codes
    'G0438', 'G0439'
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
    CONCAT(u.lname, ', ', u.fname) AS provider_name,
    fe.reason AS encounter_reason,
    (SELECT GROUP_CONCAT(DISTINCT CONCAT(b_dx.code, ' (', DATE_FORMAT(fe_dx.date, '%Y-%m-%d'), ')') SEPARATOR ', ')
     FROM billing b_dx
     INNER JOIN form_encounter fe_dx ON b_dx.encounter = fe_dx.encounter
     WHERE b_dx.pid = p.pid
     AND (b_dx.code LIKE 'L40.0%' OR b_dx.code = 'L400' OR b_dx.code = 'L40.0')
     AND b_dx.activity = 1
     ORDER BY fe_dx.date DESC
     LIMIT 5
    ) AS psoriasis_diagnosis_history
FROM 
    patient_data p
INNER JOIN 
    form_encounter fe ON p.pid = fe.pid
    AND fe.date BETWEEN '$performancePeriodStart' AND '$performancePeriodEnd'
INNER JOIN 
    billing b ON fe.encounter = b.encounter 
    AND fe.pid = b.pid
    AND b.code IN ($encounterCodesStr)
    AND b.activity = 1
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
        AND (b_dx.code LIKE 'L40.0%' OR b_dx.code = 'L400' OR b_dx.code = 'L40.0')
        AND b_dx.activity = 1
    )
    AND NOT EXISTS (
        SELECT 1
        FROM billing b_other
        WHERE b_other.pid = p.pid
        AND b_other.activity = 1
        AND (
            b_other.code LIKE 'L40.1%' OR b_other.code = 'L401'
            OR b_other.code LIKE 'L40.2%' OR b_other.code = 'L402'
            OR b_other.code LIKE 'L40.3%' OR b_other.code = 'L403'
            OR b_other.code LIKE 'L40.4%' OR b_other.code = 'L404'
            OR b_other.code LIKE 'L40.5%' OR b_other.code = 'L405'
            OR b_other.code LIKE 'L40.8%' OR b_other.code = 'L408'
            OR b_other.code LIKE 'L40.9%' OR b_other.code = 'L409'
        )
    )
    AND (
        LOWER(fe.reason) LIKE '%stelara%'
        OR LOWER(fe.reason) LIKE '%ustekinumab%'
        OR LOWER(fe.reason) LIKE '%taltz%'
        OR LOWER(fe.reason) LIKE '%ixekizumab%'
        OR LOWER(fe.reason) LIKE '%tremfya%'
        OR LOWER(fe.reason) LIKE '%guselkumab%'
        OR LOWER(fe.reason) LIKE '%skyrizi%'
        OR LOWER(fe.reason) LIKE '%risankizumab%'
        OR LOWER(fe.reason) LIKE '%amjevita%'
        OR LOWER(fe.reason) LIKE '%adalimumab%'
        OR LOWER(fe.reason) LIKE '%humira%'
        OR LOWER(fe.reason) LIKE '%methotrexate%'
        OR LOWER(fe.reason) LIKE '%rheumatrex%'
        OR LOWER(fe.reason) LIKE '%trexall%'
        OR LOWER(fe.reason) LIKE '%xatmep%'
        OR LOWER(fe.reason) LIKE '%otrexup%'
        OR LOWER(fe.reason) LIKE '%rasuvo%'
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
    header('Content-Disposition: attachment; filename="MIPS_410_Denominator_Report_' . date('Y-m-d') . '.csv"');
    
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
        'Encounter Reason',
        'Psoriasis Diagnosis History',
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
            $row['encounter_reason'],
            $row['psoriasis_diagnosis_history'],
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
    <title>MIPS 410 Psoriasis Clinical Response - Denominator Report</title>
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
    <h1>MIPS Quality Measure 410 - Psoriasis: Clinical Response to Systemic Medications</h1>
    <h2>Denominator Report</h2>
    
    <div class="report-info">
        <p><strong>Report Date:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        <p><strong>Performance Period:</strong> <?php echo $performancePeriodStart; ?> to <?php echo $performancePeriodEnd; ?></p>
        <p><strong>Measure Description:</strong> Psoriasis: Clinical Response to Systemic Medications</p>
        <p><a href="?export=csv" class="export-btn">Export to CSV</a></p>
    </div>
    
    <div class="note">
        <strong>Note:</strong> This report identifies all patients (regardless of age) with psoriasis vulgaris (ICD-10: L40.0) ONLY 
        (excludes patients with other concurrent psoriasis diagnoses) who had qualifying encounters during the performance period 
        with systemic medication keywords documented in the encounter reason field.
        <br><br>
        <strong>Exclusion:</strong> Patients with any other psoriasis diagnosis codes (L40.1-L40.9) are excluded per measure specifications.
        <br><br>
        <strong>Systemic Medications Searched:</strong> Stelara, ustekinumab, Taltz, ixekizumab, Tremfya, guselkumab, Skyrizi, 
        risankizumab, Amjevita, adalimumab, Humira, methotrexate, Rheumatrex, Trexall, Xatmep, Otrexup, Rasuvo
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
                <th>Encounter Reason</th>
                <th>Diagnosis History</th>
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
                <td><?php echo htmlspecialchars($row['encounter_reason']); ?></td>
                <td><?php echo htmlspecialchars($row['psoriasis_diagnosis_history']); ?></td>
                <td><?php echo htmlspecialchars($row['provider_name']); ?></td>
                <td><?php echo htmlspecialchars($row['facility_name']); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No patients found meeting the denominator criteria for the specified performance period.</p>
    <?php endif; ?>
    
    <div style="margin-top: 30px; padding: 15px; background: #e7f3ff; border-left: 4px solid #2196F3;">
        <h3>Denominator Criteria:</h3>
        <ol>
            <li>All patients (no age restriction)</li>
            <li>Diagnosis of psoriasis vulgaris (ICD-10-CM: L40.0) ONLY - excludes patients with other concurrent psoriasis diagnoses (L40.1-L40.9)</li>
            <li>Patient encounter during performance period with qualifying CPT/HCPCS codes</li>
            <li>Systemic medication keywords documented in encounter reason field</li>
        </ol>
        
        <h3>Next Steps for Manual Review:</h3>
        <ol>
            <li>Review the "Encounter Reason" field to confirm systemic medication documentation</li>
            <li>Verify patient has been treated with a systemic medication for psoriasis vulgaris (G9764 criteria)</li>
            <li>For qualifying patients in the denominator, assess clinical response using appropriate numerator criteria</li>
            <li>Document findings in patient chart and quality measure tracking system</li>
        </ol>
    </div>
</body>
</html>