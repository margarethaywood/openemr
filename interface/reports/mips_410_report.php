<?php
/**
 * MIPS Quality Measure #410: Psoriasis Clinical Response to Systemic Medications
 * Denominator Report with CSV Export
 * 
 * Denominator Criteria:
 * 1. All patients, regardless of age
 * 2. Diagnosis for psoriasis vulgaris (ICD-10-CM): L40.0
 * 3. Patient encounter during the performance period with qualifying CPT/HCPCS codes
 * 4. Performance period: 2025-01-01 to 2025-12-31
 * 
 * Note: Manual review required to verify systemic medication treatment (G9764 criteria)
 */

require_once("../globals.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/options.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

// Check access control
if (!AclMain::aclCheckCore('acct', 'rep')) {
    echo xlt('Access Denied');
    exit;
}

// Handle CSV export
if (isset($_POST['export_csv'])) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
    
    generateCSV();
    exit;
}

/**
 * Generate CSV export
 */
function generateCSV() {
    $results = getDenominatorPatients();
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="mips_410_denominator_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, [
        'Patient ID',
        'Patient Name',
        'DOB',
        'Age',
        'Psoriasis Vulgaris Diagnosis Date',
        'ICD-10 Code',
        'Qualifying Encounter Date',
        'Encounter CPT/HCPCS Code',
        'Systemic Medication (if documented)',
        'Manual Review - Verify Systemic Med Treatment (G9764)'
    ]);
    
    // CSV data rows
    foreach ($results as $row) {
        fputcsv($output, [
            $row['pid'],
            $row['patient_name'],
            $row['dob'],
            $row['age'],
            $row['diagnosis_date'],
            $row['icd10_code'],
            $row['encounter_date'],
            $row['encounter_code'],
            $row['medication'],
            'YES - Verify systemic medication treatment'
        ]);
    }
    
    fclose($output);
}

/**
 * Get patients meeting denominator criteria
 */
function getDenominatorPatients() {
    // Performance period
    $start_date = '2025-01-01';
    $end_date = '2025-12-31';
    
    // Qualifying CPT/HCPCS codes for encounters
    $encounter_codes = [
        '98000', '98001', '98002', '98003', '98004', '98005', '98006', '98007', 
        '98008', '98009', '98010', '98011', '98012', '98013', '98014', '98015', 
        '98016', '99202', '99203', '99204', '99205', '99212', '99213', '99214', 
        '99215', '99242', '99243', '99244', '99245', '99341', '99342', '99344', 
        '99345', '99347', '99348', '99349', '99350', '99424', '99426', 'G0438', 'G0439'
    ];
    
    $codes_placeholder = implode(',', array_fill(0, count($encounter_codes), '?'));
    
    // Query to find patients with psoriasis vulgaris and qualifying encounters
    $query = "SELECT DISTINCT
        p.pid,
        CONCAT(p.fname, ' ', p.lname) AS patient_name,
        p.DOB AS dob,
        TIMESTAMPDIFF(YEAR, p.DOB, CURDATE()) AS age,
        l.date AS diagnosis_date,
        l.diagnosis AS icd10_code,
        e.date AS encounter_date,
        b_enc.code AS encounter_code,
        GROUP_CONCAT(DISTINCT pr.drug SEPARATOR '; ') AS medication
    FROM patient_data p
    INNER JOIN lists l ON p.pid = l.pid
    INNER JOIN form_encounter e ON p.pid = e.pid
    INNER JOIN billing b_enc ON e.encounter = b_enc.encounter 
        AND b_enc.code IN ($codes_placeholder)
        AND b_enc.activity = 1
    LEFT JOIN prescriptions pr ON p.pid = pr.patient_id 
        AND pr.active = 1
        AND pr.start_date <= ?
    WHERE l.type = 'medical_problem'
        AND l.diagnosis = 'L40.0'
        AND (l.enddate IS NULL OR l.enddate >= ?)
        AND e.date BETWEEN ? AND ?
    GROUP BY p.pid, l.date, e.date, b_enc.code
    ORDER BY p.lname, p.fname, e.date";
    
    $params = $encounter_codes;
    $params[] = $end_date;
    $params[] = $start_date;
    $params[] = $start_date;
    $params[] = $end_date;
    
    $result = sqlStatement($query, $params);
    
    $patients = [];
    while ($row = sqlFetchArray($result)) {
        $patients[] = $row;
    }
    
    return $patients;
}

?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('MIPS QM 410 - Denominator Report'); ?></title>
    <link rel="stylesheet" href="<?php echo $GLOBALS['assets_static_relative']; ?>/bootstrap/dist/css/bootstrap.min.css">
    <style>
        .report-container {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .report-header {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .info-box {
            background-color: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin-bottom: 20px;
        }
        .warning-box {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 20px;
        }
        .btn-export {
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="report-container">
        <div class="report-header">
            <h2><?php echo xlt('MIPS Quality Measure #410'); ?></h2>
            <h4><?php echo xlt('Psoriasis: Clinical Response to Systemic Medications - Denominator Report'); ?></h4>
            <p><strong><?php echo xlt('Performance Period:'); ?></strong> <?php echo xlt('January 1, 2025 - December 31, 2025'); ?></p>
        </div>

        <div class="info-box">
            <h5><?php echo xlt('Denominator Criteria:'); ?></h5>
            <ol>
                <li><?php echo xlt('All patients, regardless of age'); ?></li>
                <li><?php echo xlt('Diagnosis of psoriasis vulgaris (ICD-10-CM: L40.0)'); ?></li>
                <li><?php echo xlt('Patient encounter during the performance period with qualifying CPT/HCPCS codes'); ?></li>
                <li><?php echo xlt('Qualifying encounter codes: 98000-98016, 99202-99205, 99212-99215, 99242-99245, 99341-99342, 99344-99345, 99347-99350, 99424, 99426, G0438, G0439'); ?></li>
            </ol>
        </div>

        <div class="warning-box">
            <h5><?php echo xlt('Manual Review Required'); ?></h5>
            <p><?php echo xlt('All patients in this denominator report require manual review to verify systemic medication treatment for psoriasis vulgaris. This corresponds to the G9764 criteria: "Patient has been treated with a systemic medication for psoriasis vulgaris."'); ?></p>
            <p><?php echo xlt('Review patient charts for documentation of systemic medications such as:'); ?></p>
            <ul>
                <li><?php echo xlt('Methotrexate, Cyclosporine, Acitretin, Apremilast'); ?></li>
                <li><?php echo xlt('Biologics: Adalimumab, Etanercept, Infliximab, Ustekinumab, Secukinumab, Ixekizumab, Guselkumab, Risankizumab, etc.'); ?></li>
            </ul>
        </div>

        <form method="post" action="" id="report_form">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">

            <div class="form-group">
                <button type="submit" name="export_csv" class="btn btn-primary btn-export">
                    <i class="fa fa-download"></i> <?php echo xlt('Export Denominator Report to CSV'); ?>
                </button>
            </div>
        </form>

        <div class="alert alert-info" role="alert">
            <?php echo xlt('Click "Export Denominator Report to CSV" to download the patient list for the 2025 performance period. The report will include all patients meeting the denominator criteria who require manual review for systemic medication treatment verification.'); ?>
        </div>
    </div>
</body>
</html>