# Automatic Deployment Setup

This repository is configured to automatically deploy to Hostgator whenever you push code to GitHub!

## One-Time Setup (5 minutes)

### Step 1: Get Your FTP Details from Hostgator

You need these 3 pieces of information:

1. **FTP Server:** Usually `ftp.yourdomain.com` or `yourdomain.com`
2. **FTP Username:** Your cPanel username (likely `uunppite`)
3. **FTP Password:** Your cPanel password

### Step 2: Add Secrets to GitHub

1. Go to your GitHub repository: https://github.com/jaylenmareko/topic-funding

2. Click **Settings** (top menu)

3. Click **Secrets and variables** → **Actions** (left sidebar)

4. Click **New repository secret** and add these 3 secrets:

   **Secret 1:**
   - Name: `FTP_SERVER`
   - Value: Your FTP server (e.g., `ftp.uun.ppi.temporary.site`)

   **Secret 2:**
   - Name: `FTP_USERNAME`
   - Value: Your FTP username (e.g., `uunppite`)

   **Secret 3:**
   - Name: `FTP_PASSWORD`
   - Value: Your FTP password

### Step 3: Done! 🎉

That's it! Now whenever you (or I) push code to GitHub, it will automatically deploy to your Hostgator server within 1-2 minutes.

## How It Works

- Push code to GitHub → GitHub Actions runs → Files upload to Hostgator automatically
- You can watch deployments under the **Actions** tab in your GitHub repo
- Green checkmark = deployed successfully
- Red X = something went wrong (usually wrong FTP credentials)

## What Gets Deployed

The workflow deploys everything in your repository to `/public_html/` except:
- `.git` files
- `node_modules`
- `.github` folder
- Deployment guide files

## Monitoring Deployments

Visit: https://github.com/jaylenmareko/topic-funding/actions

You'll see a list of all deployments with their status.

## Testing

Once you've added the secrets, I'll push the workflow file and it will trigger automatically!
