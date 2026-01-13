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
 * - Encounter reason field contains biologic/immune therapy keywords
 */

// Include OpenEMR required files
require_once("../globals.php");
require_once("$srcdir/sql.inc.php");

// Performance period dates (modify as needed)
$performancePeriodStart = '2025-01-01';
$performancePeriodEnd = '2025-12-31';

// Qualifying encounter CPT/HCPCS codes for 2025
$encounterCodes = array(
    '99202', '99203', '99204', '99205', '99212', '99213', '99214', '99215','99341', '99342', '99344', '99345',
     '99347', '99348', '99349', '99350',
      '99424', '99426','G0402', 'G0468'
);

// Build the SQL query with encounter codes
$encounterCodesStr = "'" . implode("','", $encounterCodes) . "'";

// Build therapy keyword conditions for the WHERE clause (case-insensitive)
$therapyKeywords = "
    (LOWER(fe.reason) LIKE '%abatacept%'
    OR LOWER(fe.reason) LIKE '%adalimumab%'
    OR LOWER(fe.reason) LIKE '%adalimumab-aacf%'
    OR LOWER(fe.reason) LIKE '%adalimumab-aaty%'
    OR LOWER(fe.reason) LIKE '%adalimumab-adaz%'
    OR LOWER(fe.reason) LIKE '%adalimumab-adbm%'
    OR LOWER(fe.reason) LIKE '%adalimumab-afzb%'
    OR LOWER(fe.reason) LIKE '%yusimryadalimumab-atto%'
    OR LOWER(fe.reason) LIKE '%adalimumab-aqvh%'
    OR LOWER(fe.reason) LIKE '%adalimumab-bwwd%'
    OR LOWER(fe.reason) LIKE '%adalimumab-fkjp%'
    OR LOWER(fe.reason) LIKE '%anakinra%'
    OR LOWER(fe.reason) LIKE '%baricitinib%'
    OR LOWER(fe.reason) LIKE '%brodalumab%'
    OR LOWER(fe.reason) LIKE '%canakinumab%'
    OR LOWER(fe.reason) LIKE '%certolizumab%'
    OR LOWER(fe.reason) LIKE '%lyophilized certolizumab pegol%'
    OR LOWER(fe.reason) LIKE '%etanercept%'
    OR LOWER(fe.reason) LIKE '%golimumab%'
    OR LOWER(fe.reason) LIKE '%guselkumab%'
    OR LOWER(fe.reason) LIKE '%infliximab%'
    OR LOWER(fe.reason) LIKE '%infliximab-abda%'
    OR LOWER(fe.reason) LIKE '%infliximab-axxq%'
    OR LOWER(fe.reason) LIKE '%infliximab-dyyb%'
    OR LOWER(fe.reason) LIKE '%ixekizumab%'
    OR LOWER(fe.reason) LIKE '%risankizumab-rzaa%'
    OR LOWER(fe.reason) LIKE '%sarilumab%'
    OR LOWER(fe.reason) LIKE '%secukinumab%'
    OR LOWER(fe.reason) LIKE '%tildrakizumab%'
    OR LOWER(fe.reason) LIKE '%tocilizumab%'
    OR LOWER(fe.reason) LIKE '%tofacitinib%'
    OR LOWER(fe.reason) LIKE '%upadacitinib%'
    OR LOWER(fe.reason) LIKE '%ustekinumab%'
    OR LOWER(fe.reason) LIKE '%orencia%'
    OR LOWER(fe.reason) LIKE '%humira%'
    OR LOWER(fe.reason) LIKE '%idacio%'
    OR LOWER(fe.reason) LIKE '%yuflyma%'
    OR LOWER(fe.reason) LIKE '%hyrimoz%'
    OR LOWER(fe.reason) LIKE '%cyltezo%'
    OR LOWER(fe.reason) LIKE '%abrilada%'
    OR LOWER(fe.reason) LIKE '%amjevita%'
    OR LOWER(fe.reason) LIKE '%hadlima%'
    OR LOWER(fe.reason) LIKE '%hulio%'
    OR LOWER(fe.reason) LIKE '%kineret%'
    OR LOWER(fe.reason) LIKE '%olumiant%'
    OR LOWER(fe.reason) LIKE '%siliq%'
    OR LOWER(fe.reason) LIKE '%ilaris%'
    OR LOWER(fe.reason) LIKE '%cimzia%'
    OR LOWER(fe.reason) LIKE '%enbrel%'
    OR LOWER(fe.reason) LIKE '%simponi%'
    OR LOWER(fe.reason) LIKE '%tremfya%'
    OR LOWER(fe.reason) LIKE '%remicade%'
    OR LOWER(fe.reason) LIKE '%renflexis%'
    OR LOWER(fe.reason) LIKE '%avsola%'
    OR LOWER(fe.reason) LIKE '%inflectra%'
    OR LOWER(fe.reason) LIKE '%taltz%'
    OR LOWER(fe.reason) LIKE '%skyrizi%'
    OR LOWER(fe.reason) LIKE '%kevzara%'
    OR LOWER(fe.reason) LIKE '%cosentyx%'
    OR LOWER(fe.reason) LIKE '%ilumya%'
    OR LOWER(fe.reason) LIKE '%actemra%'
    OR LOWER(fe.reason) LIKE '%xeljanz%'
    OR LOWER(fe.reason) LIKE '%rinvoq%'
    OR LOWER(fe.reason) LIKE '%stelara%'
    OR LOWER(fe.reason) LIKE '%therapy%'
    OR LOWER(fe.reason) LIKE '%biologic%'
    OR LOWER(fe.reason) LIKE '%immune%'
    OR LOWER(fe.reason) LIKE '%modifier%')
";

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
    fe.reason AS encounter_reason,
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
    AND $therapyKeywords
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
        table { border-collapse: collapse; width: 100%; margin-top: 20px; font-size: 12px; }
        th { background-color: #4CAF50; color: white; padding: 12px; text-align: left; }
        td { border: 1px solid #ddd; padding: 8px; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        tr:hover { background-color: #ddd; }
        .summary { margin-top: 20px; font-weight: bold; font-size: 16px; }
        .note { background: #fff3cd; padding: 10px; margin: 10px 0; border-left: 4px solid #ffc107; }
        .export-btn { display: inline-block; padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; margin-top: 10px; }
        .export-btn:hover { background: #45a049; }
        .reason-cell { max-width: 250px; word-wrap: break-word; }
    </style>
</head>
<body>
    
    <div class="report-info">
    <p><strong>Measure Description:</strong> Tuberculosis Screening Prior to First Course of Biologic and/or Immune Response Modifier Therapy</p>   
    <p><strong>Report Date:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        <p><strong>Performance Period:</strong> <?php echo $performancePeriodStart; ?> to <?php echo $performancePeriodEnd; ?></p>
       
        <p><a href="?export=csv" class="export-btn">Export to CSV</a></p>
    </div>
    
    <div class="note">
        <strong>Note:</strong> This report identifies patients aged 18+ who had qualifying encounters during the performance period 
        <strong>AND</strong> whose encounter reason field contains biologic/immune therapy medication keywords. 
        Manual review is required to verify: (1) first-time biologic/immune response modifier therapy was initiated (G2182), 
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
        <h3>Denominator Criteria (Updated):</h3>
        <ol>
            <li>Patients aged >= 18 years on date of encounter</li>
            <li>Patient encounter during the performance period (<?php echo $performancePeriodStart; ?> to <?php echo $performancePeriodEnd; ?>)</li>
            <li>Qualifying CPT/HCPCS encounter codes present</li>
            <li>Encounter reason field contains biologic/immune therapy medication keywords</li>
        </ol>
        
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