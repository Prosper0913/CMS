#!/usr/bin/env python3
"""
patch_db.py  -  updates the grading rules inside config/db.php IN PLACE
(keeps your database credentials untouched; makes a .bak copy first).

  python3 patch_db.py /var/www/html/classroomv2/config/db.php
  python3 patch_db.py /c/xampp1/htdocs/classroomv2/config/db.php   (local)

Changes:
  * PASSING_GRADE = 60 and a letterGrade() that passes at 60%
  * attendance rule: Excused counts as present; every 3 Lates = 1 Absent
  * helper attendance_rate_pct() used by the student pages
Safe to run twice (it stops if already patched).
"""
import re, shutil, sys

path = sys.argv[1] if len(sys.argv) > 1 else 'config/db.php'
raw = open(path, encoding='utf-8', newline='').read()
crlf = '\r\n' in raw
src = raw.replace('\r\n', '\n')
if 'PASSING_GRADE' in src:
    print('Already patched - nothing to do.'); sys.exit(0)

# 1) constant + helpers, placed right after the timezone lines
m = re.search(r"date_default_timezone_set\('Asia/Manila'\);[^\n]*", src)
assert m, 'timezone line not found - is this the right db.php?'
anchor = m.group(0)
helpers = r'''
// ── Grading rules (edit here) ────────────────────────────────
define('PASSING_GRADE', 60);          // % needed to pass (written works, exams, performance, final)
define('LATES_PER_ABSENCE', 3);       // every 3 Lates count as 1 Absent

/**
 * Attendance rate (0-100) for one student in one subject.
 *   Present and Excused count as present.
 *   Late counts as present too, EXCEPT that every LATES_PER_ABSENCE lates
 *   turn into one absence.  total = every recorded day (Excused included).
 */
function attendance_rate_pct($total, $absent, $late) {
    $total = (int)$total;
    if ($total <= 0) return 0.0;
    $effective_absent = min($total, (int)$absent + intdiv((int)$late, LATES_PER_ABSENCE));
    return ($total - $effective_absent) / $total * 100;
}
'''
src = src.replace(anchor, anchor + "\n" + helpers, 1)

# 2) attendance query + rate inside recalcSubjectGrade
start = src.index("    // 5. Attendance rate for this subject")
end   = src.index("    // 6. Compute the blended performance component")
new5 = '''    // 5. Attendance rate for this subject
    //    Present + Excused = present.  Every 3 Lates = 1 Absent (see attendance_rate_pct).
    $q4 = $conn->prepare(
        "SELECT
            COUNT(*)                         AS total,
            COALESCE(SUM(status='Absent'),0) AS absent_count,
            COALESCE(SUM(status='Late'),0)   AS late_count
         FROM attendance
         WHERE subject_id=? AND student_id=?"
    );
    $q4->bind_param("is", $subject_id, $student_id);
    $q4->execute();
    $att = $q4->get_result()->fetch_assoc();
    $att_rate = attendance_rate_pct($att['total'], $att['absent_count'], $att['late_count']);

'''
src = src[:start] + new5 + src[end:]

# 3) header comment
src = src.replace("//    att_rate     = Present(+Late×0.5) / total days × 100",
                  "//    att_rate     = (days − Absent − floor(Late/3)) / total days × 100   (Excused = present)")

# 4) letterGrade
start = src.index("function letterGrade($grade) {")
end   = src.index("// ============================================================\n//  getTeacherSubjects")
newl = '''function letterGrade($grade) {
    // 60% is passing. Below it = 5.00 (failed).
    // 60% = 3.0 ... 100% = 1.0, in 0.1 steps (linear). If your school has an
    // official conversion table, replace the formula below with that table.
    $grade = (float)$grade;
    if ($grade < PASSING_GRADE) return '5.00';
    $gp = round(1.0 + (100 - min($grade, 100)) * 0.05, 1);
    return number_format(max(1.0, min(3.0, $gp)), 1);
}


'''
src = src[:start] + newl + src[end:]
src = src.replace("//  Philippine college grading scale (numeric → letter)",
                  "//  Numeric % → grade (60% passing; 5.00 = failed)")

shutil.copyfile(path, path + '.bak')
if crlf:
    src = src.replace('\n', '\r\n')
open(path, 'w', encoding='utf-8', newline='').write(src)
print('Patched. Backup saved as', path + '.bak')
