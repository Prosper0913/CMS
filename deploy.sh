#!/bin/bash
# ============================================================
#  deploy.sh — commit, push, and deploy classroomv2 in one step
#  Usage: ./deploy.sh "commit message"
#  Run this from inside your local classroomv2 repo folder.
#
#  Automatically strips any UTF-8 BOM from .php files before
#  committing. A BOM before <?php silently breaks header()
#  redirects in production (blank white page after save) —
#  some editors (Notepad, certain VSCode encoding settings)
#  re-add one on save without warning. This step makes that
#  class of bug impossible to accidentally ship again.
# ============================================================
set -e

MSG="${1:-Update}"
DROPLET_IP="68.183.228.242"
SSH_KEY="/c/Users/dance/ssh_key/cms_ssh"
REMOTE_PATH="/var/www/html/classroomv2"

echo "== Stripping any UTF-8 BOM from .php files =="
py -c "
import os
BOM = b'\xef\xbb\xbf'
fixed = []
for root, dirs, files in os.walk('.'):
    if '.git' in root:
        continue
    for name in files:
        if not name.endswith('.php'):
            continue
        path = os.path.join(root, name)
        with open(path, 'rb') as fh:
            content = fh.read()
        if content.startswith(BOM):
            content = content[len(BOM):]
            with open(path, 'wb') as fh:
                fh.write(content)
            fixed.append(path)
if fixed:
    print(f'  Stripped BOM from {len(fixed)} file(s):')
    for f in fixed:
        print(f'    {f}')
else:
    print('  None found -- clean.')
"

echo "== Staging and committing local changes =="
git add -A
git commit -m "$MSG" || echo "(nothing to commit — continuing)"

echo "== Pushing to GitHub =="
git push origin main

echo "== Pulling on droplet =="
ssh -i "$SSH_KEY" "root@${DROPLET_IP}" "cd ${REMOTE_PATH} && git pull origin main"

echo "== Done. Live at http://${DROPLET_IP}/classroomv2/ =="

# TO PUSH IN ONE COMMAND --- (./deploy.sh "fixed the BOM issue")