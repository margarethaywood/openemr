<?php
/**
 * MIPS Quality Measure 374 - Closing the Referral Loop: Receipt of Specialist Report
 * 
 * DENOMINATOR REPORT ONLY
 * This report identifies patients who meet the denominator criteria.
 * 
 * Denominator Criteria:
 * - All patients (no age restriction)
 * - Patient encounter during the performance period with qualifying CPT codes
 * - Patient referred to another provider during the performance period
 * 
 * NOTE: Manual review required to identify referrals as G9968 code is not used by this practice.
 */

// Include OpenEMR required files
require_once("../globals.php");
require_once("$srcdir/sql.inc.php");

// Performance period dates
$performancePeriodStart = '2025-01-01';
$performancePeriodEnd = '2025-10-31';

// Qualifying encounter CPT codes for MIPS 374
$encounterCodes = array(
    '99202', '99203', '99204', '99205', '99211', '99212', '99213', '99214', '99215',
    '99241', '99242', '99243', '99244', '99245', '99341', '99342', '99344', '99345',
    '99347', '99348', '99349', '99350', '99381', '99382', '99383', '99384', '99385',
    '99386', '99387', '99391', '99392', '99393', '99394', '99395', '99396', '99397',
    '99401', '99402', '99403', '99404', '99411', '99412', '99429', '99455', '99456'
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
    users u ON fe.provider_id = u.id
WHERE 
    fe.date BETWEEN '$performancePeriodStart' AND '$performancePeriodEnd'
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
    header('Content-Disposition: attachment; filename="MIPS_374_Denominator_Report_' . date('Y-m-d') . '.csv"');
    
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
        'Facility ID',
        'Referral Made (Y/N)',
        'Referral Date',
        'Referred To',
        'Specialist Report Received (Y/N)',
        'Report Receipt Date'
    ));
    
    // Write data rows with blank columns for manual entry
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
            $row['facility_id'],
            '', // Referral Made - to be filled manually
            '', // Referral Date - to be filled manually
            '', // Referred To - to be filled manually
            '', // Specialist Report Received - to be filled manually
            ''  // Report Receipt Date - to be filled manually
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
    <title>MIPS 374 Closing the Referral Loop - Denominator Report</title>
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
        .warning { background: #ffe6e6; padding: 10px; margin: 10px 0; border-left: 4px solid #ff0000; }
        .export-btn { display: inline-block; padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; margin-top: 10px; }
        .export-btn:hover { background: #45a049; }
    </style>
</head>
<body>

    
    <div class="report-info">
        <p><strong>Report Date:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        <p><strong>Performance Period:</strong> <?php echo $performancePeriodStart; ?> to <?php echo $performancePeriodEnd; ?></p>
        <p><strong>Measure Description:</strong> MIPS Quality Measure 374 - Closing the Referral Loop</p>
        <p><a href="?export=csv" class="export-btn">Export to CSV</a></p>
    </div>
    
    
    <div class="note">
        <strong>Denominator Criteria:</strong>
        <ul>
            <li>All patients (no age restriction)</li>
            <li>Had a qualifying encounter during the performance period</li>
            <li><strong>AND were referred to another provider during the performance period</strong></li>
        </ul>
        <strong>Note:</strong> The patients listed below meet criteria #1 and #2 only. Manual chart review is required to determine 
        if criterion #3 (referral made) is met. Only patients with confirmed referrals should be included in the final denominator.
    </div>
    
    <div class="summary">Total Patients with Qualifying Encounters: <?php echo count($results); ?></div>
    
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
                <td><?php echo htmlspecialchars($row['code_description']); ?></td>
                <td><?php echo htmlspecialchars($row['provider_name']); ?></td>
                <td><?php echo htmlspecialchars($row['facility_id']); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No patients found with qualifying encounters during the specified performance period.</p>
    <?php endif; ?>
    
    <div style="margin-top: 30px; padding: 15px; background: #e7f3ff; border-left: 4px solid #2196F3;">
        <h3>Next Steps for Manual Review:</h3>
        <ol>
            <li><strong>Identify Referrals:</strong> Review each patient's chart to determine if they were referred to another provider 
            (specialist, ancillary provider, or other healthcare provider) during the performance period (<?php echo $performancePeriodStart; ?> to <?php echo $performancePeriodEnd; ?>)</li>
            
            <li><strong>Document Referral Details:</strong> For patients with referrals, document:
                <ul>
                    <li>Date of referral</li>
                    <li>Provider/specialist referred to</li>
                    <li>Reason for referral</li>
                </ul>
            </li>
            
            <li><strong>Check for Specialist Report:</strong> For confirmed referrals, verify if a report was received from the provider 
            to whom the patient was referred. The report should include:
                <ul>
                    <li>Findings from the specialist evaluation</li>
                    <li>Recommendations or treatment plan</li>
                    <li>Any follow-up instructions</li>
                </ul>
            </li>
            
            <li><strong>Performance Scoring:</strong>
                <ul>
                    <li><strong>Performance Met (G9970):</strong> Specialist report received and documented in patient record</li>
                    <li><strong>Performance Not Met (G9971):</strong> Referral made but no specialist report received or documented</li>
                </ul>
            </li>
            
            <li><strong>Alternative Methods to Identify Referrals:</strong>
                <ul>
                    <li>Review encounter notes for referral documentation</li>
                    <li>Check for referral orders or consult requests in the system</li>
                    <li>Look for specialist correspondence in the patient record</li>
                    <li>Review any external document uploads or faxed reports</li>
                </ul>
            </li>
        </ol>
        
        <h3>Suggested Workflow Improvement:</h3>
        <p>Consider implementing one of the following to streamline future reporting:</p>
        <ul>
            <li>Use G9968 code at the time of referral to track referrals systematically</li>
            <li>Create a referral tracking system or flag in OpenEMR</li>
            <li>Implement a structured referral form or template</li>
            <li>Establish a process to log incoming specialist reports</li>
        </ul>
    </div>
    
    <div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
        <h3>Performance Period Note:</h3>
        <p>The performance period for this measure is <strong>January 1, 2025 through October 31, 2025</strong> to ensure all referrals and specialist reports are evaluated within this timeframe.</p>
    </div>
</body>
</html>