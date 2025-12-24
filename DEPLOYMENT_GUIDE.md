# Git Deployment Setup for Hostgator

## Prerequisites
- SSH access to Hostgator (check in cPanel under "SSH Access")
- Your Hostgator SSH credentials
- GitHub repository access

## Step 1: Enable SSH on Hostgator

1. Log into your Hostgator cPanel
2. Search for "SSH Access" or "Terminal"
3. Enable SSH access if not already enabled
4. Note your SSH credentials:
   - Host: Usually `yourdomain.com` or `ssh.yourdomain.com`
   - Port: Usually `2222` (Hostgator default)
   - Username: Your cPanel username
   - Password: Your cPanel password

## Step 2: SSH Into Your Server

Open your terminal and connect:
```bash
ssh -p 2222 your-cpanel-username@yourdomain.com
```

## Step 3: Navigate to Your Web Directory

Once connected, navigate to your website's public directory:
```bash
cd public_html
# or if in a subdirectory:
# cd public_html/your-app-folder
```

## Step 4: Set Up Git Repository

### Option A: If code is NOT already on the server
```bash
# Clone your repository
git clone https://github.com/jaylenmareko/topic-funding.git .

# Note: The dot (.) at the end clones into current directory
```

### Option B: If code IS already on the server
```bash
# Initialize git in existing directory
git init
git remote add origin https://github.com/jaylenmareko/topic-funding.git
git fetch
git checkout -b main origin/main
```

## Step 5: Create Deploy Script

Create a deployment script to make updates easy:

```bash
# Create the deploy script
nano deploy.sh
```

Paste this content:
```bash
#!/bin/bash
echo "🚀 Starting deployment..."

# Pull latest changes from GitHub
echo "📥 Pulling latest changes..."
git fetch origin
git pull origin main

# Optional: Clear any caches
# php artisan cache:clear (if using Laravel)
# rm -rf cache/* (if you have a cache folder)

echo "✅ Deployment complete!"
```

Save and exit (Ctrl+X, then Y, then Enter)

Make it executable:
```bash
chmod +x deploy.sh
```

## Step 6: Deploy Updates

Now whenever you push to GitHub, just SSH in and run:
```bash
./deploy.sh
```

Or even simpler, run directly:
```bash
cd public_html && git pull origin main
```

## Alternative: One-Command Deploy (Advanced)

You can even run git pull via SSH without logging in:
```bash
ssh -p 2222 your-username@yourdomain.com "cd public_html && git pull origin main"
```

Add this to a local script for one-click deploys!

## Troubleshooting

### Permission Issues
If you get permission errors:
```bash
chmod -R 755 .
chown -R your-username:your-username .
```

### GitHub Authentication
If GitHub asks for credentials repeatedly, set up a Personal Access Token:
1. Go to GitHub Settings > Developer Settings > Personal Access Tokens
2. Generate new token with 'repo' permissions
3. Use token as password when prompted

### File Conflicts
If you get merge conflicts:
```bash
git reset --hard origin/main
```
**Warning:** This will overwrite local changes!

---

## Quick Reference

**Deploy updates:**
```bash
ssh -p 2222 username@domain.com "cd public_html && git pull origin main"
```

**Check current version:**
```bash
ssh -p 2222 username@domain.com "cd public_html && git log -1"
```
