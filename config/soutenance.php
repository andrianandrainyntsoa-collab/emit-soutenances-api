<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Durée de validité du code de réinitialisation (minutes)
    |--------------------------------------------------------------------------
    | Peut être surchargée par la variable d'environnement
    | PASSWORD_RESET_CODE_TTL (minimum 5 minutes).
    */
    'reset_code_ttl' => env('PASSWORD_RESET_CODE_TTL', 30),

];
