<?php
/**
 * MIPS Quality Measure 509: Melanoma Tracking and Evaluation of Recurrence
 * 
 * This report identifies patients who meet the denominator criteria for MIPS 509.
 * Users can manually document performance status in the report.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/options.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

// Check authorization
if (!AclMain::aclCheckCore('patients', 'med')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("MIPS 509 Report")]);
    exit;
}

if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
}

// Set page title
$report_title = xl("MIPS 509: Melanoma Tracking and Evaluation");

// Form parameters
// $form_from_date = (!empty($_POST['form_from_date'])) ? DateToYYYYMMDD($_POST['form_from_date']) : date('Y-01-01');
// $form_to_date = (!empty($_POST['form_to_date'])) ? DateToYYYYMMDD($_POST['form_to_date']) : date('Y-12-31');
$form_export = (!empty($_POST['form_export'])) ? true : false;
$form_from_date = '2025-10-01'; // Example fixed date range
$form_to_date = '2025-10-31';   // Example fixed date range
?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo $report_title; ?></title>
    <?php Header::setupHeader(['datetime-picker', 'report-helper']); ?>
    
    <style>
        .report-header {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .info-box {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .info-box h5 {
            color: #0c5460;
            margin-top: 0;
        }
        
        .info-box ul {
            margin-bottom: 0;
        }
        
        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 0.9em;
        }
        
        .results-table th {
            background-color: #007bff;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: bold;
            position: sticky;
            top: 0;
        }
        
        .results-table td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        
        .results-table tr:hover {
            background-color: #f5f5f5;
        }
        
        .summary-box {
            background-color: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin: 20px 0;
        }
        
        .summary-box h4 {
            margin-top: 0;
        }
        
        .stat-item {
            display: inline-block;
            margin-right: 30px;
            margin-bottom: 10px;
        }
        
        .stat-label {
            font-weight: bold;
            color: #666;
        }
        
        .stat-value {
            font-size: 1.3em;
            color: #007bff;
            font-weight: bold;
        }
        
        .patient-link {
            color: #007bff;
            text-decoration: none;
        }
        
        .patient-link:hover {
            text-decoration: underline;
        }
        
        .alert-warning {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
        }
        
        .help-text {
            font-size: 0.9em;
            color: #666;
            font-style: italic;
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
           
        
            <div class="info-box">
                <h5><?php echo xlt('Measure Requirements (Denominator Criteria)'); ?>:</h5>
                <ul>
                    <li><?php echo xlt('Patients aged 18 years and older'); ?></li>
                    <li><?php echo xlt('Had excisional surgery for melanoma or melanoma in situ in past 5 years'); ?></li>
                    <li><?php echo xlt('Initial AJCC staging of 0, I, or II'); ?></li>
                    <li><?php echo xlt('Office visit during performance period'); ?></li>
                    <li><?php echo xlt('NOT telehealth encounters'); ?></li>
                </ul>
                <p class="help-text">
                    <?php echo xlt('Note: This report identifies patients with melanoma diagnoses and qualifying encounters. You must verify surgery history and AJCC staging separately.'); ?>
                </p>
            </div>
            
            <div class="report-header">
                <form method='post' action='mips_509_report.php' id='theform'>
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                    
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="form_from_date"><?php echo xlt('From Date'); ?>:</label>
                            <input type='text' 
                                   class='form-control datepicker' 
                                   name='form_from_date' 
                                   id='form_from_date' 
                                   value='<?php echo attr(oeFormatShortDate($form_from_date)); ?>' />
                        </div>
                        
                        <div class="form-group col-md-4">
                            <label for="form_to_date"><?php echo xlt('To Date'); ?>:</label>
                            <input type='text' 
                                   class='form-control datepicker' 
                                   name='form_to_date' 
                                   id='form_to_date' 
                                   value='<?php echo attr(oeFormatShortDate($form_to_date)); ?>' />
                        </div>
                        
                        <div class="form-group col-md-4">
                            <label>&nbsp;</label><br>
                            <button type='submit' class='btn btn-primary' name='form_refresh' value='1'>
                                <?php echo xlt('Generate Report'); ?>
                            </button>
                            <button type='submit' class='btn btn-secondary' name='form_export' value='1'>
                                <?php echo xlt('Export to CSV'); ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>

<?php

if ($_POST['form_refresh'] || $_POST['form_export']) {
    
    // Build the main query - finds potential denominator patients
    $query = "SELECT DISTINCT
            pd.pid,
            pd.fname,
            pd.lname,
            pd.DOB,
            pd.phone_home,
            fe.encounter,
            fe.date as encounter_date,
            fe.reason as encounter_reason,
            TIMESTAMPDIFF(YEAR, pd.DOB, fe.date) as age_at_encounter,
            GROUP_CONCAT(DISTINCT SUBSTRING(l.diagnosis, 7) ORDER BY l.diagnosis SEPARATOR ', ') as diagnoses,
            (SELECT GROUP_CONCAT(DISTINCT b2.code SEPARATOR ', ')
             FROM billing b2 
             WHERE b2.pid = pd.pid 
             AND b2.encounter = fe.encounter 
             AND b2.code IN ('99202','99203','99204','99205','99211','99212','99213','99214','99215','99242','99243','99244','99245')
             AND b2.activity = 1) as encounter_cpt_codes,
            (SELECT COUNT(*)
             FROM form_vitals fv
             WHERE fv.pid = pd.pid
             AND fv.date BETWEEN ? AND ?) as vital_count,
            (SELECT MAX(date)
             FROM form_encounter fe2
             WHERE fe2.pid = pd.pid
             AND fe2.date < ?) as previous_encounter_date
        FROM patient_data pd
        INNER JOIN form_encounter fe ON pd.pid = fe.pid
        INNER JOIN billing b ON fe.pid = b.pid AND fe.encounter = b.encounter
        LEFT JOIN lists l ON pd.pid = l.pid 
            AND l.type = 'medical_problem'
            AND l.diagnosis LIKE 'ICD10:%'
            AND SUBSTRING(l.diagnosis, 7) IN (
                'C43.0', 'C43.10', 'C43.111', 'C43.112', 'C43.121', 'C43.122',
                'C43.20', 'C43.21', 'C43.22', 'C43.30', 'C43.31', 'C43.39',
                'C43.4', 'C43.51', 'C43.52', 'C43.59', 'C43.60', 'C43.61',
                'C43.62', 'C43.70', 'C43.71', 'C43.72', 'C43.8', 'C43.9',
                'D03.0', 'D03.10', 'D03.111', 'D03.112', 'D03.20', 'D03.121',
                'D03.122', 'D03.30', 'D03.39', 'D03.4', 'D03.51', 'D03.52',
                'D03.59', 'D03.60', 'D03.61', 'D03.62', 'D03.70', 'D03.71',
                'D03.72', 'D03.8', 'D03.9'
            )
        WHERE 
            TIMESTAMPDIFF(YEAR, pd.DOB, fe.date) >= 18
            AND fe.date BETWEEN ? AND ?
            AND b.code IN (
                '99202', '99203', '99204', '99205',
                '99211', '99212', '99213', '99214', '99215',
                '99242', '99243', '99244', '99245'
            )
            AND b.activity = 1
            AND fe.encounter NOT IN (
                SELECT b2.encounter
                FROM billing b2
                WHERE b2.pid = fe.pid 
                AND b2.encounter = fe.encounter
                AND b2.modifier IN ('GQ', 'GT')
            )
        GROUP BY pd.pid, fe.encounter, fe.date
        HAVING COUNT(DISTINCT l.diagnosis) > 0
        ORDER BY pd.lname, pd.fname, fe.date DESC";
    
    // Prepare parameters
    $params = [
        $form_from_date, $form_to_date,  // vital_count dates
        $form_from_date,                  // previous_encounter_date cutoff
        $form_from_date, $form_to_date   // Encounter dates
    ];
    
    $res = sqlStatement($query, $params);
    
    // Collect results
    $results = [];
    $unique_patients = [];
    
    while ($row = sqlFetchArray($res)) {
        $results[] = $row;
        $unique_patients[$row['pid']] = true;
    }
    
    $total_encounters = count($results);
    $total_unique_patients = count($unique_patients);
    
    // Export to CSV if requested
    if ($form_export) {
        header("Content-Type: text/csv");
        header("Content-Disposition: attachment; filename=mips_509_report_" . date('Y-m-d') . ".csv");
        
        $output = fopen('php://output', 'w');
        
        // CSV Headers
        fputcsv($output, [
            'Patient ID',
            'Last Name',
            'First Name',
            'DOB',
            'Age at Encounter',
            'Phone',
            'Encounter Date',
            'Encounter CPT Codes',
            'Melanoma Diagnoses (ICD-10)',
            'Encounter Reason',
            'Previous Encounter',
            'Notes'
        ]);
        
        // CSV Data
        foreach ($results as $row) {
            fputcsv($output, [
                $row['pid'],
                $row['lname'],
                $row['fname'],
                $row['DOB'],
                $row['age_at_encounter'],
                $row['phone_home'],
                $row['encounter_date'],
                $row['encounter_cpt_codes'],
                $row['diagnoses'],
                $row['encounter_reason'],
                $row['previous_encounter_date'] ? $row['previous_encounter_date'] : 'None',
                'Verify: Surgery history in past 5 years, AJCC staging 0/I/II, exam for recurrence performed'
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    // Display results in HTML
    ?>
    
    <div class="summary-box">
        <h4><?php echo xlt('Report Summary'); ?></h4>
        <div class="stat-item">
            <span class="stat-label"><?php echo xlt('Date Range'); ?>:</span>
            <span class="stat-value"><?php echo text(oeFormatShortDate($form_from_date)) . ' - ' . text(oeFormatShortDate($form_to_date)); ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-label"><?php echo xlt('Total Encounters'); ?>:</span>
            <span class="stat-value"><?php echo text($total_encounters); ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-label"><?php echo xlt('Unique Patients'); ?>:</span>
            <span class="stat-value"><?php echo text($total_unique_patients); ?></span>
        </div>
    </div>
    
    <div class="alert-warning">
        <strong><?php echo xlt('Action Required'); ?>:</strong> 
        <?php echo xlt('For each patient listed, verify the following in their chart'); ?>:
        <ol style="margin-top: 10px; margin-bottom: 0;">
            <li><?php echo xlt('Had excisional surgery for melanoma in the past 5 years (from start of performance period)'); ?></li>
            <li><?php echo xlt('Initial AJCC staging was 0, I, or II'); ?></li>
            <li><?php echo xlt('Exam for recurrence was performed during this encounter OR documented by surgeon'); ?></li>
        </ol>
    </div>
    
    <?php if ($total_encounters > 0) { ?>
    
    <div style="overflow-x: auto;">
    <table class="results-table table table-striped">
        <thead>
            <tr>
                <th><?php echo xlt('Patient ID'); ?></th>
                <th><?php echo xlt('Patient Name'); ?></th>
                <th><?php echo xlt('DOB'); ?></th>
                <th><?php echo xlt('Age'); ?></th>
                <th><?php echo xlt('Phone'); ?></th>
                <th><?php echo xlt('Encounter Date'); ?></th>
                <th><?php echo xlt('CPT Code(s)'); ?></th>
                <th><?php echo xlt('Melanoma Dx Codes'); ?></th>
                <th><?php echo xlt('Reason'); ?></th>
                <th><?php echo xlt('Previous Visit'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($results as $row) { ?>
            <tr>
                <td>
                    <a href="../interface/patient_file/summary/demographics.php?set_pid=<?php echo attr_url($row['pid']); ?>" 
                       class="patient-link"
                       target="_blank">
                        <?php echo text($row['pid']); ?>
                    </a>
                </td>
                <td>
                    <a href="../interface/patient_file/summary/demographics.php?set_pid=<?php echo attr_url($row['pid']); ?>" 
                       class="patient-link"
                       target="_blank">
                        <?php echo text($row['lname'] . ', ' . $row['fname']); ?>
                    </a>
                </td>
                <td><?php echo text(oeFormatShortDate($row['DOB'])); ?></td>
                <td><?php echo text($row['age_at_encounter']); ?></td>
                <td><?php echo text($row['phone_home']); ?></td>
                <td>
                    <a href="../interface/patient_file/encounter/encounter_top.php?set_encounter=<?php echo attr_url($row['encounter']); ?>&pid=<?php echo attr_url($row['pid']); ?>" 
                       class="patient-link"
                       target="_blank">
                        <?php echo text(oeFormatShortDate($row['encounter_date'])); ?>
                    </a>
                </td>
                <td><?php echo text($row['encounter_cpt_codes']); ?></td>
                <td style="font-size: 0.85em;"><?php echo text($row['diagnoses']); ?></td>
                <td style="font-size: 0.85em;"><?php echo text(substr($row['encounter_reason'], 0, 50)) . (strlen($row['encounter_reason']) > 50 ? '...' : ''); ?></td>
                <td><?php echo $row['previous_encounter_date'] ? text(oeFormatShortDate($row['previous_encounter_date'])) : xlt('None'); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    </div>
    
    <div style="margin-top: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 5px;">
        <h5><?php echo xlt('Next Steps for MIPS Submission'); ?>:</h5>
        <ol>
            <li><?php echo xlt('Review each patient chart to verify denominator criteria'); ?></li>
            <li><?php echo xlt('Document whether exam for recurrence was performed'); ?></li>
            <li><?php echo xlt('Track numerator separately using one of these methods'); ?>:
                <ul>
                    <li><?php echo xlt('Use the exported CSV and add a column for "Exam Performed" (Yes/No/Exception)'); ?></li>
                    <li><?php echo xlt('Create a spreadsheet tracker with patient IDs and performance status'); ?></li>
                    <li><?php echo xlt('Use your EHR reporting module if available'); ?></li>
                </ul>
            </li>
            <li><?php echo xlt('Calculate performance rate: (Patients with exam performed) / (Total eligible - exceptions)'); ?></li>
        </ol>
    </div>
    
    <?php } else { ?>
        <div class="alert alert-info">
            <?php echo xlt('No patients found with melanoma diagnoses and qualifying encounters for the selected date range.'); ?>
        </div>
    <?php } ?>
    
<?php } // End if form submitted ?>

        </div>
    </div>
</div>

<script>
$(function() {
    // Initialize date pickers
    $('.datepicker').datetimepicker({
        <?php $datetimepicker_timepicker = false; ?>
        <?php $datetimepicker_showseconds = false; ?>
        <?php $datetimepicker_formatInput = true; ?>
        <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
    });
});
</script>

</body>
</html>