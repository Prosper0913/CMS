#!/bin/bash
# Adds <script src="/classroomv2/assets/ui.js" defer></script> before </body>
# in every page that uses assets/style.css. Safe to re-run (skips files that already have it).
# Run from the repo root (Git Bash):  bash add_ui_js.sh
TAG='<script src="/classroomv2/assets/ui.js" defer></script>'
while read -r f; do
  [ -f "$f" ] || { echo "missing: $f"; continue; }
  if grep -q "assets/ui.js" "$f"; then echo "skip (already): $f"; continue; fi
  # only the LAST </body> in the file gets the tag
  n=$(grep -n "</body>" "$f" | tail -1 | cut -d: -f1)
  [ -z "$n" ] && { echo "no </body>: $f"; continue; }
  sed -i "${n}s#</body>#${TAG}\n</body>#" "$f"
  echo "ok: $f"
done <<'LIST'
includes/footer.php
admin/api_keys.php
admin/audit_log.php
admin/bulk_grant_existing.php
admin/dashboard.php
admin/password_requests.php
admin/sections.php
admin/settings.php
admin/students.php
admin/teachers.php
cookie_policy.php
loginv2.php
privacy.php
student/dashboard.php
student/notifications.php
student/settings.php
student/subject_detail.php
student/subjects.php
teacher/add_subject.php
teacher/all_subjects.php
teacher/biometric.php
teacher/borrowed_equipment.php
teacher/dashboard.php
teacher/import_students.php
teacher/manage_sections.php
teacher/manage_teachers_UNUSED.php
teacher/settings.php
teacher/students.php
teacher/subject_view.php
teacher/subject_view_biometric.php
terms.php
LIST