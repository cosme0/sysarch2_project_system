<?php

const SYSTEM_NAME_FULL = 'Automated Medication Dispensing and Pharmacy Management System';
const SYSTEM_NAME_LINE1 = 'Automated Medication Dispensing';
const SYSTEM_NAME_LINE2 = 'and Pharmacy Management System';
const SYSTEM_ACRONYM = 'AMDPMS';
const SYSTEM_TAGLINE = 'Secure login · Inventory · Automated dispensing workflows';

// SMTP settings for sending verification emails via Gmail (configure before use):
// These should be set to real credentials in production and kept out of source control.
// Example: create a local includes/smtp_config.php and define these constants there, then include it in config.

// SMTP_HOST: usually 'smtp.gmail.com'
// SMTP_PORT: 587 for TLS, 465 for SSL
// SMTP_SECURE: 'tls' or 'ssl'
// SMTP_USERNAME: the Gmail address (or app-specific username)
// SMTP_PASSWORD: an app password or SMTP password (DO NOT commit this)
// SMTP_FROM_EMAIL: the from address shown to users
// SMTP_FROM_NAME: friendly name

// If a local smtp_config.php exists, load it to override the placeholders (do NOT commit smtp_config.php)
if (file_exists(__DIR__ . '/smtp_config.php')) {
    require_once __DIR__ . '/smtp_config.php';
}

// Define placeholders here if the local config did not set them:
if (!defined('SMTP_HOST')) define('SMTP_HOST', 'smtp.gmail.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', 587);
if (!defined('SMTP_SECURE')) define('SMTP_SECURE', 'tls');
if (!defined('SMTP_USERNAME')) define('SMTP_USERNAME', 'your-email@gmail.com');
if (!defined('SMTP_PASSWORD')) define('SMTP_PASSWORD', 'your-smtp-password');
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', 'no-reply@example.com');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', SYSTEM_NAME_FULL);

// Token expiry: number of hours a verification code remains valid
if (!defined('RESET_TOKEN_EXPIRY_HOURS')) define('RESET_TOKEN_EXPIRY_HOURS', 2);
