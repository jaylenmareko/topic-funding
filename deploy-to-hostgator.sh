#!/bin/bash
# Quick deploy script for Hostgator
# Usage: ./deploy-to-hostgator.sh

echo "🚀 Deploying to Hostgator..."

# TODO: Replace these with your actual credentials
HOSTGATOR_USER="your-cpanel-username"
HOSTGATOR_HOST="yourdomain.com"
HOSTGATOR_PORT="2222"
WEB_DIR="public_html"

# Color codes
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}📡 Connecting to server...${NC}"

# SSH into server and pull latest changes
ssh -p $HOSTGATOR_PORT $HOSTGATOR_USER@$HOSTGATOR_HOST << 'ENDSSH'
cd public_html
echo "📥 Pulling latest changes from GitHub..."
git pull origin main
echo "✅ Deployment complete!"
ENDSSH

echo -e "${GREEN}✨ Done! Your site is updated.${NC}"
