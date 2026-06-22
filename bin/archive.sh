#!/bin/zsh

# Conductor Environment Variables:
# CONDUCTOR_WORKSPACE_NAME - Workspace name
# CONDUCTOR_WORKSPACE_PATH - Workspace path
# CONDUCTOR_ROOT_PATH      - Path to the main repo root
# CONDUCTOR_DEFAULT_BRANCH - Default branch name
# CONDUCTOR_PORT           - First of 10 reserved ports

# Unregister the Herd site
herd unlink

# Free disk space
rm -rf node_modules
