# Changelog

All notable changes to the Bema CRM plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Custom campaign creation functionality with WP-Cron background processing
- Custom campaign deletion functionality with WP-Cron background processing
- Purchase field creation for custom campaigns
- Purchase field deletion for custom campaigns
- Enhanced error handling and logging for all cron operations
- Debug logging for cron job execution tracking

### Changed
- Updated Triggers constructor to accept nullable parameters for better EDD integration handling
- Improved error messages and logging for MailerLite API operations
- Enhanced campaign management interface with better validation
- Updated README with comprehensive documentation of current features

### Fixed
- Issue with cron jobs not executing when EDD is not active
- Duplicate function declarations in JavaScript files
- Error handling in MailerLite field creation process
- Campaign deletion logic to prevent duplicate execution

## [1.0.0] - 2025-10-08

### Added
- Initial release of Bema CRM plugin
- MailerLite subscriber synchronization with EDD purchases
- Campaign tier management system (Opt-In, Wood, Bronze, Silver, Gold)
- Automated subscriber transitions between campaign tiers
- WordPress admin interface with dashboard, campaigns, sync, and settings pages
- Custom database tables for campaigns, subscribers, groups, and transitions
- WP-Cron based background processing for all operations
- Comprehensive logging system for debugging and monitoring
- Album-based campaign creation on post publish
- Purchase field creation for album campaigns
- Group management for campaign tiers
- Revenue analytics integration with EDD
- Bulk subscriber resync functionality
- Campaign transition controls and history
- Tier configuration and management
- Database management interface
- Settings configuration for API keys and sync preferences

### Changed
- Refactored codebase to use Manager Factory pattern for better organization
- Implemented proper error handling and validation throughout
- Enhanced security with nonce verification and capability checks
- Improved performance with batch processing and async operations
- Updated admin interface with modern styling and UX improvements
- Optimized database queries with proper indexing and caching

### Fixed
- Various bug fixes and performance improvements
- Security enhancements and data validation
- Compatibility issues with different WordPress versions
- EDD integration reliability improvements
- MailerLite API error handling
- Database synchronization issues
- User interface responsiveness and accessibility

[Unreleased]: https://github.com/bema-crm/bema-crm/compare/v1.0.0...HEAD