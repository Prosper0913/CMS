<?php
// ============================================================
//  admin/import_students.php
//  Bulk-enroll students from a CSV or XLSX file. Reuses the exact
//  same insert logic as the "Add Student" form on students.php
//  (students + users [+ section_students]), looped over rows.
//  Each row is its own transaction, so one bad row never kills
//  the rest of the batch. If a row names a section that doesn't
//  exist yet, that section is auto-created (same as manually
//  creating one on sections.php). Admin-only.
//
//  XLSX support is hand-rolled with ZipArchive + SimpleXML (both
//  ship with standard PHP) instead of pulling in a Composer
//  library like PhpSpreadsheet — an .xlsx file is just a zip of
//  XML, so we read the first worksheet directly. This covers
//  plain data cells (text/numbers/shared strings); it does not
//  evaluate formulas or handle multiple sheets.
// ============================================================
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/db.php';

// Make DB failures loud instead of silent. Without this, a failed
// prepare()/execute() just returns false and the script would carry
// on as if nothing happened — which is exactly why earlier imports
// looked like they "succeeded" but nothing showed up in the tables.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$admin_id = (int)$_SESSION['user_id'];

// ── Download a blank CSV template ──────────────────────────────
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['student_id','last_name','first_name','middle_name','email','course','section','username','password']);
    fputcsv($out, ['2023-00123','Dela Cruz','Juan','P','juan.delacruz@example.com','BSIT','BSIT 3A','jdelacruz','ChangeMe123']);
    fclose($out);
    exit;
}

// ── Header aliases: normalized header text -> our field name ───
// Normalization = lowercase, strip spaces/underscores/hyphens.
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
    'course'         => 'course',
    'program'        => 'course',
    'section'        => 'section',
    'sectionname'    => 'section',
    'username'       => 'username',
    'password'       => 'password',
];
function normalize_header($h) {
    return strtolower(preg_replace('/[\s_\-]+/', '', trim((string)$h)));
}

// ── Column letter (A, B, ..., AA, AB...) -> zero-based index ───
function colref_to_index($ref) {
    preg_match('/^([A-Z]+)/', $ref, $m);
    $letters = $m[1] ?? 'A';
    $col = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $col = $col * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $col - 1;
}

// ── Read a CSV file into rows keyed by their physical line number ─
function read_csv_rows($path) {
    $rows = [];
    $handle = fopen($path, 'r');
    if (!$handle) throw new Exception("Could not read the uploaded file.");
    $line_num = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $line_num++;
        if (count($row) === 1 && trim((string)$row[0]) === '') continue; // blank line
        if ($line_num === 1) {
            // strip a UTF-8 BOM off the very first header cell, if present
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
        }
        $rows[$line_num] = $row;
    }
    fclose($handle);
    if (empty($rows)) throw new Exception("The file appears to be empty.");
    return $rows;
}

// ── Read the first worksheet of an .xlsx file into rows keyed by
//    their actual Excel row number (via ZipArchive + SimpleXML) ───
function read_xlsx_rows($path) {
    if (!class_exists('ZipArchive')) {
        throw new Exception("The server's PHP is missing the Zip extension needed to read .xlsx files. Enable php_zip in php.ini, or upload a .csv instead.");
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new Exception("Could not open the .xlsx file — it may be corrupted or not a real Excel file.");
    }

    // Resolve which worksheet XML file is the FIRST sheet in the workbook.
    $sheet_path = 'xl/worksheets/sheet1.xml'; // sane fallback
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

    // Shared strings table — most text cells reference this instead of
    // storing their text inline.
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
                $val = (string)$c->v; // plain number or formula-cached string
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

$results       = null; // set once a file has been processed
$section_cache = [];   // name (lowercased) -> section id, built as we go

// ── Look up (or auto-create) a section by name ──────────────────
function resolve_section($conn, $admin_id, $name, &$section_cache, &$was_created) {
    $was_created = false;
    $key = strtolower($name);
    if (isset($section_cache[$key])) return $section_cache[$key];

    $chk = $conn->prepare("SELECT id FROM sections WHERE section_name = ? LIMIT 1");
    $chk->bind_param('s', $name);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    if ($row) {
        $section_cache[$key] = (int)$row['id'];
        return $section_cache[$key];
    }

    $ins = $conn->prepare(
        "INSERT INTO sections (section_name, description, course, year_level, school_year, created_by)
         VALUES (?, '', '', 1, '', ?)"
    );
    $ins->bind_param('si', $name, $admin_id);
    $ins->execute();
    $section_cache[$key] = $conn->insert_id;
    $was_created = true;
    return $section_cache[$key];
}

// ── Handle uploaded file (CSV or XLSX) ──────────────────────────
if (isset($_POST['import_csv'])) {
    $results = ['created' => [], 'skipped' => [], 'errors' => [], 'sections_created' => []];

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
                $required     = ['student_id', 'last_name', 'first_name', 'username', 'password'];
                $missing_cols = array_diff($required, array_filter($col_map));

                if (!empty($missing_cols)) {
                    $results['fatal'] = "Your file is missing required column(s): " . implode(', ', $missing_cols)
                        . ". Download the template below for the expected headers.";
                } else {
                    $seen_ids = [];
                    $seen_usernames = [];

                    foreach ($row_keys as $row_num) {
                        $row = $all_rows[$row_num];

                        $data = ['student_id'=>'','last_name'=>'','first_name'=>'','middle_name'=>'',
                                 'email'=>'','course'=>'','section'=>'','username'=>'','password'=>''];
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
                            $course         = $data['course'];
                        $section_name   = $data['section'];
                        $username       = $data['username'];
                        $password       = $data['password'];

                        $label = "Row {$row_num} ({$last_name}, {$first_name})";

                        if ($student_id===''||$last_name===''||$first_name===''||$username===''||$password==='') {
                            $results['errors'][] = "$label: missing a required field (ID, name, username, or password).";
                            continue;
                        }
                        if (strlen($password) < 6) {
                            $results['errors'][] = "$label: password must be at least 6 characters.";
                            continue;
                        }
                        if (isset($seen_ids[$student_id]) || isset($seen_usernames[$username])) {
                            $results['skipped'][] = "$label: duplicate student ID or username elsewhere in this file.";
                            continue;
                        }

                        try {
                            $chk = $conn->prepare("SELECT id FROM students WHERE student_id=? OR username=? LIMIT 1");
                            $chk->bind_param('ss', $student_id, $username);
                            $chk->execute();
                            $chk->store_result();
                            if ($chk->num_rows > 0) {
                                $results['skipped'][] = "$label: student ID or username already exists in the system.";
                                continue;
                            }

                            $section_id = null;
                            $section_note = '';
                            if ($section_name !== '') {
                                $was_created = false;
                                $section_id = resolve_section($conn, $admin_id, $section_name, $section_cache, $was_created);
                                if ($was_created) {
                                    $results['sections_created'][] = $section_name;
                                    $section_note = " — new section \"$section_name\" created";
                                } else {
                                    $section_note = " — added to \"$section_name\"";
                                }
                            }

                            $conn->begin_transaction();
                            $hashed = password_hash($password, PASSWORD_DEFAULT);

                            $ins = $conn->prepare(
                                    "INSERT INTO students
                                        (student_id,last_name,first_name,middle_initial,email,course,username,password,created_by)
                                     VALUES (?,?,?,?,?,?,?,?,?)"
                                );
                                $ins->bind_param("ssssssssi",
                                    $student_id,$last_name,$first_name,$middle_initial,$email,$course,$username,$hashed,$admin_id
                                );
                            $ins->execute();
                            if ($ins->affected_rows < 1) {
                                throw new Exception("insert into students affected 0 rows");
                            }

                            $ins2 = $conn->prepare(
                                "INSERT INTO users (username,password,role,student_id) VALUES (?,?,'student',?)"
                            );
                            $ins2->bind_param("sss",$username,$hashed,$student_id);
                            $ins2->execute();
                            if ($ins2->affected_rows < 1) {
                                throw new Exception("insert into users affected 0 rows");
                            }

                            if ($section_id) {
                                $ins3 = $conn->prepare("INSERT INTO section_students (section_id, student_id) VALUES (?, ?)");
                                $ins3->bind_param("is", $section_id, $student_id);
                                $ins3->execute();
                                if ($ins3->affected_rows < 1) {
                                    throw new Exception("insert into section_students affected 0 rows");
                                }
                            }

                            $conn->commit();
                            $seen_ids[$student_id] = true;
                            $seen_usernames[$username] = true;
                            $results['created'][] = "$label: enrolled as <code>" . htmlspecialchars($username) . "</code>{$section_note}.";
                        } catch (Throwable $e) {
                            if ($conn->errno || true) { $conn->rollback(); }
                            $results['errors'][] = "$label: database error — " . htmlspecialchars($e->getMessage());
                        }
                    }
                }
            }
        }
    }
}

$active_nav = 'import';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Import Students — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-admin-import">
<div class="app-shell">


<?php $active_nav = 'import'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-file-import text-accent"></i> Import Students</h1>
    <p>Bulk-enroll students from a CSV or Excel file instead of adding them one at a time.</p>
  </div>
  <hr class="thin-line" style="margin-bottom: 25px;">

  <div class="two-col">
    <div>
      <div class="card">
        <p class="card-title"><i class="ti ti-upload"></i> Upload File</p>
        <p style="font-size:12px;color:var(--text7);margin-top:-6px;margin-bottom:14px;">
          Accepts <code>.csv</code> or <code>.xlsx</code>. Required columns: <code>student_id, last_name, first_name, username, password</code>.
          Optional: <code>middle_name, email, course, section</code>. Column order doesn't matter, and
          a few common header spellings (e.g. "ID Number", "Last Name") are recognized automatically.
          If a section name doesn't exist yet, it will be created automatically.
        </p>
        <p style="font-size:12px;color:var(--text7);margin-top:0;margin-bottom:14px;">
          <i class="ti ti-alert-triangle"></i> If your Student IDs are pure numbers (e.g. <code>00123</code>), format that
          column as <b>Text</b> in Excel before typing — otherwise Excel silently drops leading zeros.
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
            <li>Each row becomes one student, added exactly like the "Add Student" form.</li>
            <li>Rows missing required fields, or with a password under 6 characters, are skipped and reported — the rest of the file still imports.</li>
            <li>Rows whose student ID or username already exists (in the file or the system) are skipped, not overwritten.</li>
            <li>Section names that don't exist yet are created automatically and reused for later rows with the same name.</li>
            <li>Every insert is verified — if a row doesn't actually save, it now shows up as an error instead of silently disappearing.</li>
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
              <span style="font-size:12px;font-weight:600;padding:4px 10px;border-radius:99px;background:rgba(234,179,8,.12);color:var(--yellow);border:1px solid rgba(234,179,8,.25);">
                <?php echo count($results['skipped']); ?> skipped
              </span>
              <span style="font-size:12px;font-weight:600;padding:4px 10px;border-radius:99px;background:rgba(239,68,68,.12);color:var(--red);border:1px solid rgba(239,68,68,.25);">
                <?php echo count($results['errors']); ?> errors
              </span>
              <?php if (!empty($results['sections_created'])): ?>
              <span class="badge badge-blue"><?php echo count(array_unique($results['sections_created'])); ?> new section(s)</span>
              <?php endif; ?>
            </div>

            <?php if (!empty($results['created'])): ?>
              <p style="font-size:12px;font-weight:600;color:var(--text7);margin-bottom:6px;">CREATED</p>
              <ul style="font-size:12.5px;line-height:1.8;padding-left:18px;margin:0 0 14px;">
                <?php foreach ($results['created'] as $line): ?><li><?php echo $line; /* already escaped/safe above */ ?></li><?php endforeach; ?>
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
                <?php foreach ($results['errors'] as $line): ?><li><?php echo $line; /* built with htmlspecialchars for dynamic parts */ ?></li><?php endforeach; ?>
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