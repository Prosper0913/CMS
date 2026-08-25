#!/bin/bash
# ============================================================
#  deploy.sh — commit, push, and deploy classroomv2 in one step
#  Usage: ./deploy.sh "commit message"
#  Run this from inside your local classroomv2 repo folder.
# ============================================================
set -e

MSG="${1:-Update}"
DROPLET_IP="68.183.228.242"
SSH_KEY="/c/Users/dance/ssh_key/cms_ssh"
REMOTE_PATH="/var/www/html/classroomv2"

echo "== Staging and committing local changes =="
git add -A
git commit -m "$MSG" || echo "(nothing to commit — continuing)"

echo "== Pushing to GitHub =="
git push origin main

echo "== Pulling on droplet =="
ssh -i "$SSH_KEY" "root@${DROPLET_IP}" "cd ${REMOTE_PATH} && git pull origin main"

echo "== Done. Live at http://${DROPLET_IP}/classroomv2/ =="

# TO PUSH IN ONE COMMAND --- (./deploy.sh "fixed the BOM issue")