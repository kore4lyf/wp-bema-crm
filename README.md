# Bema CRM Plugin

> Custom WordPress plugin for Bema Music that syncs MailerLite subscribers with Easy Digital Downloads (EDD) purchases, manages campaign tiers (Gold, Silver, Bronze, etc.), and automates subscriber transitions between campaigns based on behavior and product purchases.

---

## 📦 Current Folder Structure

```plaintext
bema_crm/
├── bema_crm.php                # Main plugin loader and core class
├── em_sync/                    # MailerLite & EDD sync logic
│   ├── class.edd.php
│   ├── class.em_sync.php
│   ├── class.mailerlite.php
│   ├── triggers/
│   │   └── class-triggers.php  # WP-Cron triggers for async operations
│   ├── utils/
│   │   └── class-utils.php     # Utility functions
│   ├── sync/
│   │   └── class-sync-manager.php # Sync management
│   └── transition/
│       └── class-transition-manager.php # Campaign transitions
├── includes/                   # Core plugin classes
│   ├── admin/
│   │   ├── class-admin-interface.php
│   │   └── views/              # Admin UI templates
│   ├── database/               # Database managers for all tables
│   ├── exceptions/
│   ├── handlers/
│   ├── interfaces/
│   ├── class-bema-crm-logger.php
│   ├── class-database-manager.php
│   ├── class-manager-factory.php
│   ├── class-settings.php
│   └── class-database-migrations.php
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   ├── dashboard.css
│   │   ├── settings.css
│   │   └── synchronize.css
│   └── js/
│       ├── admin.js
│       ├── admin-campaigns-page.js
│       └── modules/
│           ├── sync.js
│           ├── database.js
│           └── logs.js
├── post-types/
│   └── class.bema-cpt.php
├── logs/                       # Log output directory
├── temp/, cache/               # Reserved folders
├── index.php
├── README.md
└── CHANGELOG.md
```

---

## ⚙️ Core Features

- ✅ **Campaign Management**: Create and manage music campaigns tied to artists/products
- ✅ **Tier-based System**: Opt-In → Wood → Bronze → Silver → Gold (with purchase variants)
- ✅ **MailerLite Integration**: Sync groups, campaigns, and subscriber data
- ✅ **EDD Purchase Detection**: Automatically detect and process music album purchases
- ✅ **Subscriber Transition Logic**: Automate subscriber movement between campaign tiers
- ✅ **Custom Campaign Creation**: Create campaigns not tied to specific album posts
- ✅ **Async Processing**: WP-Cron based background processing for all operations
- ✅ **Comprehensive Admin Interface**: Full dashboard with statistics and controls
- ✅ **Advanced Logging**: Detailed logging system for debugging and monitoring
- ✅ **Database Management**: Custom tables for campaigns, subscribers, groups, etc.

---

## 🎯 Key Functionality

### 1. Campaign Management
- Create campaigns through the admin interface
- Associate campaigns with EDD products
- Set start/end dates and manage campaign status
- Support for both album-based and custom campaigns

### 2. Subscriber Management
- Sync MailerLite subscribers with local database
- Track subscriber tiers and purchase history
- Manage subscriber groups and campaign associations

### 3. Purchase Processing
- Automatic detection of EDD order completions
- Creation of purchase fields in MailerLite
- Assignment of subscribers to appropriate campaign groups

### 4. Campaign Transitions
- Automated subscriber transitions between campaign tiers
- Configurable transition rules matrix
- Manual transition controls for campaign migration

### 5. Background Processing
- All operations run through WP-Cron for performance
- Custom campaign creation/deletion with async processing
- Purchase field creation/deletion for campaigns
- Album group creation/deletion on publish/delete

---

## 🖥️ Admin Interface

### Dashboard
- Campaign statistics and overview
- Sync status monitoring
- Subscriber metrics and breakdown
- Revenue analytics with EDD integration

### Campaigns
- Create new campaigns with product associations
- Edit campaign details (dates, status)
- View all campaigns with sorting and pagination
- Delete campaigns with automatic cleanup

### Synchronize
- Manual sync initiation
- Sync history tracking
- Progress monitoring
- Performance metrics display

### Database Management
- Subscriber data viewing and filtering
- Bulk actions (resync subscribers)
- Tier and campaign filtering
- Search functionality

### Campaign Transitions
- Manual campaign transitions
- Transition history tracking
- Transition matrix configuration
- Tier management

### Settings
- MailerLite API configuration
- EDD API credentials
- Sync settings (batch size, retry attempts)
- Memory limit configuration

---

## 🔄 WP-Cron Triggers

The plugin uses WordPress cron system for all background operations:

| Hook Name | Function | Purpose |
|-----------|----------|---------|
| `bema_handle_order_purchase_field_update` | `handle_order_purchase_field_update_via_cron` | Update subscriber purchase fields after EDD order completion |
| `bema_create_groups_on_album_publish` | `handle_create_groups_via_cron` | Create MailerLite groups when album posts are published |
| `bema_create_purchase_field_on_album_publish` | `handle_create_purchase_field_via_cron` | Create purchase fields for album campaigns |
| `bema_handle_deleted_album` | `handle_deleted_album_cron` | Clean up MailerLite data when album posts are deleted |
| `bema_create_custom_campaign` | `handle_create_custom_campaign_via_cron` | Create custom campaigns not tied to posts |
| `bema_delete_custom_campaign` | `handle_delete_custom_campaign_via_cron` | Delete custom campaigns |
| `bema_create_custom_campaign_purchase_field` | `handle_create_custom_campaign_purchase_field_via_cron` | Create purchase fields for custom campaigns |
| `bema_delete_custom_campaign_purchase_field` | `handle_delete_custom_campaign_purchase_field_via_cron` | Delete purchase fields for custom campaigns |

---

## 🗄️ Database Structure

The plugin uses several custom database tables:

- `wp_bemacrm_campaignsmeta` - Campaign information
- `wp_bemacrm_groupsmeta` - MailerLite group data
- `wp_bemacrm_fieldsmeta` - MailerLite field data
- `wp_bemacrm_subscribersmeta` - Subscriber information
- `wp_bemacrm_campaign_subscribersmeta` - Campaign-subscriber relationships
- `wp_bemacrm_transitionsmeta` - Transition history
- `wp_bemacrm_transition_subscribersmeta` - Transition subscriber data
- `wp_bemacrm_sync_log` - Sync operation logs

---

## 🛠 Installation

1. Upload the `bema_crm` folder to `/wp-content/plugins/`
2. Activate via WordPress Admin > Plugins
3. Go to `Bema CRM > Settings`:
   - Add your MailerLite API key
   - Configure EDD API credentials
   - Set sync preferences
4. Create campaigns through `Bema CRM > Campaigns`

---

## 🔧 Configuration

### Required Plugins
- Easy Digital Downloads (Pro recommended)
- WordPress 5.6 or higher
- PHP 7.4 or higher

### API Configuration
1. **MailerLite API Key**: Required for MailerLite integration
2. **EDD API Credentials**: Public key and token for EDD integration
3. **Sync Settings**: Batch size, retry attempts, memory limits

### Default Tiers
The plugin comes with these default tiers:
- Opt-In
- Wood
- Gold
- Silver
- Bronze
- Bronze Purchase
- Silver Purchase
- Gold Purchase

---

## 📊 Logging System

The plugin includes a comprehensive logging system:
- File-based logging in `/logs/` directory
- Database logging for sync operations
- Debug logging with correlation IDs
- Error tracking and monitoring
- Performance metrics collection

---

## 🚀 Development Features

### Manager Factory Pattern
Centralized access to database managers and services:
- `Manager_Factory::get_campaign_database_manager()`
- `Manager_Factory::get_sync_manager()`
- `Manager_Factory::get_transition_manager()`

### Triggers System
Event-driven architecture using WordPress hooks:
- Automatic scheduling of background operations
- Error handling and retry mechanisms
- Comprehensive logging for all operations

### Utility Functions
Helper functions for common operations:
- Campaign name parsing and formatting
- Album detail extraction
- Date and string manipulation

---

## 🧪 Testing and Debugging

### Debug Mode
Enable WP_DEBUG to see detailed logging information.

### Manual Cron Execution
WP-Cron events can be triggered manually for testing:
```bash
wp cron event run --all
```

### Log Analysis
Check `/logs/` directory for detailed operation logs.

---

## 🔒 Security

- Nonce verification for all admin actions
- Capability checks for user permissions
- Data sanitization and validation
- Secure API credential storage
- XSS prevention in admin interface

---

## 📈 Performance

- Asynchronous processing for all heavy operations
- Batch processing for large data sets
- Memory usage monitoring and optimization
- Database indexing for fast queries
- Caching mechanisms where appropriate

---

## 📌 File Highlights

| File | Purpose |
|------|---------|
| `bema_crm.php` | Plugin entry point and main class |
| `class-triggers.php` | WP-Cron event handlers |
| `class-sync-manager.php` | Core sync functionality |
| `class-transition-manager.php` | Campaign transition logic |
| `class-admin-interface.php` | Admin UI controller |
| `admin.js` | Main admin JavaScript functionality |
| `sync.js` | Sync page JavaScript controls |

---

## 🚧 Future Enhancements

| Task | Status | Notes |
|------|--------|-------|
| Enhanced transition matrix UI | ✅ Partially Complete | Settings page implemented |
| Logs tab & notices UI | ✅ Complete | Admin interface available |
| Campaign auto-validation | ✅ Complete | Validation implemented |
| WooCommerce adapter | 🔲 Future | Currently EDD-only |
| Advanced reporting | 🔲 Future | Analytics expansion |
| Email templates | 🔲 Future | Automated email sequences |

---

## 👨‍💻 Developer Info

**Plugin Maintainer:** Current Development Team  
**Original Developer:** Bema Music
**Base Directory:** `/wp-content/plugins/bema_crm/`

---

## 📂 Version Control

See `CHANGELOG.md` for release history and version details.

---

## 🔒 Notes

- Do not commit `logs/`, `temp/`, or `cache/` to Git.
- API keys should be added via Settings, never hardcoded.
- Database tables are automatically created on plugin activation.
- WP-Cron must be enabled for background processing.

---

## 📧 Support

For issues, questions, or feature requests, please contact the development team.