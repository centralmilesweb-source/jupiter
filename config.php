<?php
/**
 * Copy this file to config.php and fill in your values.
 * Never commit config.php to Git (add it to .gitignore).
 */
return [
    // Fine-grained Personal Access Token from GitHub
    'token'      => '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',

    // Default repository used by the demo page
    'owner'      => 'centralmilesweb-source',
    'repo'       => 'your-repo-name',

    // GitHub requires a User-Agent header; use your app/company name
    'user_agent' => 'MyPHPApp',

    // Cache API responses (seconds) to avoid hitting rate limits; 0 = off
    'cache_ttl'  => 60,
    'cache_dir'  => __DIR__ . '/cache',
];
