<?php

declare(strict_types=1);

use Webconsulting\AgentNexus\Shared\Http\Api\ApiRouter;
use Webconsulting\AgentNexus\Shared\Http\Api\BackendTrafficRecorder;

return [
    'frontend' => [
        // Before site resolution: the API and the well-known documents answer
        // on every host, whatever its site configuration says.
        'webconsulting/agent-nexus/api' => [
            'target' => ApiRouter::class,
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-frontend/eid',
                'typo3/cms-frontend/site',
            ],
        ],
    ],
    'backend' => [
        // After authentication, so an entry knows which backend user made it.
        'webconsulting/agent-nexus/traffic' => [
            'target' => BackendTrafficRecorder::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
            'before' => [
                'typo3/cms-backend/response-headers',
            ],
        ],
    ],
];
