<?php
return [
    ['POST', 'api/v1/evaluate', [\App\Http\Controllers\Api\EvaluateController::class, 'evaluate']],
    ['OPTIONS', 'api/v1/evaluate', [\App\Http\Controllers\Api\EvaluateController::class, 'evaluate']],
];
