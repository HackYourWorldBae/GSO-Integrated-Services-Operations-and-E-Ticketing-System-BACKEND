<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Cross-Origin Resource Sharing (CORS) Configuration
 *
 * Configured for the GSO Vue.js frontend.
 * Dynamically allows local dev ports (`http://localhost:5173`, `http://localhost:4173`)
 * and production origins (`FRONTEND_URL` from `.env`).
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
 */
class Cors extends BaseConfig
{
    public array $default = [
        'allowedOrigins' => [
            'http://localhost:5173',
            'http://localhost:4173',
        ],

        'allowedOriginsPatterns' => [],

        // Must be true when the frontend sends Authorization headers / credentials.
        'supportsCredentials' => true,

        // Allow the Authorization header so Bearer JWT tokens can be sent.
        'allowedHeaders' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With', 'X-CSRF-TOKEN'],

        'exposedHeaders' => [],

        'allowedMethods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

        'maxAge' => 7200,
    ];

    public function __construct()
    {
        parent::__construct();

        // Dynamically add production FRONTEND_URL if set in .env
        $productionUrl = getenv('FRONTEND_URL');
        if (!empty($productionUrl) && !in_array($productionUrl, $this->default['allowedOrigins'], true)) {
            $this->default['allowedOrigins'][] = rtrim($productionUrl, '/');
        }
    }
}
