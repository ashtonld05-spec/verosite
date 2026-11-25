# VERO Digital website

Static site deployed to Hostinger. Pages: index, work, pricing, about, book; concept sites in work/.
Last updated: 2026-11-19T19:15Z

## Admin dashboard: Lead Finder (/admin/#leads)
Finds local businesses with no website or a weak one (Google Places API), checks their sites (robots.txt respected, rate-limited),
scores them 1-100 with reasons, and links leads to Cal.com meetings by phone/email. Never contacts anyone.
API keys are entered in Admin → Settings → Lead Finder API keys and stored AES-256-GCM encrypted in the database.
Code: api/_lib/leads.php (engine), admin/api.php (lead_* actions), admin/index.php (Leads page). Tables: leads, lead_searches (schema v3, auto-upgrades).
