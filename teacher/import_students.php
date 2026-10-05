<?php
// ============================================================
//  teacher/import_students.php
//  Bulk-enroll students from a CSV or XLSX file — the teacher-side
//  equivalent of the old admin import. Same create-or-attach model
//  as the "Add Student" form on students.php:
//    - New Student ID  -> full account created (students + users),
//      password auto-generated (last name + last 4 ID digits), then
//      added to the chosen section.
//    - Existing Student ID -> identity untouched, just attached to
//      the chosen section (and backfilled into any subjects already
//      created for it).
//  Sections named in the file that don't exist yet are auto-created
//  as one of THIS teacher's own sections.
//
//  XLSX support is hand-rolled with ZipArchive + SimpleXML (both
//  ship with standard PHP) — an .xlsx is just a zip of XML, so the
//  first worksheet is read directly. Covers plain data cells; does
//  not evaluate formulas or handle multiple sheets.
// ============================================================
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';
require_once __DIR__ . '/../includes/sync_to_tooltrack.php';
require_once __DIR__ . '/../includes/sync_to_guidance.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$teacher_id = (int)$_SESSION['user_id'];

const IMPORT_STUDENT_COURSES = ['BSIT','LAED','BSBA','BSN','FPST','BSA'];

// Same generator used on students.php — duplicated here since this
// page doesn't include that file.
function import_generate_default_password(string $last_name, string $student_id): string {
    $clean = preg_replace('/[^A-Za-z]/', '', $last_name);
    if ($clean === '') $clean = 'Student';
    $clean = ucfirst(strtolower($clean));
    $digits = preg_replace('/\D/', '', $student_id);
    $suffix = substr($digits, -4);
    if ($suffix === '') $suffix = '0000';
    return $clean . $suffix;
}

// ── My own sections, for display + "Section" dropdown in the help card ──
$my_sections_q = $conn->prepare("SELECT id, section_name FROM sections WHERE teacher_id = ? ORDER BY section_name ASC");
$my_sections_q->bind_param("i", $teacher_id);
$my_sections_q->execute();
$my_sections_list = $my_sections_q->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Download a blank CSV template ──────────────────────────────
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['student_id','last_name','first_name','middle_name','email','contact_number','gender','course','section']);
    fputcsv($out, ['2023-00123','Dela Cruz','Juan','P','juan.delacruz@example.com','09171234567','Male','BSIT','BSIT 3A']);
    fclose($out);
    exit;
}

// ── Header aliases: normalized header text -> our field name ───
$HEADER_ALIASES = [
    'studentid'      => 'student_id',
    'idnumber'       => 'student_id',
    'id'             => 'student_id',
    'lastname'       => 'last_name',
    'surname'        => 'last_name',
    'firstname'      => 'first_name',
    'givenname'      => 'first_name',
    'middlename'     => 'middle_name',
    'middleinitial'  => 'middle_name',
    'mi'             => 'middle_name',
    'email'          => 'email',
    'emailaddress'   => 'email',
    'contactnumber'  => 'contact_number',
    'contact'        => 'contact_number',
    'phone'          => 'contact_number',
    'phonenumber'    => 'contact_number',
    'gender'         => 'gender',
    'sex'            => 'gender',
    'course'         => 'course',
    'program'        => 'course',
    'section'        => 'section',
    'sectionname'    => 'section',
];
function normalize_header($h) {
    return strtolower(preg_replace('/[\s_\-]+/', '', trim((string)$h)));
}

function colref_to_index($ref) {
    preg_match('/^([A-Z]+)/', $ref, $m);
    $letters = $m[1] ?? 'A';
    $col = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $col = $col * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $col - 1;
}

function read_csv_rows($path) {
    $rows = [];
    $handle = fopen($path, 'r');
    if (!$handle) throw new Exception("Could not read the uploaded file.");
    $line_num = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $line_num++;
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
        if ($line_num === 1) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
        }
        $rows[$line_num] = $row;
    }
    fclose($handle);
    if (empty($rows)) throw new Exception("The file appears to be empty.");
    return $rows;
}

function read_xlsx_rows($path) {
    if (!class_exists('ZipArchive')) {
        throw new Exception("The server's PHP is missing the Zip extension needed to read .xlsx files. Enable php_zip in php.ini, or upload a .csv instead.");
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new Exception("Could not open the .xlsx file — it may be corrupted or not a real Excel file.");
    }

    $sheet_path = 'xl/worksheets/sheet1.xml';
    $workbook_xml = $zip->getFromName('xl/workbook.xml');
    $rels_xml     = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbook_xml !== false && $rels_xml !== false) {
        libxml_use_internal_errors(true);
        $wb   = simplexml_load_string($workbook_xml);
        $rels = simplexml_load_string($rels_xml);
        if ($wb && $rels && isset($wb->sheets->sheet[0])) {
            $ns  = $wb->sheets->sheet[0]->attributes('r', true);
            $rid = (string)$ns['id'];
            foreach ($rels->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $target = ltrim((string)$rel['Target'], '/');
                    $sheet_path = strpos($target, 'worksheets/') === 0 ? 'xl/' . $target : $target;
                    break;
                }
            }
        }
    }

    $sheet_xml = $zip->getFromName($sheet_path);
    if ($sheet_xml === false) {
        throw new Exception("Could not find a worksheet inside the .xlsx file.");
    }

    $shared = [];
    $shared_xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($shared_xml !== false) {
        $sst = simplexml_load_string($shared_xml);
        if ($sst) {
            foreach ($sst->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $run) { $text .= (string)$run->t; }
                    $shared[] = $text;
                }
            }
        }
    }

    $sheet = simplexml_load_string($sheet_xml);
    $zip->close();
    if (!$sheet || !isset($sheet->sheetData)) {
        throw new Exception("Could not parse the worksheet — the file may not be a valid .xlsx.");
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row_xml) {
        $row_num = isset($row_xml['r']) ? (int)$row_xml['r'] : (count($rows) + 1);
        $row_out = [];
        $max_col = -1;
        foreach ($row_xml->c as $c) {
            $ref = (string)$c['r'];
            $col_idx = $ref !== '' ? colref_to_index($ref) : (count($row_out));
            $type = (string)$c['t'];
            if ($type === 's') {
                $i = (int)$c->v;
                $val = $shared[$i] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = isset($c->is->t) ? (string)$c->is->t : '';
            } else {
                $val = (string)$c->v;
            }
            $row_out[$col_idx] = $val;
            if ($col_idx > $max_col) $max_col = $col_idx;
        }
        $padded = [];
        for ($i = 0; $i <= $max_col; $i++) $padded[$i] = $row_out[$i] ?? '';
        if ($max_col >= 0) $rows[$row_num] = $padded;
    }
    if (empty($rows)) throw new Exception("The worksheet appears to be empty.");
    return $rows;
}

$results       = null;
$section_cache = [];

// ── Look up (or auto-create) a section, scoped to THIS teacher ──
function resolve_section($conn, $teacher_id, $name, &$section_cache, &$was_created) {
    $was_created = false;
    $key = strtolower($name);
    if (isset($section_cache[$key])) return $section_cache[$key];

    $chk = $conn->prepare("SELECT id FROM sections WHERE section_name = ? AND teacher_id = ? LIMIT 1");
    $chk->bind_param('si', $name, $teacher_id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    if ($row) {
        $section_cache[$key] = (int)$row['id'];
        return $section_cache[$key];
    }

    $ins = $conn->prepare(
        "INSERT INTO sections (section_name, description, course, year_level, school_year, teacher_id)
         VALUES (?, '', '', 1, '', ?)"
    );
    $ins->bind_param('si', $name, $teacher_id);
    $ins->execute();
    $section_cache[$key] = $conn->insert_id;
    $was_created = true;
    return $section_cache[$key];
}

function import_contact_error(string $c): string {
    if ($c === '') return '';
    $digits = strlen(preg_replace('/\D/', '', $c));
    if (strlen($c) > 20 || !preg_match('/^\+?[0-9\s\-()]+$/', $c) || $digits < 7 || $digits > 15) {
        return "contact number must be 7-15 digits";
    }
    return '';
}

// ── Handle uploaded file (CSV or XLSX) ──────────────────────────
if (isset($_POST['import_csv'])) {
    $results = ['created' => [], 'attached' => [], 'skipped' => [], 'errors' => [], 'sections_created' => []];

    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $results['fatal'] = "No file uploaded, or the upload failed. Please choose a .csv or .xlsx file.";
    } else {
        $tmp_path  = $_FILES['import_file']['tmp_name'];
        $orig_name = $_FILES['import_file']['name'];
        $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            $results['fatal'] = "Please upload a .csv or .xlsx file.";
        } else {
            try {
                $all_rows = ($ext === 'xlsx') ? read_xlsx_rows($tmp_path) : read_csv_rows($tmp_path);
            } catch (Exception $e) {
                $results['fatal'] = $e->getMessage();
                $all_rows = null;
            }

            if ($all_rows !== null) {
                $row_keys   = array_keys($all_rows);
                $header_key = array_shift($row_keys);
                $header_row = $all_rows[$header_key];

                $col_map = [];
                foreach ($header_row as $i => $h) {
                    $norm = normalize_header($h);
                    $col_map[$i] = $HEADER_ALIASES[$norm] ?? null;
                }
                $required     = ['student_id', 'last_name', 'first_name', 'course', 'section'];
                $missing_cols = array_diff($required, array_filter($col_map));

                if (!empty($missing_cols)) {
                    $results['fatal'] = "Your file is missing required column(s): " . implode(', ', $missing_cols)
                        . ". Download the template below for the expected headers.";
                } else {
                    $seen_ids = [];

                    foreach ($row_keys as $row_num) {
                        $row = $all_rows[$row_num];

                        $data = ['student_id'=>'','last_name'=>'','first_name'=>'','middle_name'=>'',
                                 'email'=>'','contact_number'=>'','gender'=>'','course'=>'','section'=>''];
                        foreach ($col_map as $i => $field) {
                            if ($field !== null && isset($row[$i])) {
                                $data[$field] = trim((string)$row[$i]);
                            }
                        }

                        $student_id     = $data['student_id'];
                        $last_name      = $data['last_name'];
                        $first_name     = $data['first_name'];
                        $middle_initial = $data['middle_name'];
                        $email          = $data['email'];
                        $contact        = $data['contact_number'];
                        $gender         = $data['gender'];
                        $course         = $data['course'];
                        $section_name   = $data['section'];
                        $username       = $student_id;

                        $label = "Row {$row_num} ({$last_name}, {$first_name})";

                        if ($student_id === '' || $last_name === '' || $first_name === '') {
                            $results['errors'][] = "$label: missing a required field (ID, last name, or first name).";
                            continue;
                        }
                        if ($course === '') {
                            $results['errors'][] = "$label: course is required.";
                            continue;
                        }
                        if (!in_array($course, IMPORT_STUDENT_COURSES, true)) {
                            $results['errors'][] = "$label: \"$course\" is not a recognized course — use one of " . implode(', ', IMPORT_STUDENT_COURSES) . ".";
                            continue;
                        }
                        if ($section_name === '') {
                            $results['errors'][] = "$label: section is required.";
                            continue;
                        }
                        if (($cerr = import_contact_error($contact)) !== '') {
                            $results['errors'][] = "$label: $cerr.";
                            continue;
                        }
                        if ($gender !== '' && !in_array(ucfirst(strtolower($gender)), ['Male','Female'], true)) {
                            $results['errors'][] = "$label: gender must be Male or Female (or left blank).";
                            continue;
                        }
                        $gender = $gender !== '' ? ucfirst(strtolower($gender)) : '';
                        if (isset($seen_ids[$student_id])) {
                            $results['skipped'][] = "$label: duplicate student ID elsewhere in this file.";
                            continue;
                        }

                        try {
                            $existing = $conn->prepare("SELECT * FROM students WHERE student_id = ? LIMIT 1");
                            $existing->bind_param('s', $student_id);
                            $existing->execute();
                            $found = $existing->get_result()->fetch_assoc();

                            $section_id = null;
                            $section_note = '';
                            if ($section_name !== '') {
                                $was_created = false;
                                $section_id = resolve_section($conn, $teacher_id, $section_name, $section_cache, $was_created);
                                if ($was_created) {
                                    $results['sections_created'][] = $section_name;
                                    $section_note = " — new section \"$section_name\" created";
                                } else {
                                    $section_note = " — added to \"$section_name\"";
                                }
                            }

                            if ($found) {
                                // ── ATTACH: existing student — never touch identity fields.
                                if ($section_id) {
                                    $chk = $conn->prepare("SELECT id FROM section_students WHERE section_id=? AND student_id=? LIMIT 1");
                                    $chk->bind_param("is", $section_id, $student_id);
                                    $chk->execute();
                                    if ($chk->get_result()->fetch_assoc()) {
                                        $results['skipped'][] = "$label: already in that section.";
                                        continue;
                                    }
                                    $ins3 = $conn->prepare("INSERT INTO section_students (section_id, student_id) VALUES (?, ?)");
                                    $ins3->bind_param("is", $section_id, $student_id);
                                    $ins3->execute();
                                    backfillSubjectEnrollmentsForSection($conn, $section_id, $student_id);
                                    if (function_exists('auto_enroll_student_in_fpst_subjects')) {
                                        auto_enroll_student_in_fpst_subjects($conn, $section_id, $student_id);
                                    }
                                    if (function_exists('push_all_fpst_subjects_for_section')) {
                                        push_all_fpst_subjects_for_section($conn, $section_id);
                                    }
                                    $results['attached'][] = "$label: attached existing student (" . htmlspecialchars($found['last_name'] . ', ' . $found['first_name']) . "){$section_note}.";
                                } else {
                                    $results['skipped'][] = "$label: student already exists and no section was given — nothing to do.";
                                }
                                continue;
                            }

                            // ── CREATE: brand-new Student ID.
                            $clash = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                            $clash->bind_param("s", $username);
                            $clash->execute();
                            if ($clash->get_result()->fetch_assoc()) {
                                $results['errors'][] = "$label: that Student ID is already used as a login by another account.";
                                continue;
                            }

                            $password   = import_generate_default_password($last_name, $student_id);
                            $hashed     = password_hash($password, PASSWORD_DEFAULT);
                            $contact_db = $contact !== '' ? $contact : null;
                            $gender_db  = $gender  !== '' ? $gender  : null;

                            $conn->begin_transaction();
                            $ins = $conn->prepare(
                                "INSERT INTO students
                                    (student_id,last_name,first_name,middle_initial,email,contact_number,gender,course,username,password,created_by)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                            );
                            $ins->bind_param("ssssssssssi",
                                $student_id,$last_name,$first_name,$middle_initial,$email,$contact_db,$gender_db,$course,$username,$hashed,$teacher_id
                            );
                            $ins->execute();
                            if ($ins->affected_rows < 1) throw new Exception("insert into students affected 0 rows");

                            $ins2 = $conn->prepare("INSERT INTO users (username,password,role,student_id) VALUES (?,?,'student',?)");
                            $ins2->bind_param("sss", $username, $hashed, $student_id);
                            $ins2->execute();
                            if ($ins2->affected_rows < 1) throw new Exception("insert into users affected 0 rows");

                            if ($section_id) {
                                $ins3 = $conn->prepare("INSERT INTO section_students (section_id, student_id) VALUES (?, ?)");
                                $ins3->bind_param("is", $section_id, $student_id);
                                $ins3->execute();
                                if ($ins3->affected_rows < 1) throw new Exception("insert into section_students affected 0 rows");
                            }

                            $conn->commit();
                            $seen_ids[$student_id] = true;

                            if ($section_id) {
                                backfillSubjectEnrollmentsForSection($conn, $section_id, $student_id);
                                if (function_exists('auto_enroll_student_in_fpst_subjects')) {
                                    auto_enroll_student_in_fpst_subjects($conn, $section_id, $student_id);
                                }
                                if (function_exists('push_all_fpst_subjects_for_section')) {
                                    push_all_fpst_subjects_for_section($conn, $section_id);
                                }
                            }
                            if (function_exists('push_student_to_guidance')) {
                                push_student_to_guidance($conn, $student_id);
                            }

                            $results['created'][] = "$label: created as <code>" . htmlspecialchars($username)
                                . "</code>, password <code>" . htmlspecialchars($password) . "</code>{$section_note}.";
                        } catch (Throwable $e) {
                            $conn->rollback();
                            $results['errors'][] = "$label: database error — " . htmlspecialchars($e->getMessage());
                        }
                    }
                }
            }
        }
    }
}

$active_nav = 'students';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Import Students — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-teacher-import">
<div class="app-shell">

<?php $active_nav = 'students'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-file-import text-accent"></i> Import Students</h1>
    <p>Bulk-enroll students into your own sections from a CSV or Excel file instead of adding them one at a time.</p>
  </div>
  <hr class="thin-line" style="margin-bottom: 25px;">

  <?php if (empty($my_sections_list)): ?>
  <div class="alert alert-error" style="margin-bottom:20px;">
    <i class="ti ti-alert-circle"></i>
    <div>You don't have any sections yet. <a href="manage_sections.php" class="text-accent">Create one</a> first — or list a brand-new section name in the file's "section" column and it will be created automatically.</div>
  </div>
  <?php endif; ?>

  <div class="two-col">
    <div>
      <div class="card">
        <p class="card-title"><i class="ti ti-upload"></i> Upload File</p>
        <p style="font-size:12px;color:var(--text7);margin-top:-6px;margin-bottom:14px;">
          Accepts <code>.csv</code> or <code>.xlsx</code>. Required columns: <code>student_id, last_name, first_name, course, section</code>.
          Optional: <code>middle_name, email, contact_number, gender</code>. Column order doesn't matter, and
          a few common header spellings are recognized automatically.
        </p>
        <p style="font-size:12px;color:var(--text7);margin-top:0;margin-bottom:14px;">
          A Student ID that already exists in the system is just added to the section you list — its name/details are
          never overwritten. A new Student ID gets a full account with an auto-generated password (last name + last
          4 digits of the ID). A section name that doesn't exist yet becomes one of <em>your</em> sections automatically.
        </p>
        <p style="font-size:12px;color:var(--text7);margin-top:0;margin-bottom:14px;">
          <i class="ti ti-alert-triangle"></i> If your Student IDs are pure numbers, format that column as <b>Text</b>
          in Excel before typing — otherwise Excel silently drops leading zeros.
        </p>
        <form method="POST" enctype="multipart/form-data">
          <div class="form-group">
            <label>File <span class="text-red">*</span></label>
            <input type="file" name="import_file" class="form-control"
                   accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="submit" name="import_csv" class="btn btn-primary">
              <i class="ti ti-file-import"></i> Import Students
            </button>
            <a href="import_students.php?template=1" class="btn btn-outline">
              <i class="ti ti-download"></i> Download Template
            </a>
            <a href="students.php" class="btn btn-outline">Back to Students</a>
          </div>
        </form>
      </div>
    </div>

    <div>
      <?php if ($results === null): ?>
        <div class="card">
          <p class="card-title"><i class="ti ti-info-circle"></i> How it works</p>
          <ul style="font-size:13px;color:var(--text7);line-height:1.9;padding-left:18px;margin:0;">
            <li>Each row is processed the same way as the "Add Student" form — create-or-attach by Student ID.</li>
            <li>Rows missing required fields, or with an invalid contact number/gender/course, are reported as errors — the rest of the file still imports.</li>
            <li>A duplicate Student ID within the file is skipped after the first occurrence.</li>
            <li>Section names that don't exist yet are created as your own section and reused for later rows with the same name.</li>
          </ul>
        </div>
      <?php else: ?>
        <div class="card">
          <p class="card-title"><i class="ti ti-report"></i> Import Results</p>

          <?php if (!empty($results['fatal'])): ?>
            <div class="alert alert-error"><i class="ti ti-alert-circle"></i><div><?php echo htmlspecialchars($results['fatal']); ?></div></div>
          <?php else: ?>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
              <span class="badge badge-green"><?php echo count($results['created']); ?> created</span>
              <span class="badge badge-blue"><?php echo count($results['attached']); ?> attached</span>
              <span style="font-size:12px;font-weight:600;padding:4px 10px;border-radius:99px;background:rgba(234,179,8,.12);color:var(--yellow);border:1px solid rgba(234,179,8,.25);">
                <?php echo count($results['skipped']); ?> skipped
              </span>
              <span style="font-size:12px;font-weight:600;padding:4px 10px;border-radius:99px;background:rgba(239,68,68,.12);color:var(--red);border:1px solid rgba(239,68,68,.25);">
                <?php echo count($results['errors']); ?> errors
              </span>
              <?php if (!empty($results['sections_created'])): ?>
              <span class="badge badge-gray"><?php echo count(array_unique($results['sections_created'])); ?> new section(s)</span>
              <?php endif; ?>
            </div>

            <?php if (!empty($results['created'])): ?>
              <p style="font-size:12px;font-weight:600;color:var(--text7);margin-bottom:6px;">CREATED</p>
              <ul style="font-size:12.5px;line-height:1.8;padding-left:18px;margin:0 0 14px;">
                <?php foreach ($results['created'] as $line): ?><li><?php echo $line; ?></li><?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <?php if (!empty($results['attached'])): ?>
              <p style="font-size:12px;font-weight:600;color:var(--text7);margin-bottom:6px;">ATTACHED</p>
              <ul style="font-size:12.5px;line-height:1.8;padding-left:18px;margin:0 0 14px;">
                <?php foreach ($results['attached'] as $line): ?><li><?php echo $line; ?></li><?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <?php if (!empty($results['skipped'])): ?>
              <p style="font-size:12px;font-weight:600;color:var(--yellow);margin-bottom:6px;">SKIPPED</p>
              <ul style="font-size:12.5px;line-height:1.8;padding-left:18px;margin:0 0 14px;">
                <?php foreach ($results['skipped'] as $line): ?><li><?php echo htmlspecialchars($line); ?></li><?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <?php if (!empty($results['errors'])): ?>
              <p style="font-size:12px;font-weight:600;color:var(--red);margin-bottom:6px;">ERRORS</p>
              <ul style="font-size:12.5px;line-height:1.8;padding-left:18px;margin:0;">
                <?php foreach ($results['errors'] as $line): ?><li><?php echo $line; ?></li><?php endforeach; ?>
              </ul>
            <?php endif; ?>

          <?php endif; ?>

          <div style="margin-top:16px;">
            <a href="students.php" class="btn btn-primary btn-sm"><i class="ti ti-users"></i> View Students</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

</main>
</div>
</body>
</html>
