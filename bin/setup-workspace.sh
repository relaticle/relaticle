#!/bin/zsh

# Conductor Environment Variables:
# CONDUCTOR_WORKSPACE_NAME - Workspace name
# CONDUCTOR_WORKSPACE_PATH - Workspace path
# CONDUCTOR_ROOT_PATH      - Path to the main repo root
# CONDUCTOR_DEFAULT_BRANCH - Default branch name
# CONDUCTOR_PORT           - First of 10 reserved ports

# Register this worktree as a Herd site (creates http://WORKSPACE.test)
herd link ${CONDUCTOR_WORKSPACE_NAME}

# Pin PHP version so it doesn't depend on your global Herd setting
herd isolate 8.3 --site="${CONDUCTOR_WORKSPACE_NAME}"

# Symlink .env from the main repo — every worktree shares the same config.
# ln -sf won't fail if the target doesn't exist yet (creates a dangling symlink).
ln -sf "${CONDUCTOR_ROOT_PATH}/.env" .env

# Install dependencies
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
nvm use
herd composer install
npm install