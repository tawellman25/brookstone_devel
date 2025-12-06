set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

"${SCRIPT_DIR}/brookstone-sync-from-remote.sh"
"${SCRIPT_DIR}/brookstone-sync-db-from-remote.sh"
