<?php
/**
 * MIPS Quality Measures Report - 2026 Performance Year
 *
 * Measures included: 039
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Updated for 2026 MIPS specifications (Quality ID #039, v10.0, Dec 2025)
 * @copyright Copyright (c) 2026
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/forms.inc.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/options.inc.php");

// Check if CSV download is requested
$export_csv = isset($_GET['export']) && $_GET['export'] === 'csv';

set_time_limit(0);

// Set performance period dates for 2026
// Read from POST (Run Report) or GET (Export to CSV link) so the export uses the selected range
$req_from_date = $_POST['form_from_date'] ?? ($_GET['form_from_date'] ?? '');
$req_to_date   = $_POST['form_to_date'] ?? ($_GET['form_to_date'] ?? '');
$form_from_date = $req_from_date ? DateToYYYYMMDD($req_from_date) : '2026-01-01';
$form_to_date   = $req_to_date ? DateToYYYYMMDD($req_to_date) : '2026-12-31';

// Denominator encounter CPT codes - 2026 (unchanged from 2025)
$encounter_cpt_codes = array(
    '98000', '98001', '98002', '98003', '98004', '98005', '98006', '98007', '98008', '98009',
    '98010', '98011', '98012', '98013', '98014', '98015', '98016',
    '99202', '99203', '99204', '99205', '99212', '99213', '99214', '99215'
);

// 2026 Denominator Exclusion: Diagnosis of osteoporosis on date of encounter (M1153)
// Reference coding from the 2026 specification (stored without dots for matching)
$osteoporosis_icd10_codes = array_map(function ($c) {
    return str_replace('.', '', $c);
}, array(
    'M80.00XA', 'M80.00XD', 'M80.00XG', 'M80.00XK', 'M80.00XP', 'M80.00XS', 'M80.011A', 'M80.011D', 'M80.011G', 'M80.011K',
    'M80.011P', 'M80.011S', 'M80.012A', 'M80.012D', 'M80.012G', 'M80.012K', 'M80.012P', 'M80.012S', 'M80.019A', 'M80.019D',
    'M80.019G', 'M80.019K', 'M80.019P', 'M80.019S', 'M80.021A', 'M80.021D', 'M80.021G', 'M80.021K', 'M80.021P', 'M80.021S',
    'M80.022A', 'M80.022D', 'M80.022G', 'M80.022K', 'M80.022P', 'M80.022S', 'M80.029A', 'M80.029D', 'M80.029G', 'M80.029K',
    'M80.029P', 'M80.029S', 'M80.031A', 'M80.031D', 'M80.031G', 'M80.031K', 'M80.031P', 'M80.031S', 'M80.032A', 'M80.032D',
    'M80.032G', 'M80.032K', 'M80.032P', 'M80.032S', 'M80.039A', 'M80.039D', 'M80.039G', 'M80.039K', 'M80.039P', 'M80.039S',
    'M80.041A', 'M80.041D', 'M80.041G', 'M80.041K', 'M80.041P', 'M80.041S', 'M80.042A', 'M80.042D', 'M80.042G', 'M80.042K',
    'M80.042P', 'M80.042S', 'M80.049A', 'M80.049D', 'M80.049G', 'M80.049K', 'M80.049P', 'M80.049S', 'M80.051A', 'M80.051D',
    'M80.051G', 'M80.051K', 'M80.051P', 'M80.051S', 'M80.052A', 'M80.052D', 'M80.052G', 'M80.052K', 'M80.052P', 'M80.052S',
    'M80.059A', 'M80.059D', 'M80.059G', 'M80.059K', 'M80.059P', 'M80.059S', 'M80.061A', 'M80.061D', 'M80.061G', 'M80.061K',
    'M80.061P', 'M80.061S', 'M80.062A', 'M80.062D', 'M80.062G', 'M80.062K', 'M80.062P', 'M80.062S', 'M80.069A', 'M80.069D',
    'M80.069G', 'M80.069K', 'M80.069P', 'M80.069S', 'M80.071A', 'M80.071D', 'M80.071G', 'M80.071K', 'M80.071P', 'M80.071S',
    'M80.072A', 'M80.072D', 'M80.072G', 'M80.072K', 'M80.072P', 'M80.072S', 'M80.079A', 'M80.079D', 'M80.079G', 'M80.079K',
    'M80.079P', 'M80.079S', 'M80.08XA', 'M80.08XD', 'M80.08XG', 'M80.08XK', 'M80.08XP', 'M80.08XS', 'M80.0AXA', 'M80.0B1A',
    'M80.0B1D', 'M80.0B1G', 'M80.0B1K', 'M80.0B1P', 'M80.0B1S', 'M80.0B2A', 'M80.0B2D', 'M80.0B2G', 'M80.0B2K', 'M80.0B2P',
    'M80.0B2S', 'M80.0B9A', 'M80.0B9D', 'M80.0B9G', 'M80.0B9K', 'M80.0B9P', 'M80.0B9S', 'M80.80XA', 'M80.80XD', 'M80.80XG',
    'M80.80XK', 'M80.80XP', 'M80.80XS', 'M80.811A', 'M80.811D', 'M80.811G', 'M80.811K', 'M80.811P', 'M80.811S', 'M80.812A',
    'M80.812D', 'M80.812G', 'M80.812K', 'M80.812P', 'M80.812S', 'M80.819A', 'M80.819D', 'M80.819G', 'M80.819K', 'M80.819P',
    'M80.819S', 'M80.821A', 'M80.821D', 'M80.821G', 'M80.821K', 'M80.821P', 'M80.821S', 'M80.822A', 'M80.822D', 'M80.822G',
    'M80.822K', 'M80.822P', 'M80.822S', 'M80.829A', 'M80.829D', 'M80.829G', 'M80.829K', 'M80.829P', 'M80.829S', 'M80.831A',
    'M80.831D', 'M80.831G', 'M80.831K', 'M80.831P', 'M80.831S', 'M80.832A', 'M80.832D', 'M80.832G', 'M80.832K', 'M80.832P',
    'M80.832S', 'M80.839A', 'M80.839D', 'M80.839G', 'M80.839K', 'M80.839P', 'M80.839S', 'M80.841A', 'M80.841D', 'M80.841G',
    'M80.841K', 'M80.841P', 'M80.841S', 'M80.842A', 'M80.842D', 'M80.842G', 'M80.842K', 'M80.842P', 'M80.842S', 'M80.849A',
    'M80.849D', 'M80.849G', 'M80.849K', 'M80.849P', 'M80.849S', 'M80.851A', 'M80.851D', 'M80.851G', 'M80.851K', 'M80.851P',
    'M80.851S', 'M80.852A', 'M80.852D', 'M80.852G', 'M80.852K', 'M80.852P', 'M80.852S', 'M80.859A', 'M80.859D', 'M80.859G',
    'M80.859K', 'M80.859P', 'M80.859S', 'M80.861A', 'M80.861D', 'M80.861G', 'M80.861K', 'M80.861P', 'M80.861S', 'M80.862A',
    'M80.862D', 'M80.862G', 'M80.862K', 'M80.862P', 'M80.862S', 'M80.869A', 'M80.869D', 'M80.869G', 'M80.869K', 'M80.869P',
    'M80.869S', 'M80.871A', 'M80.871D', 'M80.871G', 'M80.871K', 'M80.871P', 'M80.871S', 'M80.872A', 'M80.872D', 'M80.872G',
    'M80.872K', 'M80.872P', 'M80.872S', 'M80.879A', 'M80.879D', 'M80.879G', 'M80.879K', 'M80.879P', 'M80.879S', 'M80.88XA',
    'M80.88XD', 'M80.88XG', 'M80.88XK', 'M80.88XP', 'M80.88XS', 'M80.8B1A', 'M80.8B1D', 'M80.8B1G', 'M80.8B1K', 'M80.8B1P',
    'M80.8B1S', 'M80.8B2A', 'M80.8B2D', 'M80.8B2G', 'M80.8B2K', 'M80.8B2P', 'M80.8B2S', 'M80.8B9A', 'M80.8B9D', 'M80.8B9G',
    'M80.8B9K', 'M80.8B9P', 'M80.8B9S', 'M81.0', 'M81.6', 'M81.8',
));

// 2026 Denominator Exclusion: Hospice services any time during the measurement period (G9690)
$hospice_codes = array('G9690');

// Normalize an ICD-10 code for comparison (uppercase, no dots/spaces)
function qm039_normalize_icd($code)
{
    return strtoupper(str_replace(array('.', ' '), '', trim($code)));
}

// Rank function for measure 039 (higher = more advantageous; patient-process measure)
function qm039_rank($numerator)
{
    switch ($numerator) {
        case 'G8399': return 3; // Performance Met
        case 'G9690': return 2; // Denominator Exclusion - hospice
        case 'M1153': return 2; // Denominator Exclusion - osteoporosis dx
        case 'G8400': return 1; // Performance Not Met
        default: return 0;
    }
}

// Cache of hospice lookups per patient (applies to the whole measurement period)
$hospice_cache = array();

// Measure definitions
$measures = array(
    '039' => array('name' => 'Osteoporosis Screening', 'applies_to' => 'female_65_85'),
);

$sqlBindArray = array();

$query = "SELECT " .
    "fe.encounter, fe.date, fe.reason, " .
    "f.formdir, f.form_name, " .
    "p.fname, p.mname, p.lname, p.pid, p.pubpid, p.dob, p.sex, " .
    "TIMESTAMPDIFF(YEAR, p.dob, fe.date) AS age, " .
    "u.lname AS ulname, u.fname AS ufname, u.mname AS umname " .
    "FROM (form_encounter AS fe, forms AS f) " .
    "LEFT OUTER JOIN patient_data AS p ON p.pid = fe.pid " .
    "LEFT JOIN users AS u ON u.id = fe.provider_id " .
    "WHERE f.pid = fe.pid AND f.encounter = fe.encounter AND f.formdir = 'newpatient' ";

if ($form_to_date) {
    $query .= "AND fe.date >= ? AND fe.date <= ? ";
    array_push($sqlBindArray, $form_from_date . ' 00:00:00', $form_to_date . ' 23:59:59');
}

$query .= "ORDER BY p.pid ASC";
$res = sqlStatement($query, $sqlBindArray);

$dd_report_rows = array();
$best_results = array();

if ($res) {
    while ($row = sqlFetchArray($res)) {

        $pid = $row['pid'];
        $mips_enc_date = substr($row['date'], 0, 10);

        // Check for qualifying encounter CPT code
        $bres = sqlStatement(
            "SELECT code FROM billing WHERE encounter = ? AND code_type LIKE 'CPT%'",
            array($row['encounter'])
        );

        $cpt_code = null;
        while ($brow = sqlFetchArray($bres)) {
            if (in_array($brow['code'], $encounter_cpt_codes, true)) {
                $cpt_code = $brow['code'];
                break;
            }
        }
        if (!$cpt_code) {
            continue;
        }

        // Get ALL ICD-10 codes for the encounter (for manual exception assessment)
        $icd10_codes = array();
        $dxres = sqlStatement(
            "SELECT code FROM billing 
             WHERE pid = ? 
               AND encounter = ? 
               AND code_type = 'ICD10'
             ORDER BY code",
            array($pid, $row['encounter'])
        );
        $osteo_dx_found = '';
        while ($dxrow = sqlFetchArray($dxres)) {
            $icd10_codes[] = $dxrow['code'];
            if (!$osteo_dx_found && in_array(qm039_normalize_icd($dxrow['code']), $osteoporosis_icd10_codes, true)) {
                $osteo_dx_found = $dxrow['code'];
            }
        }
        $icd10_all = implode(', ', $icd10_codes);

        // Most recent act_osteo entry in rule_patient_data (any date) - for display
        $rule_result = '';
        $rule_res = sqlStatement(
            "SELECT date, complete, result FROM rule_patient_data 
             WHERE pid = ? 
               AND item = 'act_osteo'
             ORDER BY date DESC, id DESC
             LIMIT 1",
            array($pid)
        );
        if ($rule_row = sqlFetchArray($rule_res)) {
            $rule_result = trim(
                ($rule_row['complete'] === 'YES' ? 'Complete' : ($rule_row['complete'] === 'NO' ? 'Not Complete' : '')) .
                ($rule_row['result'] !== '' && $rule_row['result'] !== null ? ' - ' . $rule_row['result'] : '') .
                ($rule_row['date'] ? ' (' . substr($rule_row['date'], 0, 10) . ')' : '')
            );
        }

        // Qualifying act_osteo entry: marked complete AND dated within the performance year
        $rule_met_date = '';
        $rule_met_res = sqlStatement(
            "SELECT date FROM rule_patient_data 
             WHERE pid = ? 
               AND item = 'act_osteo'
               AND complete = 'YES'
               AND date >= ? AND date <= ?
             ORDER BY date DESC, id DESC
             LIMIT 1",
            array($pid, $form_from_date . ' 00:00:00', $form_to_date . ' 23:59:59')
        );
        if ($rule_met_row = sqlFetchArray($rule_met_res)) {
            $rule_met_date = substr($rule_met_row['date'], 0, 10);
        }

        // Store patient base data
        $patient_base = array(
            'pid' => $pid,
            'patient_id' => $row['pubpid'],
            'first_name' => $row['fname'],
            'last_name' => $row['lname'],
            'dob' => $row['dob'],
            'sex' => $row['sex'],
            'age' => $row['age'],
            'dos' => $mips_enc_date,
            'cpt' => $cpt_code,
            'icd10' => $icd10_all,
            'rule_result' => $rule_result
        );

        // ========== MEASURE 039: Osteoporosis Screening for Women Aged 65–85 ==========

        $measure_result = null;
        $result_text = '';
        $has_dexa = false;
        $dexa_control_id = '';
        $dexa_date = '';

        // female 65-85
        if (
            in_array(strtoupper(trim($row['sex'])), array('F', 'FEMALE')) &&
            $row['age'] >= 65 && $row['age'] <= 85
        ) {

            // Look for BDENSE procedure order ever performed (through end of performance period)
            // - get the most recent one. Performance Met (BDENSE or completed act_osteo) is checked
            // first because it is the most advantageous result regardless of exclusions.
            $proc = sqlStatement(
                "SELECT control_id, date_transmitted 
                 FROM procedure_order 
                 WHERE patient_id = ? 
                   AND control_id LIKE '%BDENSE%'
                   AND (date_transmitted IS NULL OR date_transmitted <= ?)
                 ORDER BY date_transmitted DESC
                 LIMIT 1",
                array($pid, $form_to_date . ' 23:59:59')
            );

            if ($procrow = sqlFetchArray($proc)) {
                $has_dexa = true;
                $dexa_control_id = $procrow['control_id'];
                $dexa_date = $procrow['date_transmitted'] ? substr($procrow['date_transmitted'], 0, 10) : '';
            }

            // Denominator Exclusion: hospice services any time during the measurement period (G9690)
            if (!isset($hospice_cache[$pid])) {
                $hospice_cache[$pid] = '';
                $hres = sqlStatement(
                    "SELECT b.code, fe2.date
                     FROM billing AS b
                     JOIN form_encounter AS fe2 ON fe2.encounter = b.encounter AND fe2.pid = b.pid
                     WHERE b.pid = ?
                       AND b.activity = 1
                       AND b.code IN ('" . implode("','", $hospice_codes) . "')
                       AND fe2.date >= ? AND fe2.date <= ?
                     ORDER BY fe2.date DESC
                     LIMIT 1",
                    array($pid, $form_from_date . ' 00:00:00', $form_to_date . ' 23:59:59')
                );
                if ($hrow = sqlFetchArray($hres)) {
                    $hospice_cache[$pid] = $hrow['code'] . ' on ' . substr($hrow['date'], 0, 10);
                }
            }

            // Performance Met sources: BDENSE order and/or act_osteo marked complete in the performance year
            $met_via = array();
            $met_text = array();
            if ($has_dexa) {
                $met_via[] = 'BDENSE order';
                $met_text[] = 'DEXA found: ' . $dexa_control_id . ' on ' . $dexa_date;
            }
            if ($rule_met_date) {
                $met_via[] = 'Osteo Clinical Reminder complete';
                $met_text[] = 'Osteo Clinical Reminder marked complete on ' . $rule_met_date;
            }

            if (!empty($met_via)) {
                $measure_result = array(
                    'numerator' => 'G8399',
                    'modifier' => '',
                    'assessment_type' => 'DXA Screening (' . implode(' + ', $met_via) . ')',
                    'assessment_score' => 'Performance Met',
                    'exclusion' => '',
                    'result_text' => implode('; ', $met_text),
                    'dexa_control_id' => $dexa_control_id,
                    'dexa_date' => $dexa_date
                );
            } elseif ($hospice_cache[$pid]) {
                $measure_result = array(
                    'numerator' => 'G9690',
                    'modifier' => '',
                    'assessment_type' => 'Denominator Exclusion',
                    'assessment_score' => 'Excluded',
                    'exclusion' => 'Hospice services during measurement period (' . $hospice_cache[$pid] . ')',
                    'result_text' => 'Excluded - hospice (G9690); no DEXA procedure or completed act_osteo found',
                    'dexa_control_id' => '',
                    'dexa_date' => ''
                );
            } elseif ($osteo_dx_found) {
                $measure_result = array(
                    'numerator' => 'M1153',
                    'modifier' => '',
                    'assessment_type' => 'Denominator Exclusion',
                    'assessment_score' => 'Excluded',
                    'exclusion' => 'Osteoporosis diagnosis on date of encounter (' . $osteo_dx_found . ')',
                    'result_text' => 'Excluded - osteoporosis dx ' . $osteo_dx_found . ' on DOS; no DEXA procedure or Osteo Clinical Reminder completed in performance year',
                    'dexa_control_id' => '',
                    'dexa_date' => ''
                );
            } else {
                $measure_result = array(
                    'numerator' => 'G8400',
                    'modifier' => '',
                    'assessment_type' => 'DXA Screening',
                    'assessment_score' => 'Performance Not Met',
                    'exclusion' => '',
                    'result_text' => 'No DEXA BDENSE found and no Osteo Clinical Reminder completed in performance year',
                    'dexa_control_id' => '',
                    'dexa_date' => ''
                );
            }
        }

        // Ranking logic: store best result per patient
        if ($measure_result) {

            $rank = qm039_rank($measure_result['numerator']);

            if (!isset($best_results[$pid]) || $rank > $best_results[$pid]['rank']) {

                $best_results[$pid] = array(
                    'rank' => $rank,
                    'base' => $patient_base,
                    'measure' => $measure_result
                );
            }
        }
    }
}

// Step 7: Output rows (one per patient)
foreach ($best_results as $pid => $entry) {

    $base = $entry['base'];
    $m = $entry['measure'];

    $dd_report_rows[] = array(
        'pid' => $base['pid'],
        'PatientID' => $base['patient_id'],
        'First Name' => $base['first_name'],
        'Last Name' => $base['last_name'],
        'Date of Birth' => $base['dob'],
        'Sex' => $base['sex'],
        'Age' => $base['age'],
        'Date of Service' => $base['dos'],
        'Measure Number' => '039',
        'CPT' => $base['cpt'],
        'ICD10' => $base['icd10'],
        'Rule Result' => $base['rule_result'],
        'Numerator' => $m['numerator'],
        'Modifier' => $m['modifier'],
        'Assessment Type' => $m['assessment_type'],
        'Assessment Score' => $m['assessment_score'],
        'Exclusion' => $m['exclusion'],
        'DEXA Control ID' => $m['dexa_control_id'],
        'DEXA Date' => $m['dexa_date'],
        'Result' => $m['result_text']
    );
}

// ========== CSV Export ==========
if ($export_csv) {
    $filename = "MIPS_2026_QM039_Report_" . date('Y-m-d_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $output = fopen('php://output', 'w');

    // CSV header
    fputcsv($output, array(
        'PID',
        'Patient_ID',
        'First_Name',
        'Last_Name',
        'Date_of_Birth',
        'Sex',
        'Age',
        'Date_of_Service',
        'Measure_Number',
        'CPT',
        'ICD10_Codes',
        'Rule_Result',
        'Numerator',
        'Numerator_Modifier',
        'Assessment_Type',
        'Assessment_Score',
        'Denominator_Exclusion',
        'DEXA_Control_ID',
        'DEXA_Date',
        'Result'
    ));

    // CSV data rows
    foreach ($dd_report_rows as $row) {
        fputcsv($output, array(
            $row['pid'],
            $row['PatientID'],
            $row['First Name'],
            $row['Last Name'],
            $row['Date of Birth'],
            $row['Sex'],
            $row['Age'],
            $row['Date of Service'],
            $row['Measure Number'],
            $row['CPT'],
            $row['ICD10'],
            $row['Rule Result'],
            $row['Numerator'],
            $row['Modifier'],
            $row['Assessment Type'],
            $row['Assessment Score'],
            $row['Exclusion'],
            $row['DEXA Control ID'],
            $row['DEXA Date'],
            $row['Result']
        ));
    }

    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MIPS 2026 Quality Measure 039 Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f5f5f5;
        }
        .header {
            background-color: #2c5aa0;
            color: white;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .controls {
            background-color: white;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .button {
            background-color: #4CAF50;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 4px;
            display: inline-block;
            margin-right: 10px;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .button:hover {
            background-color: #45a049;
        }
        .button.secondary {
            background-color: #2196F3;
        }
        .button.secondary:hover {
            background-color: #0b7dda;
        }
        .stats {
            background-color: white;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        .stat-box {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #2c5aa0;
        }
        .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #2c5aa0;
            margin-top: 5px;
        }
        table {
            background-color: white;
            border-collapse: collapse;
            width: 100%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            font-size: 11px;
        }
        th {
            background-color: #2c5aa0;
            color: white;
            padding: 10px 6px;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 10;
            font-size: 11px;
        }
        td {
            padding: 8px 6px;
            border-bottom: 1px solid #ddd;
            vertical-align: top;
        }
        tr:hover {
            background-color: #f5f5f5;
        }
        .report-section {
            background-color: white;
            border-radius: 5px;
            padding: 10px 15px 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-top: 4px solid #2c5aa0;
        }
        .report-section.ineligible { border-top-color: #f9a825; }
        .report-section h3 { margin: 8px 0 4px; color: #2c5aa0; }
        .report-section.ineligible h3 { color: #a86f00; }
        .report-section.ineligible th { background-color: #a86f00; }
        .section-note { margin: 0 0 10px; font-size: 12px; color: #666; }
        .section-empty { text-align: center; color: #666; padding: 20px; }
        .row-eligible { background-color: #e3f2fd; }
        .row-ineligible { background-color: #fff8e1; }
        .pid-link { color: #2c5aa0; font-weight: bold; text-decoration: underline; cursor: pointer; }
        .pid-link:hover { color: #0b3d91; }
        .date-form {
            display: inline-block;
            margin-right: 20px;
        }
        .date-form label {
            font-weight: bold;
            margin-right: 5px;
        }
        .date-form input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-right: 10px;
        }
        .info-box {
            background-color: #e8f5e9;
            border: 1px solid #4caf50;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .info-box h4 {
            margin-top: 0;
            color: #2e7d32;
        }
        .info-box ul {
            margin: 10px 0;
            padding-left: 20px;
        }
    </style>
</head>
<body>

<div class="header">
    <h2>MIPS 2026 - Quality Measure 039: Screening for Osteoporosis for Women Aged 65-85 Years of Age</h2>
</div>

<div class="info-box">
    <h4>Report Requirements - QM 039</h4>
    <ul>
        <li><strong>Population:</strong> Female patients aged 65-85 at time of encounter</li>
        <li><strong>PerformancePeriod:</strong> (<?php echo oeFormatShortDate($form_from_date) . ' to ' . oeFormatShortDate($form_to_date); ?>)</li>
        <li><strong>Exclusions (2026):</strong> Osteoporosis diagnosis on the date of encounter (M1153 - M80.x/M81.0/M81.6/M81.8 per spec reference coding), or hospice services any time during the measurement period (G9690 billed on an encounter in the period)</li>
        <li><strong>ICD-10 Codes:</strong> All diagnosis codes shown for review</li>
        <li><strong>Performance Met:</strong> Either a BDENSE procedure order result (ever, through the end of the performance period) <em>or</em> an Osteoporosis Clincial Reminder marked complete and dated within the performance period</li>
        <li><strong>Most Recent:</strong> If multiple DEXA procedures exist, only the most recent is displayed</li>
    </ul>
</div>

<div class="controls">
    <form method="POST" action="" style="display: inline;">
        <div class="date-form">
            <label>From Date:</label>
            <input type="text" name="form_from_date" value="<?php echo oeFormatShortDate($form_from_date); ?>" 
                   class="datepicker" placeholder="YYYY-MM-DD">
        </div>
        <div class="date-form">
            <label>To Date:</label>
            <input type="text" name="form_to_date" value="<?php echo oeFormatShortDate($form_to_date); ?>" 
                   class="datepicker" placeholder="YYYY-MM-DD">
        </div>
        <button type="submit" class="button secondary">Run Report</button>
    </form>
    
    <a href="?export=csv&form_from_date=<?php echo urlencode(oeFormatShortDate($form_from_date)); ?>&form_to_date=<?php echo urlencode(oeFormatShortDate($form_to_date)); ?>" 
       class="button">
        Export to CSV
    </a>
</div>

<div class="stats">
    <h3>Report Summary</h3>
    <div class="stats-grid">
        <div class="stat-box">
            <div class="stat-label">Performance Period</div>
            <div class="stat-value" style="font-size: 16px;"><?php echo oeFormatShortDate($form_from_date) . ' to ' . oeFormatShortDate($form_to_date); ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Total Patients (Initial Population)</div>
            <div class="stat-value"><?php echo count($dd_report_rows); ?></div>
        </div>
        <?php
            $met = 0; $not_met = 0; $excluded = 0;
            foreach ($dd_report_rows as $row) {
                if ($row['Numerator'] === 'G8399') {
                    $met++;
                } elseif ($row['Numerator'] === 'G8400') {
                    $not_met++;
                } else {
                    $excluded++;
                }
            }
            $denominator = $met + $not_met;
            $perf_rate = $denominator > 0 ? round(($met / $denominator) * 100, 2) : 0;
        ?>
        <div class="stat-box">
            <div class="stat-label">Denominator Exclusions</div>
            <div class="stat-value"><?php echo $excluded; ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Eligible Denominator</div>
            <div class="stat-value"><?php echo $denominator; ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Performance Rate</div>
            <div class="stat-value"><?php echo $perf_rate; ?>%</div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Performance Met</div>
            <div class="stat-value"><?php echo $met; ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Performance Not Met</div>
            <div class="stat-value"><?php echo $not_met; ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Report Generated</div>
            <div class="stat-value" style="font-size: 16px;"><?php echo date('Y-m-d H:i:s'); ?></div>
        </div>
    </div>
</div>

<?php
// Split patients into eligible (denominator) and ineligible (denominator exclusion) sections
$eligible_rows = array();
$ineligible_rows = array();
foreach ($dd_report_rows as $row) {
    if ($row['Exclusion'] !== '') {
        $ineligible_rows[] = $row;
    } else {
        $eligible_rows[] = $row;
    }
}

// Columns shown in each section: header label => row key
$eligible_columns = array(
    'PID' => 'pid',
    'Patient ID' => 'PatientID',
    'First Name' => 'First Name',
    'Last Name' => 'Last Name',
    'DOB' => 'Date of Birth',
    'Sex' => 'Sex',
    'Age' => 'Age',
    'DOS' => 'Date of Service',
    'CPT' => 'CPT',
    'ICD10 Codes' => 'ICD10',
    'Rule Result' => 'Rule Result',
    'Numerator' => 'Numerator',
    'Assessment Score' => 'Assessment Score',
    'Met Via' => 'Assessment Type',
    'Result' => 'Result'
);
$ineligible_columns = array(
    'PID' => 'pid',
    'Patient ID' => 'PatientID',
    'First Name' => 'First Name',
    'Last Name' => 'Last Name',
    'DOB' => 'Date of Birth',
    'Sex' => 'Sex',
    'Age' => 'Age',
    'DOS' => 'Date of Service',
    'CPT' => 'CPT',
    'ICD10 Codes' => 'ICD10',
    'Rule Result' => 'Rule Result',
    'Exclusion Code' => 'Numerator',
    'Exclusion Reason' => 'Exclusion'
);

// Render one section's table
function qm039_render_table($rows, $columns, $row_class)
{
    ?>
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <?php foreach ($columns as $label => $key): ?>
                    <th><?php echo htmlspecialchars($label); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr class="<?php echo htmlspecialchars($row_class); ?>">
                    <?php foreach ($columns as $label => $key): ?>
                        <?php if ($key === 'pid'): ?>
                    <td><a href="#" class="pid-link" data-pid="<?php echo htmlspecialchars($row[$key]); ?>" title="Open patient dashboard"><?php echo htmlspecialchars($row[$key]); ?></a></td>
                        <?php elseif ($key === 'Numerator'): ?>
                    <td><strong><?php echo htmlspecialchars($row[$key]); ?></strong></td>
                        <?php elseif ($key === 'ICD10'): ?>
                    <td style="max-width: 200px; word-wrap: break-word;"><?php echo htmlspecialchars($row[$key]); ?></td>
                        <?php else: ?>
                    <td><?php echo htmlspecialchars($row[$key]); ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
?>

<?php if (count($dd_report_rows) > 0): ?>
<div class="report-section eligible">
    <h3>Eligible Patients (Denominator) &mdash; <?php echo count($eligible_rows); ?></h3>
    <p class="section-note">Women 65&ndash;85 with a qualifying encounter and no denominator exclusion. These patients count toward the performance rate.</p>
    <?php if (count($eligible_rows) > 0): ?>
        <?php qm039_render_table($eligible_rows, $eligible_columns, 'row-eligible'); ?>
    <?php else: ?>
        <p class="section-empty">No eligible patients for the selected date range.</p>
    <?php endif; ?>
</div>

<div class="report-section ineligible">
    <h3>Ineligible Patients (Denominator Exclusions) &mdash; <?php echo count($ineligible_rows); ?></h3>
    <p class="section-note">Patients removed from the denominator for an osteoporosis diagnosis on the date of encounter (M1153) or hospice services during the measurement period (G9690).</p>
    <?php if (count($ineligible_rows) > 0): ?>
        <?php qm039_render_table($ineligible_rows, $ineligible_columns, 'row-ineligible'); ?>
    <?php else: ?>
        <p class="section-empty">No excluded patients for the selected date range.</p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="stats">
    <p style="text-align: center; color: #666; padding: 40px;">
        No patients found matching the criteria for the selected date range.
    </p>
</div>
<?php endif; ?>

<div style="margin-top: 20px; padding: 15px; background-color: white; border-radius: 5px; font-size: 12px; color: #666;">
    <h4>Column Descriptions:</h4>
    <ul style="columns: 2; -webkit-columns: 2; -moz-columns: 2;">
        <li><strong>PID:</strong> Internal patient ID &mdash; click to open the patient dashboard</li>
        <li><strong>Patient ID:</strong> Medical record number</li>
        <li><strong>Age:</strong> Patient age at time of encounter</li>
        <li><strong>DOS:</strong> Date of service (encounter date)</li>
        <li><strong>CPT:</strong> Encounter CPT code</li>
        <li><strong>ICD10 Codes:</strong> All diagnosis codes billed on the encounter</li>
        <li><strong>Rule Result:</strong> Most recent act_osteo entry from rule_patient_data (status, result, date), any year</li>
        <li><strong>Met Via:</strong> Source that satisfied the measure &mdash; BDENSE order, act_osteo complete, or both</li>
        <li><strong>Numerator:</strong> G8399 (Performance Met) or G8400 (Performance Not Met) &mdash; eligible section</li>
        <li><strong>Exclusion Code:</strong> M1153 (osteoporosis dx) or G9690 (hospice) &mdash; ineligible section</li>
        <li><strong>Exclusion Reason:</strong> Reason and supporting code for the denominator exclusion</li>
        <li><strong>Result:</strong> How the measure was met or not met, including the BDENSE control ID and date and/or the act_osteo completion date</li>
    </ul>
    
    <h4 style="margin-top: 20px;">Quality Measure 039 Notes:</h4>
    <ul>
        <li>Patients must be female and between ages 65-85 at the time of the encounter</li>
        <li>Encounter must occur during the performance period</li>
        <li>DEXA procedure search looks for control_id containing 'BDENSE' at any time in patient history up to the end of the performance period</li>
        <li>An act_osteo entry counts as Performance Met only if it is marked complete (complete = YES) and dated within the performance period; use this for DXAs done outside the EMR</li>
        <li>If multiple DEXA procedures exist, only the most recent is shown</li>
        <li>Patients with osteoporosis (M80.x, M81.0, M81.6, M81.8 per 2026 reference coding) on the date of encounter are excluded (M1153)</li>
        <li>Patients with G9690 billed on any encounter during the measurement period are excluded (hospice)</li>
        <li>A patient with a DEXA on file is reported as Performance Met even if an exclusion also applies (most advantageous code)</li>
        <li>Performance Rate = Performance Met / (Performance Met + Performance Not Met)</li>
    </ul>
</div>

<script>
// Open the patient dashboard (sets the active patient) when a PID is clicked
(function () {
    var dashboardUrl = <?php echo json_encode($GLOBALS['webroot'] . '/interface/patient_file/summary/demographics.php?set_pid='); ?>;

    function openPatientDashboard(pid) {
        var url = dashboardUrl + encodeURIComponent(pid);
        if (typeof top.restoreSession === 'function') {
            top.restoreSession();
        }
        if (typeof top.navigateTab === 'function') {
            // Tabbed UI: load into the patient tab and switch to it
            top.navigateTab(url, 'pat', function () {
                if (typeof top.activateTabByName === 'function') {
                    top.activateTabByName('pat', true);
                }
            });
        } else if (top.RTop) {
            // Legacy frame layout
            top.RTop.location = url;
        } else {
            window.location.href = url;
        }
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('.pid-link') : null;
        if (!link) {
            return;
        }
        e.preventDefault();
        openPatientDashboard(link.getAttribute('data-pid'));
    });
})();
</script>

</body>
</html>