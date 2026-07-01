#!/bin/bash
# ==============================================================================
# AeroServe — Alwaysdata Automated SSH Deployment Script
# ==============================================================================
# This script automates production deployments for the Laravel backend API
# on the Alwaysdata platform. It can be run manually over SSH or triggered
# via deployment hooks.
# ==============================================================================

# Exit immediately if any command exits with a non-zero status
set -e

# ANSI escape codes for styling
BOLD="\033[1m"
GREEN="\033[32m"
BLUE="\033[34m"
YELLOW="\033[33m"
RED="\033[31m"
RESET="\033[0m"

# Print banner
echo -e "${BOLD}${BLUE}======================================================${RESET}"
echo -e "${BOLD}${BLUE}  ✈️  AEROSERVE — ALWAYS_DATA DEPLOYMENT SCRIPT       ${RESET}"
echo -e "${BOLD}${BLUE}======================================================${RESET}"

# 1. Self-awareness: locate workspace path
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# 2. Check for Git repository and pull changes
if [ -d ".git" ]; then
    echo -e "\n${BOLD}${BLUE}[1/7] 📥 Pulling latest changes from Git...${RESET}"
    CURRENT_BRANCH=$(git branch --show-current 2>/dev/null || echo "main")
    echo -e "Active branch: ${YELLOW}${CURRENT_BRANCH}${RESET}"
    git pull origin "$CURRENT_BRANCH"
else
    echo -e "\n${BOLD}${YELLOW}[1/7] ℹ️ Skipping Git pull (not a Git repository).${RESET}"
fi

# 3. Enter Laravel backend directory
BACKEND_DIR="AeroserveBackendAhlem-main"
if [ ! -d "$BACKEND_DIR" ]; then
    echo -e "\n${BOLD}${RED}❌ Error: Backend directory '$BACKEND_DIR' not found!${RESET}"
    exit 1
fi

echo -e "\n${BOLD}${BLUE}[2/7] 📂 Navigating to backend directory...${RESET}"
cd "$BACKEND_DIR"

# 4. Check for .env file
if [ ! -f ".env" ]; then
    if [ -f ".env.example" ]; then
        echo -e "${BOLD}${YELLOW}⚠️  No .env file found. Copying .env.example...${RESET}"
        cp .env.example .env
        echo -e "${BOLD}${YELLOW}👉 Please update your .env file with production credentials before running again!${RESET}"
    else
        echo -e "\n${BOLD}${RED}❌ Error: .env file missing and no .env.example found!${RESET}"
        exit 1
    fi
fi

# 5. Install composer dependencies (optimized for production)
echo -e "\n${BOLD}${BLUE}[3/7] 📦 Installing Composer dependencies...${RESET}"
if command -v composer &> /dev/null; then
    composer install --no-dev --optimize-autoloader --ansi
else
    echo -e "${BOLD}${YELLOW}⚠️  Composer not found in PATH! Attempting to locate composer.phar...${RESET}"
    if [ -f "composer.phar" ]; then
        php composer.phar install --no-dev --optimize-autoloader --ansi
    else
        echo -e "${BOLD}${RED}❌ Error: Composer could not be found! Please install it on the server.${RESET}"
        exit 1
    fi
fi

# 6. Apply database migrations
echo -e "\n${BOLD}${BLUE}[4/7] 🗄️ Running database migrations...${RESET}"
php artisan migrate --force --ansi

# 7. Rebuild configuration and route caches for maximum performance
echo -e "\n${BOLD}${BLUE}[5/7] ⚡ Optimizing production caches...${RESET}"
php artisan config:cache --ansi
php artisan route:cache --ansi
php artisan view:cache --ansi
php artisan event:cache --ansi

# 8. Set secure directory permissions
echo -e "\n${BOLD}${BLUE}[6/7] 🔒 Setting directory permissions...${RESET}"
chmod -R 775 storage bootstrap/cache
echo -e "${GREEN}✓ Permissions set on storage/ and bootstrap/cache/${RESET}"

# 9. Verify or create storage symbolic link
echo -e "\n${BOLD}${BLUE}[7/7] 🔗 Verifying storage symbolic link...${RESET}"
if [ ! -d "public/storage" ]; then
    php artisan storage:link --ansi || echo -e "${YELLOW}⚠️  Warning: Storage link failed. Please check if public/storage already exists.${RESET}"
else
    echo -e "${GREEN}✓ Storage symbolic link is already present.${RESET}"
fi

echo -e "\n${BOLD}${GREEN}======================================================${RESET}"
echo -e "${BOLD}${GREEN}  🎉 DEPLOYMENT COMPLETE! AEROSERVE IS READY ON PRODUCTION ${RESET}"
echo -e "${BOLD}${GREEN}======================================================${RESET}"
