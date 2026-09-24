<?php

// Copy outside version control as logto_config.local.php, or configure the
// equivalent environment variables on the PHP server.
return [
    'logtoEndpoint' => 'https://192.168.3.29:3001',
    'logtoIssuer' => 'https://192.168.3.29:3001/oidc',
    'logtoAudience' => 'https://192.168.3.29:9443/api',
    'logtoAppId' => 'z13cvqi4yyfn5nby0q4g6',
    // 'logtoCaBundle' was removed in 1.0.5 — the CA now comes from the
    // CURL_CA_BUNDLE environment variable that compose.yaml sets.
];
