# Bema CRM Plugin

> Custom WordPress plugin for Bema Music that syncs MailerLite subscribers with Easy Digital Downloads (EDD) purchases, manages campaign tiers (Gold, Silver, Bronze, etc.), and automates subscriber transitions between campaigns based on behavior and product purchases.

---

## 📦 Folder Structure

```plaintext
bema_crm/
├── bema_crm.php                # Main plugin loader
├── em_sync/                    # MailerLite & EDD sync logic
│   ├── class.edd.php
│   ├── class.em_sync.php
│   └── class.mailerlite.php
├── includes/                   # Core plugin classes
│   ├── admin/
│   ├── exceptions/
│   ├── handlers/
│   ├── interfaces/
│   ├── validators/
│   └── class-*.php
├── assets/
│   ├── css/admin.css
│   └── js/admin.js, modules/
├── views/                      # Admin UI views
├── post-types/
│   └── class.bema-cpt.php
├── logs/                       # Log output
├── temp/, cache/               # Reserved folders
├── index.php
├── README.md
├── .gitignore
└── CHANGELOG.md
```

---

## ⚙️ Features

- ✅ Custom post type: Campaigns tied to artists/products
- ✅ Tier-based system (Opt-In → Silver → Bronze → Gold, etc.)
- ✅ MailerLite group syncing
- ✅ Easy Digital Downloads (EDD) purchase detection
- ✅ Subscriber transition logic between campaigns
- ✅ Sync and admin management interface
- ✅ Logging + batch handling architecture scaffolded

---

## 🛠 Installation

1. Upload the `bema_crm` folder to `/wp-content/plugins/`
2. Activate via WordPress Admin > Plugins
3. Go to `Bema CRM > Settings`:
   - Add your MailerLite API key
   - Define campaign-product-tier relationships

---

## 🔁 Tier Logic Overview

Example subscriber journey:

| Starting Tier | Action              | Resulting Tier |
|---------------|---------------------|----------------|
| Opt-In        | No purchase         | Silver         |
| Silver        | No purchase         | Bronze         |
| Silver        | Product purchased   | Gold           |
| Bronze        | No purchase         | Wood (inactive) |

Defined in: `em_sync/class.em_sync.php`

---

## 🧠 How It Works

### 1. Campaign Creation
- Go to **Campaigns > Add New**
- Assign product, tier, and MailerLite group

### 2. Syncing Subscribers
- Go to **Bema CRM > Sync Management**
- Pull MailerLite subscriber data
- Compare against EDD purchase history
- Transition subscribers to next tier (if needed)

### 3. Logs & Debugging
- Logs are saved in `/logs/` folder
- Also routed to WP `debug.log` if needed

---

## 📌 File Highlights

| File | Purpose |
|------|---------|
| `bema_crm.php` | Plugin entry point and loader |
| `class.em_sync.php` | Core sync and transition logic |
| `class.mailerlite.php` | MailerLite API abstraction |
| `class.edd.php` | Retrieves and maps purchases |
| `campaign-transitions.php` | Transition matrix UI (needs final implementation) |
| `class-bema-logger.php` | Log manager |

---

## 🚧 To Do (Next Developer)

| Task | Status | Notes |
|------|--------|-------|
| Transition matrix UI | 🔲 Incomplete | Needs admin interface for dynamic mapping |
| Logs tab & notices UI | 🔲 Incomplete | Add viewer in admin to review logs/errors |
| Campaign auto-validation | 🔲 Partial | Product-to-group consistency check |
| WooCommerce adapter | 🔲 Future | Currently EDD-only |

---

## 👨‍💻 Developer Info

**Plugin Handover To:** Seide  
**Original Developer:** Eko The Beat  
**Base Directory:** `/wp-content/plugins/bema_crm/`

---

## 📂 Version Control

See `CHANGELOG.md` for release history.

---

## 🔒 Notes

- Do not commit `logs/`, `temp/`, or `cache/` to Git.
- API keys should be added via Settings, never hardcoded.

---

## 📧 Questions?

Reach out to Eko if you need clarification during setup or development. Optionally schedule a walkthrough.
