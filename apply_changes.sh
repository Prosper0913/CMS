#!/usr/bin/env bash
# ============================================================
#  apply_changes.sh — run in Git Bash:   bash ~/Downloads/apply_changes.sh
#
#  Takes the files you downloaded (audit log + password recovery)
#  from your Downloads folder and puts each one in the right place
#  in the project. Before touching anything it checks that every
#  file is present, and it backs up whatever it is about to overwrite.
#
#  Change the paths below if yours are different (or pass them as
#  environment variables, e.g.  PROJ=/d/site bash apply_changes.sh).
# ============================================================
DL="${DL:-$HOME/Downloads}"
PROJ="${PROJ:-/c/xampp1/htdocs/classroomv2}"
PHP="${PHP:-/c/xampp1/php/php.exe}"
MYSQL="${MYSQL:-/c/xampp1/mysql/bin/mysql.exe}"
DB="${DB:-classroom_db2}"
DB_USER="${DB_USER:-root}"

ISSUES=0
STAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP="$(dirname "$PROJ")/classroomv2_backup_$STAMP"

# "name-as-downloaded (without .php)"  ->  where it goes in the project
# (a name may list alternatives separated by |, in case the browser renamed it)
FILES=(
  "login|login.php"
  "logout|logout.php"
  "forgot_password|forgot_password.php"
  "reset_password|reset_password.php"
  "_nav|nav|admin/_nav.php"
  "audit_log|admin/audit_log.php"
  "password_requests|admin/password_requests.php"
  "audit|includes/audit.php"
  "csrf|includes/csrf.php"
  "auth_page|includes/auth_page.php"
)
SQL_NAME="audit_and_recovery"

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
bold()  { printf '\033[1m%s\033[0m\n' "$*"; }

# newest file in Downloads called "<base>.<ext>" or "<base> (2).<ext>" (browser duplicates)
pick() {
  local base="$1" ext="$2" f best=""
  for f in "$DL/$base.$ext" "$DL/$base "\(*\)".$ext"; do
    if [ -f "$f" ]; then
      if [ -z "$best" ] || [ "$f" -nt "$best" ]; then best="$f"; fi
    fi
  done
  printf '%s' "$best"
}

# ── 0. Sanity checks ─────────────────────────────────────────
if [ ! -f "$PROJ/login.php" ] || [ ! -d "$PROJ/includes" ]; then
  red "Can't find the project at: $PROJ"
  echo "Edit PROJ at the top of this script (or run:  PROJ=/path/to/classroomv2 bash apply_changes.sh)"
  exit 1
fi

# ── 1. Find every file BEFORE changing anything ──────────────
SRC=(); DEST=(); MISSING=()
for entry in "${FILES[@]}"; do
  IFS='|' read -r -a parts <<< "$entry"
  dest="${parts[${#parts[@]}-1]}"
  unset 'parts[${#parts[@]}-1]'
  found=""
  for base in "${parts[@]}"; do
    found="$(pick "$base" php)"
    [ -n "$found" ] && break
  done
  if [ -z "$found" ]; then MISSING+=("$(basename "$dest")"); else SRC+=("$found"); DEST+=("$dest"); fi
done
SQL_SRC="$(pick "$SQL_NAME" sql)"
[ -z "$SQL_SRC" ] && MISSING+=("$SQL_NAME.sql")

if [ ${#MISSING[@]} -gt 0 ]; then
  red "Not in $DL yet — nothing was changed:"
  printf '   - %s\n' "${MISSING[@]}"
  echo "Download the missing file(s) and run this again."
  exit 1
fi

# ── 2. Back up what will be overwritten ──────────────────────
bold "Backing up files that will be replaced -> $BACKUP"
for i in "${!DEST[@]}"; do
  d="${DEST[$i]}"
  if [ -f "$PROJ/$d" ]; then
    mkdir -p "$BACKUP/$(dirname "$d")"
    cp -p "$PROJ/$d" "$BACKUP/$d"
    echo "   saved  $d"
  fi
done

# ── 3. Copy the new files in ─────────────────────────────────
bold "Copying files into $PROJ"
for i in "${!DEST[@]}"; do
  d="${DEST[$i]}"
  mkdir -p "$PROJ/$(dirname "$d")"
  if [ -f "$PROJ/$d" ]; then verb="updated"; else verb="added  "; fi
  cp "${SRC[$i]}" "$PROJ/$d"
  echo "   $verb $d"
done

# ── 4. PHP syntax check ──────────────────────────────────────
if [ -x "$PHP" ] || command -v "$PHP" >/dev/null 2>&1; then
  bold "Checking PHP syntax"
  bad=0
  for d in "${DEST[@]}"; do
    out="$("$PHP" -l "$PROJ/$d" 2>&1)"
    case "$out" in
      *"No syntax errors"*) ;;
      *) red "   PROBLEM in $d:"; echo "$out"; bad=1; ISSUES=1 ;;
    esac
  done
  [ $bad -eq 0 ] && green "   all files OK"
else
  echo "(skipped syntax check — PHP not found at $PHP)"
fi

# ── 5. Database tables ───────────────────────────────────────
bold "Creating the audit_log and password_reset_requests tables in '$DB'"
if [ -x "$MYSQL" ] || command -v "$MYSQL" >/dev/null 2>&1; then
  if "$MYSQL" -u "$DB_USER" "$DB" < "$SQL_SRC"; then
    green "   database updated (safe to re-run — it uses IF NOT EXISTS)"
  else
    ISSUES=1
    red "   The SQL didn't run. Is MySQL started in XAMPP? Does '$DB_USER' need a password?"
    echo "   Run it by hand:  \"$MYSQL\" -u $DB_USER -p $DB < \"$SQL_SRC\""
  fi
else
  ISSUES=1
  red "   mysql not found at $MYSQL"
  echo "   Import $SQL_SRC in phpMyAdmin (database: $DB) instead."
fi

echo
if [ $ISSUES -eq 0 ]; then green "Done — everything applied."; else red "Files were copied, but something above needs attention."; fi
echo "Try it:   http://localhost/classroomv2/login.php   (look for 'Forgot password?')"
echo "          http://localhost/classroomv2/admin/audit_log.php   (as admin)"
echo
echo "Undo:     cp -r \"$BACKUP\"/. \"$PROJ\"/   and delete the 7 new files:"
echo "          forgot_password.php reset_password.php admin/audit_log.php admin/password_requests.php"
echo "          includes/audit.php includes/csrf.php includes/auth_page.php"