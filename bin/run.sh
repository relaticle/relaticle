#!/bin/zsh

# Conductor Environment Variables:
# CONDUCTOR_WORKSPACE_NAME - Workspace name
# CONDUCTOR_WORKSPACE_PATH - Workspace path
# CONDUCTOR_ROOT_PATH      - Path to the main repo root
# CONDUCTOR_DEFAULT_BRANCH - Default branch name
# CONDUCTOR_PORT           - First of 10 reserved ports

# Open the site in the default browser
herd open

# Run Vite dev server and queue worker side by side.
# Ctrl+C stops both.
npx concurrently "npm run dev" "herd php artisan queue:work"
