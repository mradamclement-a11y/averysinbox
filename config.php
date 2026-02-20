<?php
/**
 * Load Groq API key from .env.php (keep key out of version control).
 * Copy .env.php.example to .env.php and set your GROQ_API_KEY.
 */
if (file_exists(__DIR__ . '/.env.php')) {
    require __DIR__ . '/.env.php';
}
if (!defined('GROQ_API_KEY') || GROQ_API_KEY === '') {
    define('GROQ_API_KEY', '');
}
if (!defined('GROQ_MODEL')) {
    define('GROQ_MODEL', 'llama-3.1-70b-versatile');
}
